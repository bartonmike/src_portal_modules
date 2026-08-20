<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\superfund_blocks\ChemicalIdResolverTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a Chemical Human Gene Expression Table block.
 *
 * Static summary table (not a graph selector) showing, for a chemical
 * identified by the ?id= or ?cas= query parameter, how many conditions were
 * tested in each of the three human cell lines (HEPG2, MCF10A, ADIPO).
 *
 * @Block(
 *   id = "superfund_blocks_chem_gene_exp_table",
 *   admin_label = @Translation("Chemical Human Gene Expression Table"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class ChemGeneExpTableBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

  use ChemicalIdResolverTrait;

  /**
   * The cell-line projects shown, in display order.
   */
  protected const PROJECTS = ['HEPG2', 'MCF10A', 'ADIPO'];

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    Connection $database,
    RequestStack $request_stack,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->database = $database;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // -------------------------------------------------------------------------
    // 1. Resolve the chemical from ?cas= or ?id=.
    // -------------------------------------------------------------------------
    $request      = $this->requestStack->getCurrentRequest();
    $sanitized_id = $this->resolveChemicalId($request);

    if ($sanitized_id === NULL) {
      return ['#markup' => ''];
    }

    // -------------------------------------------------------------------------
    // 2. Confirm there's gene-expression data for this chemical.
    // -------------------------------------------------------------------------
    $count = (int) $this->database
      ->query(
        "SELECT COUNT(Chemical_ID) AS count
         FROM view_sigGeneStats
         WHERE Project IN (:projects[]) AND Chemical_ID = :chem_id",
        [':projects[]' => self::PROJECTS, ':chem_id' => $sanitized_id]
      )
      ->fetchField();

    if ($count <= 0) {
      return ['#markup' => ''];
    }

    // -------------------------------------------------------------------------
    // 3. Fetch the per-cell-line condition counts (LEFT JOIN so every cell
    //    line lists even with zero conditions tested for this chemical).
    // -------------------------------------------------------------------------
    $sql = "SELECT
        p.Project,
        COUNT(v.Project) AS project_count,
        MAX(v.Link) AS link
      FROM (
        SELECT 'HEPG2' AS Project, 1 AS sort_order
        UNION ALL SELECT 'MCF10A', 2
        UNION ALL SELECT 'ADIPO', 3
      ) p
      LEFT JOIN view_sigGeneStats v
        ON v.Project = p.Project AND v.Chemical_ID = :chem_id
      GROUP BY p.Project, p.sort_order
      ORDER BY p.sort_order";

    $rows = $this->database
      ->query($sql, [':chem_id' => $sanitized_id])
      ->fetchAll();

    if (empty($rows)) {
      return ['#markup' => ''];
    }

    // -------------------------------------------------------------------------
    // 4. Build the table.
    // -------------------------------------------------------------------------
    $table_id = 'chem-gene-exp-table-' . $sanitized_id;

    $body_rows = [];
    foreach ($rows as $row) {
      $project = htmlspecialchars($row->Project, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $count   = (int) $row->project_count;

      $project_cell = !empty($row->link)
        ? '<a href="' . htmlspecialchars($row->link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $project . '</a>'
        : $project;

      $body_rows[] = "<tr><td>{$project_cell}</td><td>{$count}</td></tr>";
    }

    $descriptor = "<div class='gene-exp-table element-descriptor'>"
      . '<strong>Human Gene Expression Measurements:</strong> Chemicals with measurements '
      . 'on human cell lines via the exposome project are summarized in the table above. '
      . 'On the left is the number of conditions measured in each cell line, and on the '
      . 'right is the number of differentially expressed genes in each cell line at '
      . 'various concentrations.'
      . '</div>';

    $html = "<table id='{$table_id}' class='display'>"
      . '<thead><tr><th>Human Cell Lines</th><th>Conditions Tested</th></tr></thead>'
      . '<tbody>' . implode('', $body_rows) . '</tbody>'
      . '</table><br />'
      . $descriptor;

    // -------------------------------------------------------------------------
    // 5. Basic DataTables enhancement (pagination only — this table isn't a
    //    graph selector, so no click handling is needed).
    // -------------------------------------------------------------------------
    $js = <<<JS
(function init() {
  if (typeof DataTable === 'undefined' || !document.getElementById('{$table_id}')) {
    setTimeout(init, 50);
    return;
  }

  new DataTable('#{$table_id}', {
    pageLength: 5,
  });
})();
JS;

    return [
      '#type'     => 'markup',
      '#markup'   => $html,
      '#attached' => [
        'html_head' => [
          // jQuery + DataTables — not loaded sitewide, so this block brings
          // its own copies.
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://code.jquery.com/jquery-3.7.1.js'],
            ],
            'jquery_cdn_chem_gene_exp_table',
          ],
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'link',
              '#attributes' => [
                'rel'  => 'stylesheet',
                'href' => 'https://cdn.datatables.net/2.3.2/css/dataTables.dataTables.css',
              ],
            ],
            'datatables_css_cdn_chem_gene_exp_table',
          ],
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://cdn.datatables.net/2.3.2/js/dataTables.js'],
            ],
            'datatables_js_cdn_chem_gene_exp_table',
          ],
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'script',
              '#value' => $js,
            ],
            'superfund_chem_gene_exp_init_' . $sanitized_id,
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['url.query_args:id', 'url.query_args:cas'],
      ],
    ];
  }

}
