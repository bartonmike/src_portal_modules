<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Chemical Overview Endpoints chart block (Highcharts variant).
 *
 * Displays a Highcharts horizontal bar chart of how many chemicals have
 * zebrafish XY-coordinate data for each endpoint (site-wide, not scoped to
 * a single chemical). Same query and data as ChemOverviewEndpointsChartBlock,
 * rendered with Highcharts instead of Plotly.
 *
 * @Block(
 *   id = "superfund_blocks_chem_overview_endpoints_chart_highcharts",
 *   admin_label = @Translation("Chemical Overview Endpoints Chart (Highcharts)"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class ChemOverviewEndpointsChartHighchartsBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

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

    $chart_id = 'chart-chem-overview-endpoints-highcharts';

    // -------------------------------------------------------------------------
    // 2. Build the render array.
    //    - Highcharts CDN + exporting/export-data/accessibility modules loaded
    //      via html_head (no defer — causes race conditions).
    //    - Chart data passed through drupalSettings (the Drupal-safe way).
    //    - Chart init JS lives in an inline script that polls for readiness.
    // -------------------------------------------------------------------------
    $descriptor = "<div class='chem-overview-endpoints-highcharts element-descriptor'>"
      . "<strong>Zebrafish Endpoints:</strong> Counts of the total number of measurements "
      . "captured from assays of zebrafish exposure to chemicals. To look at a specific "
      . "chemical, search and select using the table below. Click the underlined chemical "
      . "name in the first column to open a chemical page."
      . "</div>";

    $html = "<div id='{$chart_id}' class='highcharts-light' style='width:100%;height:400px;'></div>"
      . $descriptor;

    // Inline init script — no defer, polls until Highcharts, drupalSettings,
    // and the chart container are all ready.
    $js = <<<JS
(function init() {
  if (typeof Highcharts === 'undefined' || typeof drupalSettings === 'undefined' || !document.getElementById('{$chart_id}')) {
    setTimeout(init, 50);
    return;
  }

  var chartDiv = document.getElementById('{$chart_id}');
  var settings = drupalSettings.superfundBlocks.chemOverviewEndpointsHighcharts['{$chart_id}'];

  var chartOptions = {
    chart: {
      type: 'bar',
    },
    title: { text: '' },
    subtitle: { text: '' },
    xAxis: {
      categories: settings.categories,
      title: { text: null },
    },
    yAxis: {
      min: 0,
      title: { text: 'Count' },
    },
    tooltip: {
      pointFormat: '<b>{point.y}</b>',
    },
    legend: {
      enabled: false,
    },
    colors: settings.palette,
    exporting: {
      filename: 'chem-overview-endpoints',
      csv: {
        columnHeaderFormatter: function (item, key) {
          return key === 'y' ? 'Count' : 'Endpoint';
        },
      },
    },
    plotOptions: {
      bar: {
        colorByPoint: true,
      },
    },
    series: [
      {
        name: 'Chemicals',
        data: settings.values,
      },
    ],
  };

  function renderChart() {
    Highcharts.chart(chartDiv, chartOptions);
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
            'chemOverviewEndpointsHighcharts' => [
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
          // Highcharts core — no defer so it's available before init runs.
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://code.highcharts.com/highcharts.js'],
            ],
            'highcharts_cdn_chem_overview_endpoints',
          ],
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://code.highcharts.com/modules/exporting.js'],
            ],
            'highcharts_exporting_cdn_chem_overview_endpoints',
          ],
          // export-data adds the "Download CSV/XLS" items to the export menu.
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://code.highcharts.com/modules/export-data.js'],
            ],
            'highcharts_export_data_cdn_chem_overview_endpoints',
          ],
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://code.highcharts.com/modules/accessibility.js'],
            ],
            'highcharts_accessibility_cdn_chem_overview_endpoints',
          ],
          // Inline init script — no defer, polls for Highcharts readiness.
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'script',
              '#value' => $js,
            ],
            'superfund_chem_overview_endpoints_highcharts_init',
          ],
        ],
      ],
      '#cache' => [
        'contexts' => [],
      ],
    ];
  }

}
