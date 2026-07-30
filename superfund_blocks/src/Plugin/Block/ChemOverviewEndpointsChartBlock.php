<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Chemical Overview Endpoints chart block.
 *
 * Displays a Plotly horizontal bar chart of how many chemicals have
 * zebrafish XY-coordinate data for each endpoint (site-wide, not scoped to
 * a single chemical).
 *
 * @Block(
 *   id = "superfund_blocks_chem_overview_endpoints_chart",
 *   admin_label = @Translation("Chemical Overview Endpoints Chart"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class ChemOverviewEndpointsChartBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

  /**
   * Bar color palette, cycled through per category.
   */
  protected const PALETTE = [
    '#FF5733', '#33FF57', '#3357FF', '#FF33A1', '#A1FF33',
    '#33A1FF', '#FF8C33', '#8C33FF', '#33FF8C', '#FF33FF',
    '#FFD733', '#33FFD7', '#D733FF', '#33FF33', '#FF3333',
  ];

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    Connection $database,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->database = $database;
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // -------------------------------------------------------------------------
    // 1. Fetch the number of chemicals with XY-coordinate data per endpoint,
    //    across all chemicals.
    // -------------------------------------------------------------------------
    $sql = "SELECT DISTINCT
        c.End_Point_Name AS category,
        (SELECT COUNT(c2.Chemical_ID)
         FROM view_zebrafishChemXYCoords c2
         WHERE c2.End_Point_Name = c.End_Point_Name) AS value
      FROM view_chemical_endpoints c
      WHERE c.End_Point_Name IS NOT NULL";

    $rows = $this->database->query($sql)->fetchAll();

    if (empty($rows)) {
      return ['#markup' => ''];
    }

    $categories = [];
    $values     = [];
    $csv_rows   = [];

    foreach ($rows as $row) {
      $categories[] = $row->category;
      $values[]     = (int) $row->value;
      $csv_rows[] = [
        'endpoint' => $row->category,
        'count'    => (int) $row->value,
      ];
    }

    $chart_id = 'chart-chem-overview-endpoints';

    // -------------------------------------------------------------------------
    // 2. Build the render array.
    //    - Plotly CDN loaded via html_head (no defer — causes race conditions).
    //    - Chart data passed through drupalSettings (the Drupal-safe way).
    //    - Chart init JS lives in an inline script that waits for
    //      DOMContentLoaded.
    // -------------------------------------------------------------------------
    $descriptor = "<div class='chem-overview-endpoints-plot element-descriptor'>"
      . "<strong>Zebrafish Endpoints:</strong> Counts of the total number of measurements "
      . "captured from assays of zebrafish exposure to chemicals. To look at a specific "
      . "chemical, search and select using the table below. Click the underlined chemical "
      . "name in the first column to open a chemical page."
      . "</div>";

    $html = "<div id='{$chart_id}' style='width:100%;height:400px;'></div>"
      . $descriptor;

    // Inline init script — no defer, wraps itself in DOMContentLoaded so it
    // runs safely whether Plotly CDN has finished loading or not.
    $js = <<<JS
(function init() {
  // Poll until Plotly, drupalSettings, and the chart container are ready.
  if (typeof Plotly === 'undefined' || typeof drupalSettings === 'undefined' || !document.getElementById('{$chart_id}')) {
    setTimeout(init, 50);
    return;
  }

  var chartDiv    = document.getElementById('{$chart_id}');
  var settings    = drupalSettings.superfundBlocks.chemOverviewEndpoints['{$chart_id}'];
  var categories  = settings.categories;
  var values      = settings.values;
  var palette     = settings.palette;
  var csvRows     = settings.csvRows;

  // ---- CSV download -----------------------------------------------------
  function downloadCsv() {
    var headers = ['Endpoint', 'Count'];
    var lines   = [headers.join(',')];
    csvRows.forEach(function (row) {
      lines.push([
        '"' + String(row.endpoint).replace(/"/g, '""') + '"',
        row.count,
      ].join(','));
    });
    var blob = new Blob([lines.join('\\n')], { type: 'text/csv' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href     = url;
    a.download = 'chem-overview-endpoints.csv';
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

  var barColors = categories.map(function (name, i) {
    return palette[i % palette.length];
  });

  var trace = {
    type: 'bar',
    orientation: 'h',
    y: categories,
    x: values,
    marker: { color: barColors },
  };

  var layout = {
    showlegend: false,
    xaxis: {
      title: { text: 'Count' },
      rangemode: 'tozero',
    },
    yaxis: {
      type: 'category',
      automargin: true,
    },
    margin: { l: 200 },
  };

  function renderChart() {
    Plotly.newPlot(chartDiv, [trace], layout, config);
  }

  if (chartDiv.offsetWidth !== 0) {
    renderChart();
  } else {
    var observer = new ResizeObserver(function (entries) {
      for (var entry of entries) {
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
            'chemOverviewEndpoints' => [
              $chart_id => [
                'categories' => $categories,
                'values'     => $values,
                'palette'    => self::PALETTE,
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
            'plotly_cdn_chem_overview_endpoints',
          ],
          // Inline init script — no defer, polls for Plotly readiness.
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'script',
              '#value' => $js,
            ],
            'superfund_chem_overview_endpoints_init',
          ],
        ],
      ],
      '#cache' => [
        'contexts' => [],
      ],
    ];
  }

}
