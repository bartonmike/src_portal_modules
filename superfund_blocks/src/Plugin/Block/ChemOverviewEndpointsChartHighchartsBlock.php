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
   * Bar color per endpoint group (End_Point_Type), cycled if there are more
   * groups than colors.
   */
  protected const GROUP_COLORS = [
    '#1f77b4', '#ff7f0e', '#2ca02c', '#d62728', '#9467bd', '#8c564b',
  ];

  /**
   * Group label for endpoints with no End_Point_Type.
   */
  protected const UNGROUPED_LABEL = 'Other';

  /**
   * Display label per End_Point_Type (lowercase key), in the order the groups
   * should appear. Any type not listed here follows these, alphabetically,
   * under its own name.
   */
  protected const GROUP_LABELS = [
    'morphological' => 'Zebrafish: Morphological',
    'behavioral'    => 'Zebrafish: Behavioral',
    'cellular'      => 'Lung: Cellular',
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
        c.End_Point_Type AS category_group,
        (SELECT COUNT(c2.Chemical_ID)
         FROM view_zebrafishChemXYCoords c2
         WHERE c2.End_Point_Name = c.End_Point_Name) AS value
      FROM view_chemical_endpoints c
      WHERE c.End_Point_Name IS NOT NULL";

    $rows = $this->database->query($sql)->fetchAll();

    if (empty($rows)) {
      return ['#markup' => ''];
    }

    // Bucket endpoints by End_Point_Type, order the groups per GROUP_LABELS
    // (unlisted types after, alphabetically), and order endpoints
    // alphabetically within each group (matching the order the chemical
    // page's endpoint table uses).
    $grouped = [];
    foreach ($rows as $row) {
      $group = trim((string) ($row->category_group ?? ''));
      if ($group === '') {
        $group = self::UNGROUPED_LABEL;
      }
      $grouped[$group][] = [
        'endpoint' => (string) $row->category,
        'count'    => (int) $row->value,
      ];
    }
    $group_rank = array_flip(array_keys(self::GROUP_LABELS));
    uksort($grouped, function ($a, $b) use ($group_rank) {
      $rank_a = $group_rank[strtolower((string) $a)] ?? count($group_rank);
      $rank_b = $group_rank[strtolower((string) $b)] ?? count($group_rank);
      return ($rank_a <=> $rank_b) ?: strcasecmp((string) $a, (string) $b);
    });

    $categories = [];
    $groups     = [];
    $csv_rows   = [];
    $color_idx  = 0;

    foreach ($grouped as $group_name => $items) {
      usort($items, fn($a, $b) => strcasecmp($a['endpoint'], $b['endpoint']));

      $group_label = self::GROUP_LABELS[strtolower((string) $group_name)] ?? (string) $group_name;

      $group_categories = [];
      $group_values     = [];
      foreach ($items as $item) {
        $categories[]       = $item['endpoint'];
        $group_categories[] = $item['endpoint'];
        $group_values[]     = $item['count'];
        $csv_rows[] = [
          'endpoint' => $item['endpoint'],
          'group'    => $group_label,
          'count'    => $item['count'],
        ];
      }

      $groups[] = [
        'name'       => $group_label,
        'color'      => self::GROUP_COLORS[$color_idx % count(self::GROUP_COLORS)],
        'categories' => $group_categories,
        'values'     => $group_values,
      ];
      $color_idx++;
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
      . "<strong>Endpoints:</strong> Counts of the total number of measurements "
      . "captured from assays of model exposure to chemicals. To look at a specific "
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

  // One series per endpoint group, each aligned to the full category list
  // (null where a category belongs to a different group), so every group
  // gets its own color and legend entry.
  var series = settings.groups.map(function (g) {
    return {
      name: g.name,
      color: g.color,
      data: settings.categories.map(function (cat) {
        var idx = g.categories.indexOf(cat);
        return idx === -1 ? null : g.values[idx];
      }),
    };
  });

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
      enabled: true,
      title: { text: 'Endpoint Type' },
    },
    exporting: {
      filename: 'chem-overview-endpoints',
      csv: {
        columnHeaderFormatter: function (item, key) {
          if (item.isXAxis) {
            return 'Endpoint';
          }
          return item.name;
        },
      },
    },
    plotOptions: {
      // Each category only has a value in one series, so stacking keeps the
      // bars full-width instead of reserving a thin slot per group.
      bar: {
        stacking: 'normal',
      },
    },
    series: series,
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
                'groups'     => $groups,
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
