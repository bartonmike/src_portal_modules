<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a Chemical Human Gene Expression chart block.
 *
 * Displays a Plotly grouped bar chart of differentially expressed gene
 * counts by concentration, one series per human cell line (HEPG2, MCF10A,
 * ADIPO), for a chemical identified by the ?id= query parameter.
 *
 * @Block(
 *   id = "superfund_blocks_chem_gene_exp_chart",
 *   admin_label = @Translation("Chemical Human Gene Expression Chart"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class ChemGeneExpChartBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

  /**
   * The cell-line projects included, and the fixed color used per series.
   */
  protected const PROJECT_COLORS = [
    'HEPG2'  => '#1f77b4',
    'MCF10A' => '#ff7f0e',
    'ADIPO'  => '#FF33A1',
  ];

  /**
   * Fallback color for a project not in self::PROJECT_COLORS.
   */
  protected const DEFAULT_COLOR = '#999999';

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
    // 1. Get and validate ?id= query parameter.
    // -------------------------------------------------------------------------
    $request      = $this->requestStack->getCurrentRequest();
    $raw_id       = $request->query->get('id', '');
    $sanitized_id = preg_replace('/[^0-9\-]/', '', $raw_id);

    if (!preg_match('/^\d+(-\d+)?$/', $sanitized_id)) {
      return ['#markup' => ''];
    }

    $projects = array_keys(self::PROJECT_COLORS);

    // -------------------------------------------------------------------------
    // 2. Confirm there's gene-expression data for this chemical.
    // -------------------------------------------------------------------------
    $count = (int) $this->database
      ->query(
        "SELECT COUNT(Chemical_ID) AS count
         FROM view_sigGeneStats
         WHERE Project IN (:projects[]) AND Chemical_ID = :chem_id",
        [':projects[]' => $projects, ':chem_id' => $sanitized_id]
      )
      ->fetchField();

    if ($count <= 0) {
      return ['#markup' => ''];
    }

    // -------------------------------------------------------------------------
    // 3. Fetch the gene-expression rows, ordered by concentration: numeric-
    //    leading values first (sorted numerically by their leading number),
    //    then any remaining values alphabetically.
    // -------------------------------------------------------------------------
    $sql = "SELECT Chemical_ID, nGenes, Conc, Project
      FROM view_sigGeneStats
      WHERE Project IN (:projects[]) AND Chemical_ID = :chem_id
      ORDER BY
        CASE WHEN Conc REGEXP '^[0-9]' THEN 0 ELSE 1 END,
        CAST(REGEXP_SUBSTR(Conc, '^[0-9]+(\\.[0-9]+)?') AS DECIMAL(10,4)),
        Conc";

    $rows = $this->database
      ->query($sql, [':projects[]' => $projects, ':chem_id' => $sanitized_id])
      ->fetchAll();

    if (empty($rows)) {
      return ['#markup' => ''];
    }

    // -------------------------------------------------------------------------
    // 4. Build x-axis categories (concentrations, in query order) and one
    //    sparse series per project, aligned to those categories.
    // -------------------------------------------------------------------------
    $categories    = [];
    $conc_index    = [];
    $series_by_project = [];
    $csv_rows      = [];

    foreach ($rows as $row) {
      $conc    = $row->Conc;
      $project = $row->Project;
      $n_genes = (float) $row->nGenes;

      if (!isset($conc_index[$conc])) {
        $conc_index[$conc] = count($categories);
        $categories[] = $conc;

        // Extend every existing series with a null so it stays aligned.
        foreach ($series_by_project as &$series) {
          $series['data'][] = NULL;
        }
        unset($series);
      }

      if (!isset($series_by_project[$project])) {
        $series_by_project[$project] = [
          'name'  => $project,
          'color' => self::PROJECT_COLORS[$project] ?? self::DEFAULT_COLOR,
          'data'  => array_fill(0, count($categories), NULL),
        ];
      }

      $series_by_project[$project]['data'][$conc_index[$conc]] = $n_genes;

      $csv_rows[] = [
        'project' => $project,
        'conc'    => $conc,
        'n_genes' => $n_genes,
      ];
    }

    $series = array_values($series_by_project);

    // Use a unique chart ID per chemical so multiple blocks don't collide.
    $chart_id = 'chart-chem-gene-exp-' . $sanitized_id;

    // -------------------------------------------------------------------------
    // 5. Build the render array.
    //    - Plotly CDN loaded via html_head (no defer — causes race conditions).
    //    - Chart data passed through drupalSettings (the Drupal-safe way).
    //    - Chart init JS lives in an inline script that waits for
    //      DOMContentLoaded.
    // -------------------------------------------------------------------------
    $html = "<div id='{$chart_id}' style='width:100%;height:400px;'></div>";

    // Inline init script — no defer, wraps itself in DOMContentLoaded so it
    // runs safely whether Plotly CDN has finished loading or not.
    $js = <<<JS
(function init() {
  // Poll until Plotly, drupalSettings, and the chart container are ready.
  if (typeof Plotly === 'undefined' || typeof drupalSettings === 'undefined' || !document.getElementById('{$chart_id}')) {
    setTimeout(init, 50);
    return;
  }

  console.log('[chemGeneExp] init ready-gate passed for {$chart_id}');

  var chartDiv    = document.getElementById('{$chart_id}');
  var settings    = drupalSettings.superfundBlocks.chemGeneExp['{$chart_id}'];
  var categories  = settings.categories;
  var series      = settings.series;
  var csvRows     = settings.csvRows;

  // ---- CSV download -----------------------------------------------------
  function downloadCsv() {
    var headers = ['Cell Line', 'Concentration', 'Differentially Expressed Genes'];
    var lines   = [headers.join(',')];
    csvRows.forEach(function (row) {
      lines.push([
        '"' + String(row.project).replace(/"/g, '""') + '"',
        '"' + String(row.conc).replace(/"/g, '""') + '"',
        row.n_genes,
      ].join(','));
    });
    var blob = new Blob([lines.join('\\n')], { type: 'text/csv' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href     = url;
    a.download = 'chem-gene-exp-{$sanitized_id}.csv';
    a.click();
    URL.revokeObjectURL(url);
  }

  var downloadIcon = {
    width: 512,
    height: 512,
    path: 'M288 32c0-17.7-14.3-32-32-32s-32 14.3-32 32V274.7l-73.4-73.4c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3l128 128c12.5 12.5 32.8 12.5 45.3 0l128-128c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0L288 274.7V32zM64 352c-35.3 0-64 28.7-64 64v32c0 35.3 28.7 64 64 64H448c35.3 0 64-28.7 64-64V416c0-35.3-28.7-64-64-64H346.5l-45.3 45.3c-25 25-65.5 25-90.5 0L165.5 352H64z',
  };

  var config = {
    responsive: true,
    displayModeBar: true,
    modeBarButtonsToAdd: [
      {
        name: 'download-data',
        title: 'Download CSV',
        icon: downloadIcon,
        click: function () {
          downloadCsv();
        },
      },
    ],
    modeBarButtonsToRemove: ['pan2d', 'select2d', 'resetScale2d', 'lasso2d', 'zoomOut2d'],
  };

  var traces = series.map(function (s) {
    return {
      type: 'bar',
      name: s.name,
      x: categories,
      y: s.data,
      marker: { color: s.color },
    };
  });

  var layout = {
    title: { text: 'Genes Differentially Expressed and Conditions' },
    barmode: 'group',
    hovermode: 'x unified',
    xaxis: {
      type: 'category',
      categoryorder: 'array',
      categoryarray: categories,
    },
    yaxis: {
      title: { text: 'Count' },
      rangemode: 'tozero',
    },
    dragmode: 'zoom',
    annotations: [{
      text: '(drag zooming is allowed)',
      showarrow: false,
      xref: 'paper', yref: 'paper',
      x: 0.5, y: 1.06,
      xanchor: 'center',
      font: { size: 12 },
    }],
  };

  function renderChart() {
    console.log('[chemGeneExp] renderChart() called for {$chart_id}', traces, layout, config);
    Plotly.newPlot(chartDiv, traces, layout, config)
      .then(function () {
        console.log('[chemGeneExp] Plotly.newPlot resolved for {$chart_id}');
      })
      .catch(function (err) {
        console.error('[chemGeneExp] Plotly.newPlot rejected for {$chart_id}', err);
      });
  }

  console.log('[chemGeneExp] init reached render-decision for {$chart_id}, offsetWidth =', chartDiv.offsetWidth);

  if (chartDiv.offsetWidth !== 0) {
    renderChart();
  } else {
    var observer = new ResizeObserver(function (entries) {
      for (var entry of entries) {
        console.log('[chemGeneExp] ResizeObserver fired for {$chart_id}, width =', entry.contentRect.width);
        if (entry.contentRect.width !== 0) {
          observer.disconnect();
          renderChart();
          break;
        }
      }
    });
    observer.observe(chartDiv);
  }
})();
JS;

    return [
      '#type'     => 'markup',
      '#markup'   => $html,
      '#attached' => [
        // Pass chart data safely via drupalSettings — no inline JSON blobs.
        'drupalSettings' => [
          'superfundBlocks' => [
            'chemGeneExp' => [
              $chart_id => [
                'categories' => $categories,
                'series'     => $series,
                'csvRows'    => $csv_rows,
              ],
            ],
          ],
        ],
        'html_head' => [
          // Plotly CDN — no defer so it's available before the init script runs.
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://cdn.plot.ly/plotly-2.35.2.min.js'],
            ],
            'plotly_cdn',
          ],
          // Inline init script — no defer, polls for Plotly readiness.
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
        'contexts' => ['url.query_args:id'],
      ],
    ];
  }

}
