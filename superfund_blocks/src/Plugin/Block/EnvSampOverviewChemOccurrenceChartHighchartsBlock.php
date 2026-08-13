<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an Environmental Samples Overview Chemical Occurrence chart block
 * (Highcharts variant).
 *
 * Displays a Highcharts pie chart of how many sample-chemical detections
 * fall into each chemical_class (site-wide, not scoped to a single sample).
 * Same query and data as EnvSampOverviewChemOccurrenceChartBlock, rendered
 * with Highcharts instead of Plotly.
 *
 * @Block(
 *   id = "superfund_blocks_env_samp_overview_chem_occurrence_chart_highcharts",
 *   admin_label = @Translation("Environmental Samples Overview Chemical Occurrence Chart (Highcharts)"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class EnvSampOverviewChemOccurrenceChartHighchartsBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

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
    // 1. Fetch the chemical-occurrence count per class, across all samples.
    // -------------------------------------------------------------------------
    $sql = "SELECT distinct
        CASE WHEN vc.chemical_class IS NULL THEN 'Unclassified' ELSE vc.chemical_class END AS class,
        COUNT(*) AS count
      FROM view_samplesToChemicals vstc
      JOIN view_chemicals vc ON vc.Chemical_ID = vstc.Chemical_ID
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

    $chart_id = 'chart-env-samp-overview-chem-occurrence-highcharts';

    // -------------------------------------------------------------------------
    // 2. Build the render array.
    //    - Highcharts CDN + exporting/export-data/accessibility modules loaded
    //      via html_head (no defer — causes race conditions).
    //    - Chart data passed through drupalSettings (the Drupal-safe way).
    //    - Chart init JS lives in an inline script that polls for readiness.
    // -------------------------------------------------------------------------
    $html = "<div id='{$chart_id}' style='width:100%;height:400px;'></div>";

    // Inline init script — no defer, polls until Highcharts, drupalSettings,
    // and the chart container are all ready.
    $js = <<<JS
(function init() {
  if (typeof Highcharts === 'undefined' || typeof drupalSettings === 'undefined' || !document.getElementById('{$chart_id}')) {
    setTimeout(init, 50);
    return;
  }

  var chartDiv = document.getElementById('{$chart_id}');
  var settings = drupalSettings.superfundBlocks.envSampOverviewChemOccurrenceHighcharts['{$chart_id}'];

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
      enabled: false,
    },
    exporting: {
      filename: 'env-samp-overview-chem-occurrence',
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
        showInLegend: false,
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
        name: 'Registrations',
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
            'envSampOverviewChemOccurrenceHighcharts' => [
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
            'highcharts_cdn_env_samp_overview_chem_occurrence',
          ],
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://code.highcharts.com/modules/exporting.js'],
            ],
            'highcharts_exporting_cdn_env_samp_overview_chem_occurrence',
          ],
          // export-data adds the "Download CSV/XLS" items to the export menu.
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://code.highcharts.com/modules/export-data.js'],
            ],
            'highcharts_export_data_cdn_env_samp_overview_chem_occurrence',
          ],
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://code.highcharts.com/modules/accessibility.js'],
            ],
            'highcharts_accessibility_cdn_env_samp_overview_chem_occurrence',
          ],
          // Inline init script — no defer, polls for Highcharts readiness.
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'script',
              '#value' => $js,
            ],
            'superfund_env_samp_overview_chem_occurrence_highcharts_init',
          ],
        ],
      ],
      '#cache' => [
        'contexts' => [],
      ],
    ];
  }

}
