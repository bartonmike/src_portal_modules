<?php

namespace Drupal\superfund_import\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Psr\Log\LoggerInterface;

/**
 * Service that downloads a ZIP, extracts CSVs, and imports to superfund_ tables.
 */
class SuperfundImportService {

  /**
   * Table prefix applied to all CSV filenames.
   */
  const TABLE_PREFIX = 'superfund_';

  /**
   * Temporary directory used during extraction.
   */
  const TMP_DIR = 'temporary://superfund_import';

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected $loggerFactory,
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * Returns the PSR logger for this module.
   */
  protected function logger(): LoggerInterface {
    return $this->loggerFactory->get('superfund_import');
  }

  /**
   * Main entry point. Downloads the ZIP, processes every CSV inside it.
   *
   * @return array{success: bool, tables: int, rows: int, error: string}
   */
  public function run(): array {
    $config = $this->configFactory->get('superfund_import.settings');
    $url    = $config->get('zip_url');

    if (empty($url)) {
      return $this->failure('No ZIP URL configured. Visit /admin/config/superfund-import.');
    }

    // ── 1. Download ──────────────────────────────────────────────────────────
    $zipPath = $this->downloadZip($url);
    if ($zipPath === NULL) {
      return $this->failure("Failed to download ZIP from: $url");
    }

    // ── 2. Extract ───────────────────────────────────────────────────────────
    $extractDir = $this->extractZip($zipPath);
    @unlink($zipPath);
    if ($extractDir === NULL) {
      return $this->failure("Failed to extract ZIP file.");
    }

    // ── 3. Process each CSV ──────────────────────────────────────────────────
    $csvFiles   = $this->findCsvFiles($extractDir);
    $totalRows  = 0;
    $tableCount = 0;

    foreach ($csvFiles as $csvPath) {
      $table = $this->csvPathToTableName($csvPath);

      if (!$this->tableExists($table)) {
        $this->logger()->warning('Skipping @file — table @table does not exist.', [
          '@file'  => basename($csvPath),
          '@table' => $table,
        ]);
        continue;
      }

      try {
        $rows = $this->importCsv($csvPath, $table);
        $totalRows += $rows;
        $tableCount++;
        $this->logger()->info('Imported @rows rows into @table.', [
          '@rows'  => $rows,
          '@table' => $table,
        ]);
      }
      catch (\Exception $e) {
        $this->logger()->error('Error importing @file into @table: @msg', [
          '@file'  => basename($csvPath),
          '@table' => $table,
          '@msg'   => $e->getMessage(),
        ]);
        // Continue processing remaining CSVs.
      }
    }

    // ── 4. Cleanup ───────────────────────────────────────────────────────────
    $this->fileSystem->deleteRecursive($extractDir);

    return ['success' => TRUE, 'tables' => $tableCount, 'rows' => $totalRows, 'error' => ''];
  }

  // ──────────────────────────────────────────────────────────────────────────
  // Download
  // ──────────────────────────────────────────────────────────────────────────

  /**
   * Downloads the remote ZIP to a local temp file.
   *
   * @return string|null  Local filesystem path on success, NULL on failure.
   */
  protected function downloadZip(string $url): ?string {
    $tmpPath = $this->fileSystem->getTempDirectory() . '/superfund_import_' . uniqid() . '.zip';

    $context = stream_context_create([
      'http' => [
        'timeout'    => 120,
        'user_agent' => 'Drupal Superfund Import Module',
      ],
      'ssl'  => [
        'verify_peer'      => TRUE,
        'verify_peer_name' => TRUE,
      ],
    ]);

    $data = @file_get_contents($url, FALSE, $context);
    if ($data === FALSE) {
      $this->logger()->error('Could not fetch URL: @url', ['@url' => $url]);
      return NULL;
    }

    if (file_put_contents($tmpPath, $data) === FALSE) {
      $this->logger()->error('Could not write temp file: @path', ['@path' => $tmpPath]);
      return NULL;
    }

    return $tmpPath;
  }

  // ──────────────────────────────────────────────────────────────────────────
  // Extract
  // ──────────────────────────────────────────────────────────────────────────

  /**
   * Extracts the ZIP into a temporary directory.
   *
   * @return string|null  Path to extracted directory, or NULL on failure.
   */
  protected function extractZip(string $zipPath): ?string {
    $zip = new \ZipArchive();
    if ($zip->open($zipPath) !== TRUE) {
      $this->logger()->error('ZipArchive could not open: @path', ['@path' => $zipPath]);
      return NULL;
    }

    $extractDir = $this->fileSystem->getTempDirectory() . '/superfund_extract_' . uniqid();
    if (!mkdir($extractDir, 0700, TRUE)) {
      $this->logger()->error('Could not create extraction directory: @dir', ['@dir' => $extractDir]);
      $zip->close();
      return NULL;
    }

    $zip->extractTo($extractDir);
    $zip->close();

    return $extractDir;
  }

  // ──────────────────────────────────────────────────────────────────────────
  // File discovery
  // ──────────────────────────────────────────────────────────────────────────

