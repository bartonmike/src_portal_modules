<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides an Environment Air Concentration chart block.
 *
 * Displays a Highcharts bar chart of PSD-Water/PSD-Air environment
 * concentrations for a chemical identified by the ?id= query parameter.
 * Bars and x-axis labels link through to the sample detail page.
 *
 * @Block(
 *   id = "superfund_blocks_env_air_conc_chart",
 *   admin_label = @Translation("Environment Air Concentration Chart"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class EnvAirConcChartBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

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

    // -------------------------------------------------------------------------
    // 2. Confirm the chemical exists.
    // -------------------------------------------------------------------------
    $count = (int) $this->database
      ->query(
        'SELECT COUNT(`Chemical_ID`) AS count FROM view_chemicals WHERE Chemical_ID = :chem_id',
        [':chem_id' => $sanitized_id]
      )
      ->fetchField();

    if ($count <= 0) {
      return ['#markup' => ''];
    }

    // -------------------------------------------------------------------------
    // 3. Fetch concentration data.
    // -------------------------------------------------------------------------
    $sql = "SELECT DISTINCT
        CASE
          WHEN vs.SampleName   IS NOT NULL THEN vs.SampleName
          WHEN vs.SampleNumber IS NOT NULL THEN vs.SampleNumber
          ELSE vs.Sample_ID
        END AS SampleName,
        vs.Sample_ID,
        vstc.environment_concentration,
        vstc.environment_concentration_unit
      FROM view_samplesToChemicals vstc
        JOIN view_chemicals vc ON vc.Chemical_ID = vstc.Chemical_ID
        JOIN view_samples   vs ON vs.Sample_ID   = vstc.Sample_ID
      WHERE
        (vs.sample_matrix = 'PSD-Water' OR vs.sample_matrix = 'PSD-Air')
        AND vstc.environment_concentration != 0
        AND vstc.environment_concentration IS NOT NULL
        AND vstc.Chemical_ID = :chem_id
        AND (vstc.environment_concentration_qualifier != 'U'
             OR vstc.environment_concentration_qualifier IS NULL)
        AND vstc.environment_concentration_unit IN ('ng/m3', 'ng/m^3', 'ng/m³')
      ORDER BY vstc.environment_concentration DESC";

    $rows = $this->database
      ->query($sql, [':chem_id' => $sanitized_id])
      ->fetchAll();

    if (empty($rows)) {
      return ['#markup' => ''];
    }

    // -------------------------------------------------------------------------
    // 4. Build data arrays for Highcharts.
    // -------------------------------------------------------------------------
    $sample_names = [];
    $sample_ids   = [];
    $values       = [];
    $unit         = '';

    foreach ($rows as $row) {
      $sample_names[] = $row->SampleName;
      $sample_ids[]   = $row->Sample_ID;
      $values[]       = (float) $row->environment_concentration;
      $unit           = $row->environment_concentration_unit;
    }

    switch ($unit) {
      case 'ng/m3':
      case 'ng/m^3':
      case 'ng/m³':
        $title = 'Air Concentration (ng/m³)';
        $unit  = 'ng/m³';
        break;

      case 'ng/L':
        $title = 'Water Concentration (ng/L)';
        break;

      default:
        $title = 'Concentration (' . $unit . ')';
        break;
    }

    // Use a unique chart ID per chemical so multiple blocks don't collide.
    $chart_id = 'chart-env-air-conc-' . $sanitized_id;

    // -------------------------------------------------------------------------
    // 5. Build the render array.
    //    - Highcharts CDN loaded via html_head (no defer — causes race
    //      conditions).
    //    - Chart data passed through drupalSettings (the Drupal-safe way).
    //    - Chart init JS lives in an inline script that waits for
    //      DOMContentLoaded.
    // -------------------------------------------------------------------------
    $descriptor = "<div class='air-meas-plot element-descriptor'>"
      . "<strong>Air Measurements:</strong> Our passive samplers were used to "
      . "collect and measure chemicals present in air."
      . "</div>";

    $html = "<div id='{$chart_id}' style='width:100%;min-height:400px;'></div>"
      . $descriptor;

    // Inline init script — no defer, wraps itself in DOMContentLoaded so it
    // runs safely whether Highcharts CDN has finished loading or not.
    $js = <<<JS
(function init() {
  // Poll until both Highcharts and the chart container are ready.
  if (typeof Highcharts === 'undefined' || !document.getElementById('{$chart_id}')) {
    setTimeout(init, 50);
    return;
  }

  var chartDiv    = document.getElementById('{$chart_id}');
  var settings    = drupalSettings.superfundBlocks.envAirConc['{$chart_id}'];
  var sampleNames = settings.sampleNames;
  var sampleIds   = settings.sampleIds;
  var values      = settings.values;
  var unit        = settings.unit;
  var title       = settings.title;

  // Navigate to a sample's detail page.
  function goToSample(sampId) {
    window.location.href = '/samples/view?id=' + encodeURIComponent(sampId);
  }

  var chartOptions = {
    chart: {
      type: 'column',
      zoomType: 'x',
    },
    title: {
      text: title,
    },
    subtitle: {
      text: '(drag zooming is allowed)',
    },
    xAxis: {
      categories: sampleNames,
      labels: {
        step: 1,
        useHTML: true,
        rotation: -45,
        style: { fontSize: '8px' },
        formatter: function () {
          var sampId = sampleIds[this.pos];
          return '<a href="/samples/view?id=' + encodeURIComponent(sampId) + '">' + this.value + '</a>';
        },
      },
    },
    yAxis: {
      min: 0,
      title: { text: 'Concentration ' + unit },
    },
    series: [
      {
        name: 'Concentration',
        showInLegend: false,
        data: values,
        point: {
          events: {
            click: function () {
              goToSample(sampleIds[this.index]);
            },
          },
        },
      },
    ],
  };

  Highcharts.chart(chartDiv, chartOptions);
})();
JS;

    return [
      '#type'     => 'markup',
      '#markup'   => $html,
      '#attached' => [
        // Pass chart data safely via drupalSettings — no inline JSON blobs.
        'drupalSettings' => [
          'superfundBlocks' => [
            'envAirConc' => [
              $chart_id => [
                'sampleNames' => $sample_names,
                'sampleIds'   => $sample_ids,
                'values'      => $values,
                'unit'        => $unit,
                'title'       => $title,
              ],
            ],
          ],
        ],
        'html_head' => [
          // Highcharts CDN — no defer so it's available before the init
          // script runs.
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://code.highcharts.com/highcharts.js'],
            ],
            'highcharts_cdn',
          ],
          // Inline init script — no defer, polls for Highcharts readiness.
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'script',
              '#value' => $js,
            ],
            'superfund_env_air_conc_init_' . $sanitized_id,
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['url.query_args:id'],
      ],
    ];
  }

}
