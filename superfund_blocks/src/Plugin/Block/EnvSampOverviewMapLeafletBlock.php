<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an Environmental Samples Overview Map block (Leaflet variant).
 *
 * Displays a Leaflet/OpenStreetMap map of every sample location (site-wide,
 * not scoped to a single sample). Same query and data as
 * EnvSampOverviewMapBlock, rendered with Leaflet instead of Plotly.
 *
 * @Block(
 *   id = "superfund_blocks_env_samp_overview_map_leaflet",
 *   admin_label = @Translation("Environmental Samples Overview Map (Leaflet)"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class EnvSampOverviewMapLeafletBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

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
        CASE
          WHEN LocationAlternateDescription = 'NULL' THEN ''
          WHEN LocationAlternateDescription = 'NA' THEN ''
          ELSE LocationAlternateDescription
        END AS description,
        LocationLat AS lat,
        LocationLon AS `long`
      FROM view_samples
      WHERE LocationLat IS NOT NULL";

    $rows = $this->database->query($sql)->fetchAll();

    if (empty($rows)) {
      return ['#markup' => ''];
    }

    $locations = [];

    foreach ($rows as $row) {
      $location    = htmlspecialchars($row->location ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $description = htmlspecialchars($row->description ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

      $locations[] = [
        'lat'   => (float) $row->lat,
        'lon'   => (float) $row->long,
        'popup' => $description !== '' ? "<strong>{$location}</strong><br>{$description}" : "<strong>{$location}</strong>",
        'label' => $location,
      ];
    }

    $chart_id = 'chart-env-samp-overview-map-leaflet';

    // -------------------------------------------------------------------------
    // 2. Build the render array.
    //    - Leaflet CDN loaded via html_head (no defer — causes race conditions).
    //    - Map data passed through drupalSettings (the Drupal-safe way).
    //    - Map init JS lives in an inline script that polls for readiness.
    // -------------------------------------------------------------------------
    $descriptor = "<div class='env-samp-overview-map-leaflet element-descriptor'>"
      . "<strong>Sample Locations:</strong> The OSU-PNNL SRC (Oregon State University - "
      . "Pacific Northwest National Laboratory Superfund Research Center) is based in the "
      . "Pacific Northwest, but measurements are captured throughout the United States and "
      . "overseas. To look at a specific sample, search and select using the table below. "
      . "Click the underlined sample name in the first column to open a sample page."
      . "</div>";

    $html = "<div id='{$chart_id}' style='width:100%;height:400px;'></div>"
      . $descriptor;

    // Inline init script — no defer, polls until Leaflet, drupalSettings,
    // and the map container are all ready.
    $js = <<<JS
(function init() {
  // Check L.tileLayer, not just typeof L — something else on the page may
  // stake a claim on the global "L" before or while Leaflet is still
  // loading, and we'd otherwise latch onto that instead of the real thing.
  if (typeof L === 'undefined' || typeof L.tileLayer !== 'function' || typeof drupalSettings === 'undefined' || !document.getElementById('{$chart_id}')) {
    setTimeout(init, 50);
    return;
  }

  // Snapshot Leaflet now, the instant it's confirmed real — if something
  // else reassigns window.L later (e.g. a theme script loading after ours),
  // renderMap() below still has the correct reference.
  var Leaflet  = L;
  var mapDiv   = document.getElementById('{$chart_id}');
  var settings = drupalSettings.superfundBlocks.envSampOverviewMapLeaflet['{$chart_id}'];

  function renderMap() {
    var osm = Leaflet.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '© OpenStreetMap',
    });

    var markers = settings.locations.map(function (loc) {
      return Leaflet.marker([loc.lat, loc.lon])
        .bindPopup(loc.popup)
        .bindTooltip(loc.label, { permanent: false });
    });

    var samplingLocations = Leaflet.layerGroup(markers);

    var map = Leaflet.map(mapDiv, {
      center: [44.08, -103.23],
      zoom: 3,
      layers: [osm, samplingLocations],
    });

    samplingLocations.eachLayer(function (layer) {
      if (layer._icon) {
        layer._icon.setAttribute('aria-label', layer.getPopup().getContent());
        layer._icon.setAttribute('role', 'img');
      }
    });
  }

  if (mapDiv.offsetWidth !== 0) {
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
    observer.observe(mapDiv);
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
            'envSampOverviewMapLeaflet' => [
              $chart_id => [
                'locations' => $locations,
              ],
            ],
          ],
        ],
        'html_head' => [
          // Leaflet CSS.
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'link',
              '#attributes' => [
                'rel'         => 'stylesheet',
                'href'        => 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
                'integrity'   => 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=',
                'crossorigin' => '',
              ],
            ],
            'leaflet_css_cdn_env_samp_overview_map',
          ],
          // Leaflet JS — no defer so it's available before the init script runs.
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => [
                'src'         => 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
                'integrity'   => 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=',
                'crossorigin' => '',
              ],
            ],
            'leaflet_js_cdn_env_samp_overview_map',
          ],
          // Inline init script — no defer, polls for Leaflet readiness.
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'script',
              '#value' => $js,
            ],
            'superfund_env_samp_overview_map_leaflet_init',
          ],
        ],
      ],
      '#cache' => [
        'contexts' => [],
      ],
    ];
  }

}
