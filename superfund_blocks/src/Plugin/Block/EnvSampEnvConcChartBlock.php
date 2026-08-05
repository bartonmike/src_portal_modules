<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides an Environmental Sample Environment Concentration chart block.
 *
 * Displays a Plotly bar chart of PSD-Water/PSD-Air environment
 * concentrations for every chemical detected in a sample identified by the
 * ?id= query parameter. Bars and x-axis labels link through to the
 * chemical detail page.
 *
 * @Block(
 *   id = "superfund_blocks_env_samp_env_conc_chart",
 *   admin_label = @Translation("Environmental Sample Environment Concentration Chart"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class EnvSampEnvConcChartBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

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
    // 2. Confirm the sample exists.
    // -------------------------------------------------------------------------
    $count = (int) $this->database
      ->query(
        'SELECT COUNT(`Sample_ID`) AS count FROM view_samples WHERE Sample_ID = :sample_id',
        [':sample_id' => $sanitized_id]
      )
      ->fetchField();

    if ($count <= 0) {
      return ['#markup' => ''];
    }

    // -------------------------------------------------------------------------
    // 3. Fetch concentration data.
    // -------------------------------------------------------------------------
    $sql = "SELECT DISTINCT
        vc.Chemical_ID,
        vc.PREFERRED_NAME,
        vstc.environment_concentration,
        vstc.environment_concentration_unit
      FROM view_samplesToChemicals vstc
        JOIN view_chemicals vc ON vc.Chemical_ID = vstc.Chemical_ID
        JOIN view_samples   vs ON vs.Sample_ID   = vstc.Sample_ID
      WHERE
        (vs.sample_matrix = 'PSD-Water' OR vs.sample_matrix = 'PSD-Air')
        AND vstc.environment_concentration != 0
        AND vstc.environment_concentration IS NOT NULL
        AND vstc.Sample_ID = :sample_id
      ORDER BY vstc.environment_concentration DESC";

    $rows = $this->database
      ->query($sql, [':sample_id' => $sanitized_id])
      ->fetchAll();

    if (empty($rows)) {
      return ['#markup' => ''];
    }

    // -------------------------------------------------------------------------
    // 4. Build data arrays for Plotly.
    // -------------------------------------------------------------------------
    $chemical_ids   = [];
    $chemical_names = [];
    $values         = [];
    $unit           = '';

    $csv_rows = [];
    foreach ($rows as $row) {
      $chemical_ids[]   = $row->Chemical_ID;
      $chemical_names[] = $row->PREFERRED_NAME;
      $values[]         = (float) $row->environment_concentration;
      $unit             = $row->environment_concentration_unit;
      // Full row data for CSV download.
      $csv_rows[] = [
        'chemical_name' => $row->PREFERRED_NAME,
        'chemical_id'   => $row->Chemical_ID,
        'concentration' => $row->environment_concentration,
        'unit'          => $row->environment_concentration_unit,
      ];
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

    // Use a unique chart ID per sample so multiple blocks don't collide.
    $chart_id = 'chart-env-samp-env-conc-' . $sanitized_id;

    // -------------------------------------------------------------------------
    // 5. Build the render array.
    //    - Plotly CDN loaded via html_head (no defer — causes race conditions).
    //    - Chart data passed through drupalSettings (the Drupal-safe way).
    //    - Chart init JS lives in an inline script that waits for
    //      DOMContentLoaded.
    // -------------------------------------------------------------------------
    $descriptor = "<div class='env-meas-plot element-descriptor'>"
      . "<strong>Air/Water Measurements:</strong> Our passive samplers were used to "
      . "collect and measure chemicals present in air/water."
      . "</div>";

    $html = "<div id='{$chart_id}' style='width:100%;min-height:400px;'></div>"
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

  var chartDiv       = document.getElementById('{$chart_id}');
  var settings       = drupalSettings.superfundBlocks.envSampEnvConc['{$chart_id}'];
  var chemicalNames  = settings.chemicalNames;
  var chemicalIds    = settings.chemicalIds;
  var values         = settings.values;
  var unit           = settings.unit;
  var title          = settings.title;
  var csvRows        = settings.csvRows;

  // Navigate to a chemical's detail page.
  function goToChemical(chemId) {
    window.location.href = '/chemicals/view?id=' + encodeURIComponent(chemId);
  }

  // ---- CSV download ---------------------------------------------------------
  function downloadCsv() {
    var headers = ['Chemical Name', 'Chemical ID', 'Concentration', 'Unit'];
    var lines   = [headers.join(',')];
    csvRows.forEach(function (row) {
      lines.push([
        '"' + String(row.chemical_name).replace(/"/g, '""') + '"',
        '"' + String(row.chemical_id).replace(/"/g, '""') + '"',
        row.concentration,
        '"' + String(row.unit).replace(/"/g, '""') + '"',
      ].join(','));
    });
    var blob = new Blob([lines.join('\\n')], { type: 'text/csv' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href     = url;
    a.download = 'env-samp-env-conc-{$sanitized_id}.csv';
    a.click();
    URL.revokeObjectURL(url);
  }

  // ---- Chart ---------------------------------------------------------------
  var tickText = chemicalNames.map(function (name, i) {
    var chemUrl = '/chemicals/view?id=' + encodeURIComponent(chemicalIds[i]);
    var finalChemUrl = `<a href="\${chemUrl}">\${name}</a>`;
    return finalChemUrl;
  });

  var trace = {
    type: 'bar',
    x: chemicalIds,
    y: values,
    text: chemicalNames,
    textposition: 'none',
    hovertemplate: '%{text}<br>%{y} ' + unit + '<extra></extra>',
  };

  var layout = {
    title: { text: title },
    xaxis: {
      type: 'category',
      tickangle: -45,
      tickfont: { size: 8 },
      tickmode: 'array',
      tickvals: chemicalIds,
      ticktext: tickText,
    },
    yaxis: {
      title: { text: 'Concentration ' + unit },
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
    modeBarButtonsToRemove: ['pan2d', 'select2d', 'resetScale2d', 'lasso2d'/*, 'zoomOut2d'*/],
  };

  function renderChart() {
    Plotly.newPlot(chartDiv, [trace], layout, config);

    chartDiv.on('plotly_click', function (data) {
      goToChemical(chemicalIds[data.points[0].pointIndex]);
    });
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
            'envSampEnvConc' => [
              $chart_id => [
                'chemicalNames' => $chemical_names,
                'chemicalIds'   => $chemical_ids,
                'values'        => $values,
                'unit'          => $unit,
                'title'         => $title,
                'csvRows'       => $csv_rows,
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
            'superfund_env_samp_env_conc_init_' . $sanitized_id,
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['url.query_args:id'],
      ],
    ];
  }

}
