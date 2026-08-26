<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Chemical Overview Class chart block (Highcharts variant).
 *
 * Displays a Highcharts pie chart of how many chemicals fall into each
 * chemical_class (site-wide, not scoped to a single chemical). Same query
 * and data as ChemOverviewClassChartBlock, rendered with Highcharts
 * instead of Plotly.
 *
 * @Block(
 *   id = "superfund_blocks_chem_overview_class_chart_highcharts",
 *   admin_label = @Translation("Chemical Overview Class Chart (Highcharts)"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class ChemOverviewClassChartHighchartsBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

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
    // 1. Fetch the chemical count per class, across all chemicals.
    // -------------------------------------------------------------------------
    $sql = "SELECT
        CASE WHEN chemical_class IS NULL THEN 'Unclassified' ELSE chemical_class END AS class,
        COUNT(*) AS count
      FROM view_chemicals
      GROUP BY class
      ORDER BY count DESC";

    $rows = $this->database->query($sql)->fetchAll();

    if (empty($rows)) {
      return ['#markup' => ''];
    }

    $data     = [];
    $csv_rows = [];

    foreach ($rows as $row) {
      $data[] = [
        'name' => $row->class,
        'y'    => (int) $row->count,
      ];
      $csv_rows[] = [
        'class' => $row->class,
        'count' => (int) $row->count,
      ];
    }

    $chart_id = 'chart-chem-overview-class-highcharts';

    // -------------------------------------------------------------------------
    // 2. Build the render array.
    //    - Highcharts CDN + exporting/export-data/accessibility modules loaded
    //      via html_head (no defer — causes race conditions).
    //    - Chart data passed through drupalSettings (the Drupal-safe way).
    //    - Chart init JS lives in an inline script that polls for readiness.
    // -------------------------------------------------------------------------
    $descriptor = "<div class='chem-overview-class-highcharts element-descriptor'>"
      . "The count of studied chemicals per category. "
      . "PFAS = per- and polyfluoroalkyl substances, colloquially known as \"forever chemicals\". "
      . "PAH = polycyclic aromatic hydrocarbons, formed during incomplete burning. "
      . "PCB = polychlorinated biphenyls, a group of synthetic and toxic chlorinated hydrocarbon chemicals."
      . "</div>";

    $html = "<h2>Chemicals Measured</h2>"
      . "<div id='{$chart_id}' class='highcharts-light' style='width:100%;height:400px;'></div>"
      . $descriptor;

    // Inline init script — no defer, polls until Highcharts, drupalSettings,
    // and the chart container are all ready.
    $js = <<<JS
(function init() {
  if (typeof Highcharts === 'undefined' || typeof drupalSettings === 'undefined' || !document.getElementById('{$chart_id}')) {
    setTimeout(init, 50);
    return;
  }

  var chartDiv  = document.getElementById('{$chart_id}');
  var settings  = drupalSettings.superfundBlocks.chemOverviewClassHighcharts['{$chart_id}'];

  var chartOptions = {
    chart: {
      type: 'pie',
    },
    title: { text: '' },
    subtitle: { text: '' },
    accessibility: {
      point: { valueSuffix: '%' },
    },
    tooltip: {
      pointFormat: '<b>{point.y}</b> ({point.percentage:.0f}%)',
    },
    legend: {
      enabled: true,
      align: 'center',
      verticalAlign: 'bottom',
      layout: 'horizontal',
      symbolRadius: 6,
    },
    exporting: {
      filename: 'chem-overview-class',
      csv: {
        columnHeaderFormatter: function (item, key) {
          return key === 'y' ? 'Count' : 'Chemical Class';
        },
      },
    },
    plotOptions: {
      pie: {
        allowPointSelect: true,
        cursor: 'pointer',
        showInLegend: true,
        dataLabels: [
          {
            enabled: true,
            distance: 20,
            format: '{point.name}',
          },
          {
            enabled: true,
            distance: -15,
            format: '{point.y}',
            style: { fontSize: '0.9em', textOutline: 'none' },
          },
        ],
      },
    },
    series: [
      {
        name: 'Chemicals',
        colorByPoint: true,
        data: settings.data,
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
            'chemOverviewClassHighcharts' => [
              $chart_id => [
                'data'    => $data,
                'csvRows' => $csv_rows,
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
            'highcharts_cdn',
          ],
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://code.highcharts.com/modules/exporting.js'],
            ],
            'highcharts_exporting_cdn',
          ],
          // export-data adds the "Download CSV/XLS" items to the export menu.
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://code.highcharts.com/modules/export-data.js'],
            ],
            'highcharts_export_data_cdn',
          ],
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://code.highcharts.com/modules/accessibility.js'],
            ],
            'highcharts_accessibility_cdn',
          ],
          // Inline init script — no defer, polls for Highcharts readiness.
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'script',
              '#value' => $js,
            ],
            'superfund_chem_overview_class_highcharts_init',
          ],
        ],
      ],
      '#cache' => [
        'contexts' => [],
      ],
    ];
  }

}
