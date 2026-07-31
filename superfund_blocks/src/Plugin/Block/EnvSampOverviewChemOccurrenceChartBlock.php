<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an Environmental Samples Overview Chemical Occurrence chart block.
 *
 * Displays a Plotly pie chart of how many sample-chemical detections fall
 * into each chemical_class (site-wide, not scoped to a single sample).
 *
 * @Block(
 *   id = "superfund_blocks_env_samp_overview_chem_occurrence_chart",
 *   admin_label = @Translation("Environmental Samples Overview Chemical Occurrence Chart"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class EnvSampOverviewChemOccurrenceChartBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

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

    $labels    = [];
    $values    = [];
    $csv_rows  = [];

    foreach ($rows as $row) {
      $labels[] = $row->class;
      $values[] = (int) $row->count;
      $csv_rows[] = [
        'class' => $row->class,
        'count' => (int) $row->count,
      ];
    }

    $chart_id = 'chart-env-samp-overview-chem-occurrence';

    // -------------------------------------------------------------------------
    // 2. Build the render array.
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

  var chartDiv = document.getElementById('{$chart_id}');
  var settings = drupalSettings.superfundBlocks.envSampOverviewChemOccurrence['{$chart_id}'];
  var labels   = settings.labels;
  var values   = settings.values;
  var csvRows  = settings.csvRows;

  // ---- CSV download -----------------------------------------------------
  function downloadCsv() {
    var headers = ['Chemical Class', 'Count'];
    var lines   = [headers.join(',')];
    csvRows.forEach(function (row) {
      lines.push([
        '"' + String(row.class).replace(/"/g, '""') + '"',
        row.count,
      ].join(','));
    });
    var blob = new Blob([lines.join('\\n')], { type: 'text/csv' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href     = url;
    a.download = 'env-samp-overview-chem-occurrence.csv';
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

  var trace = {
    type: 'pie',
    labels: labels,
    values: values,
    textinfo: 'value',
    textposition: 'outside',
    hovertemplate: '<b>%{label}: %{value}</b> (%{percent})<extra></extra>',
  };

  var layout = {
    showlegend: true,
    legend: {
      orientation: 'h',
      x: 0.5,
      xanchor: 'center',
      y: -0.1,
    },
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
            'envSampOverviewChemOccurrence' => [
              $chart_id => [
                'labels'  => $labels,
                'values'  => $values,
                'csvRows' => $csv_rows,
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
            'plotly_cdn_env_samp_overview_chem_occurrence',
          ],
          // Inline init script — no defer, polls for Plotly readiness.
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'script',
              '#value' => $js,
            ],
            'superfund_env_samp_overview_chem_occurrence_init',
          ],
        ],
      ],
      '#cache' => [
        'contexts' => [],
      ],
    ];
  }

}
