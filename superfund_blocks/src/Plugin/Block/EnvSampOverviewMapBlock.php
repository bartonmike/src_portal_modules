<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an Environmental Samples Overview Map block.
 *
 * Displays a Plotly scattermap (MapLibre/OpenStreetMap, no API key needed)
 * of every sample location (site-wide, not scoped to a single sample).
 *
 * @Block(
 *   id = "superfund_blocks_env_samp_overview_map",
 *   admin_label = @Translation("Environmental Samples Overview Map"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class EnvSampOverviewMapBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

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
    // 1. Fetch every sample location, across all samples.
    // -------------------------------------------------------------------------
    $sql = "SELECT DISTINCT
        LocationName AS location,
        projectName  AS project_name,
        LocationLat AS lat,
        LocationLon AS `long`
      FROM view_samples
      WHERE LocationLat IS NOT NULL";

    $rows = $this->database->query($sql)->fetchAll();

    if (empty($rows)) {
      return ['#markup' => ''];
    }

    $lats  = [];
    $lons  = [];
    $texts = [];

    foreach ($rows as $row) {
      $location     = htmlspecialchars($row->location ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $project_name = htmlspecialchars($row->project_name ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

      $lats[]  = (float) $row->lat;
      $lons[]  = (float) $row->long;
      $texts[] = $project_name !== '' ? "<b>{$location}</b><br>{$project_name}" : "<b>{$location}</b>";
    }

    $chart_id = 'chart-env-samp-overview-map';

    // -------------------------------------------------------------------------
    // 2. Build the render array.
    //    - Plotly CDN loaded via html_head (no defer — causes race conditions).
    //    - Map data passed through drupalSettings (the Drupal-safe way).
    //    - Map init JS lives in an inline script that waits for
    //      DOMContentLoaded.
    // -------------------------------------------------------------------------
    $descriptor = "<div class='env-samp-overview-map-plot element-descriptor'>"
      . "<strong>Sample Locations:</strong> The OSU-PNNL SRC (Oregon State University - "
      . "Pacific Northwest National Laboratory Superfund Research Center) is based in the "
      . "Pacific Northwest, but measurements are captured throughout the United States and "
      . "overseas. To look at a specific sample, search and select using the table below. "
      . "Click the underlined sample name in the first column to open a sample page."
      . "</div>";

    $html = "<div id='{$chart_id}' style='width:100%;height:400px;'></div>"
      . $descriptor;

    // Inline init script — no defer, wraps itself in DOMContentLoaded so it
    // runs safely whether Plotly CDN has finished loading or not.
    $js = <<<JS
(function init() {
  // Poll until Plotly, drupalSettings, and the map container are ready.
  if (typeof Plotly === 'undefined' || typeof drupalSettings === 'undefined' || !document.getElementById('{$chart_id}')) {
    setTimeout(init, 50);
    return;
  }

  var chartDiv = document.getElementById('{$chart_id}');
  var settings = drupalSettings.superfundBlocks.envSampOverviewMap['{$chart_id}'];

  // Plotly's built-in "marker" symbol icon renders as a flat black
  // silhouette and ignores marker.color, so instead we fake a pin look
  // with two overlaid circles: a blue outer dot plus a small white center.
  var outerTrace = {
    type: 'scattermap',
    lat: settings.lats,
    lon: settings.lons,
    text: settings.texts,
    hovertemplate: '%{text}<extra></extra>',
    mode: 'markers',
    marker: { size: 16, color: '#3388ff' },
  };

  var centerTrace = {
    type: 'scattermap',
    lat: settings.lats,
    lon: settings.lons,
    mode: 'markers',
    hoverinfo: 'skip',
    marker: { size: 6, color: '#ffffff' },
  };

  var layout = {
    map: {
      style: 'open-street-map',
      center: { lat: 44.08, lon: -103.23 },
      zoom: 3,
    },
    margin: { t: 0, b: 0, l: 0, r: 0 },
  };

  var config = {
    responsive: true,
    displayModeBar: true,
    modeBarButtonsToRemove: ['pan2d', 'select2d', 'resetScale2d', 'lasso2d', 'zoomOut2d', 'toImage'],
  };

  function renderMap() {
    Plotly.newPlot(chartDiv, [outerTrace, centerTrace], layout, config);
  }

  if (chartDiv.offsetWidth !== 0) {
    renderMap();
  } else {
    var observer = new ResizeObserver(function (entries) {
      for (var entry of entries) {
        if (entry.contentRect.width !== 0) {
          observer.disconnect();
          renderMap();
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
        // Pass map data safely via drupalSettings — no inline JSON blobs.
        'drupalSettings' => [
          'superfundBlocks' => [
            'envSampOverviewMap' => [
              $chart_id => [
                'lats'  => $lats,
                'lons'  => $lons,
                'texts' => $texts,
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
            'plotly_cdn_env_samp_overview_map',
          ],
          // Inline init script — no defer, polls for Plotly readiness.
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'script',
              '#value' => $js,
            ],
            'superfund_env_samp_overview_map_init',
          ],
        ],
      ],
      '#cache' => [
        'contexts' => [],
        'max-age'  => 0,
      ],
    ];
  }

}
