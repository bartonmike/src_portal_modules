<?php

namespace Drupal\superfund_import\Commands;

use Drush\Commands\DrushCommands;
use Drupal\superfund_import\Service\SuperfundImportService;

/**
 * Drush commands for Superfund Import.
 *
 * Usage:
 *   drush superfund:import
 *
 * Linux cron example (runs daily at 2 AM):
 *   0 2 * * * cd /var/www/html && vendor/bin/drush superfund:import >> /var/log/superfund_import.log 2>&1
 */
class SuperfundImportCommands extends DrushCommands {

  public function __construct(
    protected SuperfundImportService $importer,
  ) {
    parent::__construct();
  }

  /**
   * Run the Superfund CSV import immediately.
   *
   * @command superfund:import
   * @aliases sf-import
   * @usage drush superfund:import
   *   Downloads the configured ZIP and imports all CSVs into superfund_ tables.
   */
  public function import(): void {
    $this->output()->writeln('<info>Starting Superfund import…</info>');

    $result = $this->importer->run();

    if ($result['success']) {
      \Drupal::state()->set('superfund_import.last_run', \Drupal::time()->getRequestTime());
      $this->output()->writeln(sprintf(
        '<info>✔ Done. Tables: %d | Rows inserted: %d</info>',
        $result['tables'],
        $result['rows']
      ));
    }
    else {
      $this->output()->writeln('<error>✘ Import failed: ' . $result['error'] . '</error>');
      throw new \RuntimeException($result['error']);
    }
  }

}
