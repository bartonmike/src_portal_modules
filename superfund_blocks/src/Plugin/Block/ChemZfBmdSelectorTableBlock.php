<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a Chemical Zebrafish BMD Endpoint Selector Table block.
 *
 * Lists zebrafish endpoints for a chemical (grouped into Morphological and
 * Behavioral) with AUC/BMD10/BMD50 summary bars. Clicking an endpoint that
 * has data calls into the companion ChemZfBmdRespChartBlock's exposed
 * render function — window.superfundBlocks.chemZfBmdResp['<chart_id>'] —
 * to switch which endpoint's concentration-response curve is displayed,
 * without a page reload. Both blocks derive the same chart_id from the
 * chemical id, so they only need to be placed on the same chemical page.
 *
 * @Block(
 *   id = "superfund_blocks_chem_zf_bmd_selector_table",
 *   admin_label = @Translation("Chemical Zebrafish BMD Endpoint Selector Table"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class ChemZfBmdSelectorTableBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

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
   * Builds the superscript reference-link markup for an endpoint.
   *
   * @param string|null $endpoint_link
   *   Pipe-separated ("|") list of reference URLs, or NULL/'NA'.
   */
  protected function endpointLinksHtml(?string $endpoint_link): string {
    if (empty($endpoint_link) || strtoupper(trim($endpoint_link)) === 'NA') {
      return '';
    }

    $links = preg_split('/\s*\|\s*/', trim($endpoint_link));
    $sup_links = [];

    foreach ($links as $index => $link) {
      $link = trim($link);
      if ($link === '' || strtoupper($link) === 'NA') {
        continue;
      }
      $safe_link = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $sup_links[] = '<sup class="endpoint-link-ref"><a target="_blank" rel="noopener noreferrer" href="' . $safe_link . '">[' . $index . ']</a></sup>';
    }

    return implode('', $sup_links);
  }

  /**
   * Builds a value-bar cell (AUC/BMD10/BMD50), or an "NA" cell.
   */
  protected function valueBarHtml($value, string $tooltip): string {
    if (is_null($value) || !is_numeric($value)) {
      return 'NA';
    }
    $safe_value  = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safe_tooltip = htmlspecialchars($tooltip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return "<div class='value-bar' data-value=\"{$safe_tooltip}\" style='--value: {$safe_value};'><div class='bar-fill'></div></div>";
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
        "SELECT COUNT(zcdr.Dose) AS count
         FROM view_zebrafishChemDoseResponse zcdr
         LEFT JOIN view_zebrafishChemXYCoords zcxy
           ON (zcxy.Chemical_ID = zcdr.Chemical_ID AND zcxy.End_Point_Name = zcdr.End_Point_Name)
         LEFT JOIN view_zebrafishChemBMDs zcbmd
           ON (zcdr.Chemical_ID = zcbmd.Chemical_ID AND zcdr.End_Point_Name = zcbmd.End_Point_Name)
         WHERE zcbmd.BMD10 IS NOT NULL AND zcdr.Chemical_ID = :chem_id",
        [':chem_id' => $sanitized_id]
      )
      ->fetchField();

    if ($count <= 0) {
      return ['#markup' => ''];
    }

    // -------------------------------------------------------------------------
    // 3. Fetch every known endpoint, joined against this chemical's data
    //    (so endpoints with no data for this chemical still list, unlinked),
    //    with two group-header pseudo-rows unioned in for the table sections.
    // -------------------------------------------------------------------------
    $sql = "SELECT
        CONCAT(UCASE(LEFT(cen.End_Point_Name, 1)), SUBSTRING(cen.End_Point_Name, 2)) AS Endpoint,
        LCASE(REPLACE(cen.End_Point_Name, ' ', '_')) AS end_point_name_cleaned,
        zcbmd.AUC_Norm AS AUC,
        zcbmd.BMD10,
        zcbmd.BMD50,
        zcbmd.Max_Dose AS Max_Dose,
        zcdr.End_Point_Name AS data_present,
        zed.description,
        zed.endPointLink,
        CASE
          WHEN cen.End_Point_Name IN ('Behavior transition', '5 day total movement') THEN 4
          ELSE 2
        END AS sort_order
      FROM view_chemical_endpoints cen
      LEFT JOIN view_zebrafishChemDoseResponse zcdr
        ON zcdr.End_Point_Name = cen.End_Point_Name AND zcdr.Chemical_ID = :chem_id
      LEFT JOIN view_zebrafishChemXYCoords zcxy
        ON zcxy.Chemical_ID = zcdr.Chemical_ID AND zcxy.End_Point_Name = zcdr.End_Point_Name
      LEFT JOIN view_zebrafishChemBMDs zcbmd
        ON zcdr.Chemical_ID = zcbmd.Chemical_ID AND zcdr.End_Point_Name = zcbmd.End_Point_Name
      LEFT JOIN superfund_zebrafish_endpoint_descriptions zed
        ON zed.End_Point_Name = cen.End_Point_Name

      UNION

      SELECT 'Morphological Endpoints', '', NULL, NULL, NULL, NULL, '', NULL, NULL, 1

      UNION

      SELECT 'Behavioral Endpoints', '', NULL, NULL, NULL, NULL, '', NULL, NULL, 3

      ORDER BY sort_order, Endpoint";

    $rows = $this->database
      ->query($sql, [':chem_id' => $sanitized_id])
      ->fetchAll();

    if (empty($rows)) {
      return ['#markup' => ''];
    }

    // -------------------------------------------------------------------------
    // 4. Build the table rows.
    // -------------------------------------------------------------------------
    $chart_id = 'chart-chem-zf-bmd-resp-' . $sanitized_id;

    $body_rows = [];
    foreach ($rows as $row) {
      if (in_array($row->Endpoint, ['Morphological Endpoints', 'Behavioral Endpoints'], TRUE)) {
        $body_rows[] = "<tr><td colspan='4'>" . htmlspecialchars($row->Endpoint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td></tr>';
        continue;
      }

      $endpoint_name_text = htmlspecialchars($row->Endpoint ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $endpoint_description = htmlspecialchars($row->description ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

      $endpoint_name = $endpoint_description !== ''
        ? '<span class="endpoint-name-hover" title="' . $endpoint_description . '">' . $endpoint_name_text . '</span>'
        : $endpoint_name_text;

      $endpoint_links_html = $this->endpointLinksHtml($row->endPointLink ?? NULL);

      $has_data = !empty($row->data_present);

      $max_dose = is_numeric($row->Max_Dose) ? round((float) $row->Max_Dose, 2) : NULL;

      $auc_html = $this->valueBarHtml($row->AUC, (string) $row->AUC);

      $bmd10_tooltip = is_numeric($row->BMD10) ? round((float) $row->BMD10, 4) . '/' . ($max_dose ?? 'NA') : '';
      $bmd10_value = (is_numeric($row->BMD10) && $max_dose) ? min(1, (float) $row->BMD10 / $max_dose) : NULL;
      $bmd10_html = is_null($bmd10_value) ? 'NA' : $this->valueBarHtml($bmd10_value, $bmd10_tooltip);

      $bmd50_tooltip = is_numeric($row->BMD50) ? round((float) $row->BMD50, 4) . '/' . ($max_dose ?? 'NA') : '';
      $bmd50_value = (is_numeric($row->BMD50) && $max_dose) ? min(1, (float) $row->BMD50 / $max_dose) : NULL;
      $bmd50_html = is_null($bmd50_value) ? 'NA' : $this->valueBarHtml($bmd50_value, $bmd50_tooltip);

      $endpoint_html = $has_data ? '<u>' . $endpoint_name . '</u>' : $endpoint_name;

      if ($has_data) {
        $key = htmlspecialchars($row->end_point_name_cleaned, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $open_link  = "<a class='endpoint-select' data-endpoint-key='{$key}'>";
        $close_link = '</a>';
      }
      else {
        $open_link  = '';
        $close_link = '';
      }

      $body_rows[] = "<tr>"
        . "<td>{$open_link}{$endpoint_html}{$close_link}{$endpoint_links_html}</td>"
        . "<td>{$open_link}{$auc_html}{$close_link}</td>"
        . "<td>{$open_link}{$bmd10_html}{$close_link}</td>"
        . "<td>{$open_link}{$bmd50_html}{$close_link}</td>"
        . '</tr>';
    }

    $descriptor = "<div class='zf-table element-descriptor'>"
      . '<strong>Zebrafish Morphological Measurements Table:</strong> This table shows an '
      . '<strong>area under the curve </strong>(AUC) measurement for each modeled line. This '
      . 'value is scaled between 0 and 1, where 0 indicates a flat line at 0% and 1 indicates '
      . 'a flat line at 100%. Hover over the bar for the true value. Similarly, the '
      . '<strong>BMD10 </strong>(the concentration where 10% of the zebrafish were affected) '
      . 'and <strong>BMD50 </strong>are reported with a scale. Click the underlined name of '
      . 'each measurement (endpoint) to see its plot.'
      . '</div>';

    $html = "<table id='endpoint-table' class='display'>"
      . "<thead><tr>"
      . "<th class='endpoint-table-name'>Endpoint</th>"
      . "<th class='endpoint-table-auc'>AUC</th>"
      . "<th class='endpoint-table-bmd10'>BMD10</th>"
      . "<th class='endpoint-table-bmd50'>BMD50</th>"
      . "</tr></thead>"
      . '<tbody>' . implode('', $body_rows) . '</tbody>'
      . '</table><br />'
      . $descriptor;

    // -------------------------------------------------------------------------
    // 5. Inline script: delegate clicks on rows with a data-endpoint-key to
    //    the companion chart block's exposed render function.
    // -------------------------------------------------------------------------
    $js = <<<JS
(function init() {
  var table = document.getElementById('endpoint-table');
  if (!table) {
    setTimeout(init, 50);
    return;
  }

  table.addEventListener('click', function (event) {
    var target = event.target.closest('[data-endpoint-key]');
    if (!target) {
      return;
    }

    var render = window.superfundBlocks
      && window.superfundBlocks.chemZfBmdResp
      && window.superfundBlocks.chemZfBmdResp['{$chart_id}'];

    if (typeof render === 'function') {
      render(target.getAttribute('data-endpoint-key'));
    }
  });
})();
JS;

    return [
      '#type'     => 'markup',
      '#markup'   => $html,
      '#attached' => [
        'html_head' => [
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'script',
              '#value' => $js,
            ],
            'superfund_chem_zf_bmd_selector_init_' . $sanitized_id,
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['url.query_args:id'],
      ],
    ];
  }

}
