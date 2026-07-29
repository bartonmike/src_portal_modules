<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a Chemical Zebrafish BMD Dose-Response chart block.
 *
 * Displays a Plotly concentration-response curve (fitted line + dose-response
 * scatter with error bars) for a chemical identified by the ?id= query
 * parameter. A chemical can have multiple zebrafish endpoints; all of their
 * datasets are loaded up front and exposed on
 * window.superfundBlocks.chemZfBmdResp['<chart_id>'](endpointKey) so a
 * companion endpoint-selector block can switch which one is displayed
 * without any further server round-trip.
 *
 * @Block(
 *   id = "superfund_blocks_chem_zf_bmd_resp_chart",
 *   admin_label = @Translation("Chemical Zebrafish BMD Dose-Response Chart"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class ChemZfBmdRespChartBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

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
   * Builds a URL/JS-safe lowercase key for an endpoint name.
   */
  protected function endpointKey(string $endpoint_name): string {
    return strtolower(preg_replace('/\s+/', '_', trim($endpoint_name)));
  }

  /**
   * Builds the small FontAwesome QC-status icon markup for a DataQC_Flag.
   */
  protected function dataQcIconHtml(?string $flag): string {
    switch (strtolower(trim((string) $flag))) {
      case 'good':
        return ' <i class="far fa-circle-check qc-icon qc-good" title="Good data quality" aria-label="Good data quality"></i>';

      case 'moderate':
        return ' <i class="fas fa-circle-minus qc-icon qc-moderate" title="Moderate data quality" aria-label="Moderate data quality"></i>';

      case 'poor':
        return ' <i class="fas fa-circle-exclamation qc-icon qc-poor" title="Poor data quality" aria-label="Poor data quality"></i>';

      default:
        return '';
    }
  }

  /**
   * Builds the endpoint name with superscript reference links appended.
   *
   * @param string $endpoint_name
   *   The raw endpoint name.
   * @param string|null $endpoint_link
   *   Pipe-separated ("|") list of reference URLs, or NULL/'NA'.
   */
  protected function endpointNameHtml(string $endpoint_name, ?string $endpoint_link): string {
    $html = htmlspecialchars($endpoint_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    if (empty($endpoint_link) || $endpoint_link === 'NA') {
      return $html;
    }

    $links = preg_split('/\s+\|\s+/', trim($endpoint_link));
    $sup_links = [];

    foreach ($links as $index => $link) {
      $link = trim($link);
      if ($link === '' || strtoupper($link) === 'NA') {
        continue;
      }
      $safe_link = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $sup_links[] = '<sup><a target="_blank" rel="noopener noreferrer" href="' . $safe_link . '">[' . $index . ']</a></sup>';
    }

    return $html . implode('', $sup_links);
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
    // 2. Confirm there is at least one endpoint with a complete BMD analysis.
    // -------------------------------------------------------------------------
    $count = (int) $this->database
      ->query(
        "SELECT COUNT(zcxy.X_vals) AS count
         FROM view_zebrafishChemXYCoords zcxy
         INNER JOIN view_zebrafishChemDoseResponse zcdr
           ON (zcxy.Chemical_ID = zcdr.Chemical_ID AND zcxy.End_Point_Name = zcdr.End_Point_Name)
         INNER JOIN view_zebrafishChemBMDs zcbmd
           ON (zcxy.Chemical_ID = zcbmd.Chemical_ID AND zcxy.End_Point_Name = zcbmd.End_Point_Name)
         WHERE zcbmd.BMD10 IS NOT NULL AND zcxy.Chemical_ID = :chem_id",
        [':chem_id' => $sanitized_id]
      )
      ->fetchField();

    if ($count <= 0) {
      return ['#markup' => ''];
    }

    // -------------------------------------------------------------------------
    // 3. Fetch the list of endpoints valid for this chemical.
    // -------------------------------------------------------------------------
    $endpoint_rows = $this->database
      ->query(
        "SELECT DISTINCT zcdr.End_Point_Name AS end_point_name
         FROM view_zebrafishChemDoseResponse zcdr
         WHERE zcdr.End_Point_Name IS NOT NULL
           AND zcdr.End_Point_Name IN (SELECT cen.End_Point_Name FROM view_chemical_endpoint_names cen)
           AND zcdr.Chemical_ID = :chem_id",
        [':chem_id' => $sanitized_id]
      )
      ->fetchAll();

    if (empty($endpoint_rows)) {
      return ['#markup' => ''];
    }

    $endpoint_names = array_map(fn($row) => $row->end_point_name, $endpoint_rows);

    // -------------------------------------------------------------------------
    // 4. Fetch the fitted-curve line points for all endpoints.
    // -------------------------------------------------------------------------
    $line_rows = $this->database
      ->query(
        "SELECT DISTINCT zcxy.End_Point_Name AS end_point_name, zcxy.X_vals AS x_val, zcxy.Y_vals AS y_val
         FROM view_zebrafishChemXYCoords zcxy
         WHERE zcxy.Chemical_ID = :chem_id AND zcxy.End_Point_Name IN (:endpoint_names[])
         ORDER BY zcxy.End_Point_Name, zcxy.X_vals",
        [':chem_id' => $sanitized_id, ':endpoint_names[]' => $endpoint_names]
      )
      ->fetchAll();

    $lines_by_endpoint = [];
    foreach ($line_rows as $row) {
      $lines_by_endpoint[$row->end_point_name][] = [
        'x' => (float) $row->x_val,
        'y' => (float) $row->y_val * 100,
      ];
    }

    // -------------------------------------------------------------------------
    // 5. Fetch the dose/response points (feeds both the scatter markers and
    //    their error bars) for all endpoints.
    // -------------------------------------------------------------------------
    $dose_rows = $this->database
      ->query(
        "SELECT zcdr.End_Point_Name AS end_point_name, zcdr.Dose AS dose, zcdr.Response AS response,
                zcdr.CI_Lo AS ci_lo, zcdr.CI_Hi AS ci_hi
         FROM view_zebrafishChemDoseResponse zcdr
         WHERE zcdr.Chemical_ID = :chem_id AND zcdr.End_Point_Name IN (:endpoint_names[])",
        [':chem_id' => $sanitized_id, ':endpoint_names[]' => $endpoint_names]
      )
      ->fetchAll();

    $dose_response_by_endpoint = [];
    foreach ($dose_rows as $row) {
      $dose_response_by_endpoint[$row->end_point_name][] = [
        'dose'        => (float) $row->dose,
        'response'    => (float) $row->response * 100,
        'errorPlus'   => (float) $row->ci_hi * 100,
        'errorMinus'  => (float) $row->ci_lo * 100,
        'ciLo'        => (float) $row->ci_lo,
        'ciHi'        => (float) $row->ci_hi,
      ];
    }

    // -------------------------------------------------------------------------
    // 6. Fetch the per-endpoint summary (AUC/BMD10/BMD50/model/QC/references).
    // -------------------------------------------------------------------------
    $summary_rows = $this->database
      ->query(
        "SELECT DISTINCT zcdr.End_Point_Name AS end_point_name,
                zcbmd.AUC_Norm AS auc_norm, zcbmd.BMD10 AS bmd10, zcbmd.BMD50 AS bmd50,
                zcbmd.Model AS model, zcbmd.DataQC_Flag AS dataqc_flag,
                zed.endPointLink AS endpoint_link
         FROM view_zebrafishChemDoseResponse zcdr
         LEFT JOIN view_zebrafishChemBMDs zcbmd
           ON (zcdr.Chemical_ID = zcbmd.Chemical_ID AND zcdr.End_Point_Name = zcbmd.End_Point_Name)
         LEFT JOIN superfund_zebrafish_endpoint_descriptions zed
           ON (zed.End_Point_Name = zcdr.End_Point_Name)
         WHERE zcdr.Chemical_ID = :chem_id AND zcdr.End_Point_Name IN (:endpoint_names[])",
        [':chem_id' => $sanitized_id, ':endpoint_names[]' => $endpoint_names]
      )
      ->fetchAll();

    $summary_by_endpoint = [];
    foreach ($summary_rows as $row) {
      $summary_by_endpoint[$row->end_point_name] = $row;
    }

    // -------------------------------------------------------------------------
    // 7. Assemble the per-endpoint datasets keyed by a JS-safe slug.
    // -------------------------------------------------------------------------
    $endpoints = [];
    $datasets  = [];
    $default_endpoint_key = NULL;

    foreach ($endpoint_names as $endpoint_name) {
      $key     = $this->endpointKey($endpoint_name);
      $summary = $summary_by_endpoint[$endpoint_name] ?? NULL;

      $model    = $summary ? htmlspecialchars((string) $summary->model, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
      $auc_norm = $summary ? (float) $summary->auc_norm : 0.0;
      $bmd10    = $summary ? (float) $summary->bmd10 : 0.0;
      $bmd50    = $summary ? (float) $summary->bmd50 : 0.0;
      $qc_icon  = $summary ? $this->dataQcIconHtml($summary->dataqc_flag) : '';
      $endpoint_name_html = $this->endpointNameHtml($endpoint_name, $summary->endpoint_link ?? NULL);

      $subtitle_html = $endpoint_name_html . ' - model: <b>' . $model . '</b><br /> AUC: '
        . $auc_norm . ', BMD10: ' . $bmd10 . ', BMD50: ' . $bmd50;

      $endpoints[] = [
        'name' => $endpoint_name,
        'key'  => $key,
      ];

      $datasets[$key] = [
        'line'         => $lines_by_endpoint[$endpoint_name] ?? [],
        'doseResponse' => $dose_response_by_endpoint[$endpoint_name] ?? [],
        'qcIconHtml'   => $qc_icon,
        'subtitleHtml' => $subtitle_html,
      ];

      if ($default_endpoint_key === NULL || strtolower($endpoint_name) === 'any effect') {
        $default_endpoint_key = $key;
      }
    }

    // Use a unique chart ID per chemical so multiple blocks don't collide.
    $chart_id = 'chart-chem-zf-bmd-resp-' . $sanitized_id;

    // -------------------------------------------------------------------------
    // 8. Build the render array.
    //    - Plotly CDN loaded via html_head (no defer — causes race conditions).
    //    - Chart data passed through drupalSettings (the Drupal-safe way).
    //    - Chart init JS lives in an inline script that waits for
    //      DOMContentLoaded, and exposes a render function on window so a
    //      companion endpoint-selector block can switch datasets later.
    // -------------------------------------------------------------------------
    $css = "#{$chart_id}-info { text-align: center; margin-bottom: 8px; }"
      . "#{$chart_id}-title { font-size: 22px; font-weight: 700; color: #2c3e50; margin: 0 0 4px; }"
      . "#{$chart_id}-subtitle { font-size: 14px; color: #6c757d; line-height: 1.5; }";

    $html = "<div id='{$chart_id}-info' class='zf-response-info'>"
      . "<h4 id='{$chart_id}-title'></h4>"
      . "<div id='{$chart_id}-subtitle'></div>"
      . "</div>"
      . "<div id='{$chart_id}' style='width:100%;min-height:400px;'></div>"
      . "<br /><div class='zf-response-plot element-descriptor'>"
      . "<strong>Zebrafish Morphological Measurements Plot:</strong> "
      . "Plots can be selected from the measurements table on the left."
      . "</div>";

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
  var titleEl     = document.getElementById('{$chart_id}-title');
  var subtitleEl  = document.getElementById('{$chart_id}-subtitle');
  var settings    = drupalSettings.superfundBlocks.chemZfBmdResp['{$chart_id}'];
  var datasets    = settings.datasets;
  var currentKey  = null;

  // ---- CSV download (currently displayed endpoint) --------------------------
  function downloadCsv() {
    var dataset = datasets[currentKey];
    if (!dataset) {
      return;
    }
    var headers = ['Dose', 'Response (%)', 'CI Lo', 'CI Hi'];
    var lines   = [headers.join(',')];
    dataset.doseResponse.forEach(function (row) {
      lines.push([row.dose, row.response, row.ciLo, row.ciHi].join(','));
    });
    var blob = new Blob([lines.join('\\n')], { type: 'text/csv' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href     = url;
    a.download = 'chem-zf-bmd-resp-' + currentKey + '-{$sanitized_id}.csv';
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

  var layout = {
    yaxis: {
      range: [0, 105],
      tickvals: [0, 20, 40, 60, 80, 100],
      title: { text: 'Percentage of Adverse Effects' },
    },
    xaxis: {
      title: { text: 'Dilution' },
    },
    showlegend: true,
    legend: {
      orientation: 'h',
      x: 0.5,
      xanchor: 'center',
      y: -0.2,
    },
    dragmode: 'zoom',
  };

  // ---- Render a given endpoint's dataset -------------------------------------
  function renderEndpoint(key) {
    var dataset = datasets[key];
    if (!dataset) {
      return;
    }
    currentKey = key;

    if (titleEl) {
      titleEl.innerHTML = 'Concentration Response Curve' + (dataset.qcIconHtml || '');
    }
    if (subtitleEl) {
      subtitleEl.innerHTML = dataset.subtitleHtml || '';
    }

    var lineTrace = {
      type: 'scatter',
      mode: 'lines+markers',
      name: 'Fitted Curve',
      x: dataset.line.map(function (p) { return p.x; }),
      y: dataset.line.map(function (p) { return p.y; }),
      line: { color: '#7cb5ec', width: 2 },
      marker: { color: '#7cb5ec', size: 5 },
    };

    var scatterTrace = {
      type: 'scatter',
      mode: 'markers',
      name: 'Dose Response',
      x: dataset.doseResponse.map(function (p) { return p.dose; }),
      y: dataset.doseResponse.map(function (p) { return p.response; }),
      error_y: {
        type: 'data',
        symmetric: false,
        array: dataset.doseResponse.map(function (p) { return p.errorPlus; }),
        arrayminus: dataset.doseResponse.map(function (p) { return p.errorMinus; }),
        color: '#000000',
        thickness: 1.5,
        width: 4,
      },
      marker: { color: '#6c63d1', symbol: 'diamond', size: 9 },
    };

    Plotly.react(chartDiv, [lineTrace, scatterTrace], layout, config);
  }

  // Expose so a companion endpoint-selector block can switch datasets:
  //   window.superfundBlocks.chemZfBmdResp['{$chart_id}']('some_other_endpoint_key')
  window.superfundBlocks = window.superfundBlocks || {};
  window.superfundBlocks.chemZfBmdResp = window.superfundBlocks.chemZfBmdResp || {};
  window.superfundBlocks.chemZfBmdResp['{$chart_id}'] = renderEndpoint;

  if (chartDiv.offsetWidth !== 0) {
    renderEndpoint(settings.defaultEndpoint);
  } else {
    var observer = new ResizeObserver(function (entries) {
      for (var entry of entries) {
        if (entry.contentRect.width !== 0) {
          observer.disconnect();
          renderEndpoint(settings.defaultEndpoint);
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
            'chemZfBmdResp' => [
              $chart_id => [
                'endpoints'       => $endpoints,
                'datasets'        => $datasets,
                'defaultEndpoint' => $default_endpoint_key,
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
          // Title/subtitle styling — added via html_head rather than an
          // inline <style> in #markup, since #markup content is run through
          // Drupal's Xss::filter(), which strips <style> tags (they're not
          // in the default allowed-tags list) and leaves the CSS text
          // behind as visible page content.
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'style',
              '#value' => $css,
            ],
            'superfund_chem_zf_bmd_resp_style_' . $sanitized_id,
          ],
          // Inline init script — no defer, polls for Plotly readiness.
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'script',
              '#value' => $js,
            ],
            'superfund_chem_zf_bmd_resp_init_' . $sanitized_id,
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['url.query_args:id'],
      ],
    ];
  }

}