  /**
   * Recursively finds all *.csv files under $dir.
   *
   * @return string[]
   */
  protected function findCsvFiles(string $dir): array {
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );

    $csvFiles = [];
    foreach ($iterator as $file) {
      if ($file->isFile() && strtolower($file->getExtension()) === 'csv') {
        $csvFiles[] = $file->getPathname();
      }
    }

    return $csvFiles;
  }

  // ──────────────────────────────────────────────────────────────────────────
  // Name mapping
  // ──────────────────────────────────────────────────────────────────────────

  /**
   * Converts a CSV file path to its target database table name.
   *
   * Example: /tmp/extract/orders.csv  →  superfund_orders
   */
  protected function csvPathToTableName(string $csvPath): string {
    $basename  = basename($csvPath, '.csv');        // "orders"
    $sanitized = preg_replace('/[^a-z0-9_]/', '_', strtolower($basename));
    return self::TABLE_PREFIX . $sanitized;
  }

  // ──────────────────────────────────────────────────────────────────────────
  // Import
  // ──────────────────────────────────────────────────────────────────────────

  /**
   * Flushes $table and inserts all rows from $csvPath.
   *
   * Strategy: TRUNCATE then batch INSERT for performance.
   *
   * @return int  Number of rows inserted.
   * @throws \Exception On any database or file-parsing error.
   */
  protected function importCsv(string $csvPath, string $table): int {
    $handle = @fopen($csvPath, 'r');
    if ($handle === FALSE) {
      throw new \RuntimeException("Cannot open CSV file: $csvPath");
    }

    // Read header row.
    $headers = fgetcsv($handle);
    if ($headers === FALSE || empty($headers)) {
      fclose($handle);
      throw new \RuntimeException("CSV file has no header row: $csvPath");
    }

    // Sanitize column names to prevent SQL injection via header values.
    $columns = array_map(
      fn($h) => preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($h))),
      $headers
    );

    // Validate columns exist in the target table.
    $this->validateColumns($table, $columns);

    // Wrap the flush + insert in a transaction so a failure leaves the
    // table either fully loaded or untouched (not half-truncated).
    $txn      = $this->database->startTransaction();
    $rowCount = 0;

    try {
      // Flush the table.
      $this->database->truncate($table)->execute();

      // Batch rows for efficiency (1 000 rows per INSERT).
      $batch     = [];
      $batchSize = 1000;

      while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) !== count($columns)) {
          // Pad or trim mismatched rows rather than hard-failing.
          $row = array_slice(array_pad($row, count($columns), NULL), 0, count($columns));
        }

        $batch[] = array_combine($columns, $row);

        if (count($batch) >= $batchSize) {
          $rowCount += $this->insertBatch($table, $columns, $batch);
          $batch = [];
        }
      }

      // Flush any remaining rows.
      if (!empty($batch)) {
        $rowCount += $this->insertBatch($table, $columns, $batch);
      }
    }
    catch (\Exception $e) {
      $txn->rollBack();
      fclose($handle);
      throw $e;
    }

    fclose($handle);
    // $txn commits automatically when it goes out of scope.
    unset($txn);

    return $rowCount;
  }

  /**
   * Inserts a batch of rows using Drupal's query builder.
   *
   * @param string   $table
   * @param string[] $columns
   * @param array[]  $rows
   *
   * @return int  Rows inserted.
   */
  protected function insertBatch(string $table, array $columns, array $rows): int {
    $query = $this->database->insert($table)->fields($columns);
    foreach ($rows as $row) {
      $query->values(array_values($row));
    }
    $query->execute();
    return count($rows);
  }

  // ──────────────────────────────────────────────────────────────────────────
  // Helpers
  // ──────────────────────────────────────────────────────────────────────────

  /**
   * Checks that a table exists in the database.
   */
  protected function tableExists(string $table): bool {
    return $this->database->schema()->tableExists($table);
  }

  /**
   * Validates that every CSV column exists in the target table.
   *
   * Logs a warning for unknown columns and removes them so the insert
   * doesn't fail — safe to continue with the known columns only.
   *
   * @param string   $table
   * @param string[] &$columns  Passed by reference; unknown columns are removed.
   */
  protected function validateColumns(string $table, array &$columns): void {
    // Use information_schema to get the real column names.
    $existing = $this->database->query(
      "SELECT COLUMN_NAME FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table",
      [':table' => $table]
    )->fetchCol();

    $existing = array_map('strtolower', $existing);
    $unknown  = array_diff($columns, $existing);

    if (!empty($unknown)) {
      $this->logger()->warning(
        'Table @table: ignoring unknown CSV columns: @cols',
        ['@table' => $table, '@cols' => implode(', ', $unknown)]
      );
      // Keep only columns that exist in the DB.
      $columns = array_values(array_intersect($columns, $existing));
    }
  }

  /**
   * Builds a failure result array.
   */
  protected function failure(string $message): array {
    return ['success' => FALSE, 'tables' => 0, 'rows' => 0, 'error' => $message];
  }

}
