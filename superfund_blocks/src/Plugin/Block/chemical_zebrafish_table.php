<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a 'Hello World' block.
 *
 * To add a new block, duplicate this file, rename the class and update:
 *  - The @Block annotation (id, admin_label)
 *  - The build() method with your custom PHP logic
 *  - Optionally add blockForm() / blockSubmit() for per-block config
 *
 * @Block(
 *   id = "superfund_blocks_chemical_zebrafish_table",
 *   admin_label = @Translation("Chemical Zebrafish Table Block"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class chemical_zebrafish_table extends BlockBase implements BlockPluginInterface {
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
    // 1. Get and validate the ?id= query parameter.
    // -------------------------------------------------------------------------
    $request = $this->requestStack->getCurrentRequest();
    $raw_id = $request->query->get('id', '');
 
    // Strip anything that isn't a digit or dash, then validate the format.
    $sanitized_id = preg_replace('/[^0-9\-]/', '', $raw_id);
 
    if (!preg_match('/^\d+(-\d+)?$/', $sanitized_id)) {
      // No valid ID — render nothing.
      return ['#markup' => ''];
    }
 
    // -------------------------------------------------------------------------
    // 2. Check whether there is any data for this chemical ID.
    // -------------------------------------------------------------------------
    $count_sql = "SELECT COUNT(zcdr.Dose) AS count
      FROM view_zebrafishChemDoseResponse zcdr
      LEFT JOIN view_zebrafishChemXYCoords zcxy
        ON (zcxy.Chemical_ID = zcdr.Chemical_ID AND zcxy.End_Point_Name = zcdr.End_Point_Name)
      LEFT JOIN view_zebrafishChemBMDs zcbmd
        ON (zcdr.Chemical_ID = zcbmd.Chemical_ID AND zcdr.End_Point_Name = zcbmd.End_Point_Name)
      WHERE zcbmd.BMD10 IS NOT NULL
        AND zcdr.Chemical_ID = :chem_id";
 
    $count = (int) $this->database
      ->query($count_sql, [':chem_id' => $sanitized_id])
      ->fetchField();
 
    if ($count <= 0) {
      return ['#markup' => ''];
    }
 
    // -------------------------------------------------------------------------
    // 3. Fetch endpoint rows.
    // -------------------------------------------------------------------------
    $endpoint_sql = "SELECT DISTINCT
        CONCAT(UCASE(LEFT(cen.End_Point_Name, 1)), SUBSTRING(cen.End_Point_Name, 2)) AS Endpoint,
        LCASE(REPLACE(cen.End_Point_Name, ' ', '_'))                                  AS end_point_name_cleaned,
        zcbmd.AUC_Norm  AS AUC,
        zcbmd.BMD10,
        zcbmd.BMD50,
        zcbmd.Max_Dose  AS Max_Dose,
        zcdr.End_Point_Name AS data_present,
        zed.description,
        zed.ontologyLink,
        CASE
          WHEN cen.End_Point_Name IN ('Behavior transition', '5 day total movement') THEN 4
          ELSE 2
        END AS sort_order
      FROM view_chemical_endpoints cen
      LEFT JOIN view_zebrafishChemDoseResponse zcdr
        ON zcdr.End_Point_Name = cen.End_Point_Name AND zcdr.Chemical_ID = :chem_id
      LEFT JOIN view_zebrafishChemXYCoords zcxy
        ON (zcxy.Chemical_ID = zcdr.Chemical_ID AND zcxy.End_Point_Name = zcdr.End_Point_Name)
           AND zcxy.Chemical_ID = zcdr.Chemical_ID
      LEFT JOIN view_zebrafishChemBMDs zcbmd
        ON (zcdr.Chemical_ID = zcbmd.Chemical_ID AND zcdr.End_Point_Name = zcbmd.End_Point_Name)
           AND zcdr.Chemical_ID = zcdr.Chemical_ID
      LEFT JOIN superfund_zebrafish_endpoint_descriptions zed
        ON zed.simpleName = cen.End_Point_Name
 
      UNION
 
      SELECT 'Morphological Endpoints', '', '', '', '', '', '', '', '', 1
 
      UNION
 
      SELECT 'Behavioral Endpoints', '', '', '', '', '', '', '', '', 3
 
      ORDER BY sort_order, Endpoint";
 
    $rows = $this->database
      ->query($endpoint_sql, [':chem_id' => $sanitized_id])
      ->fetchAll();
 
    if (empty($rows)) {
      return ['#markup' => ''];
    }
 
    // -------------------------------------------------------------------------
    // 4. Build the HTML table.
    // -------------------------------------------------------------------------
    $header_rows = [
      'Morphological Endpoints',
      'Behavioral Endpoints',
    ];
 
    $tbody = '';
    foreach ($rows as $row) {
      if (in_array($row->Endpoint, $header_rows, TRUE)) {
        $tbody .= "<tr><td colspan='4'>" . htmlspecialchars($row->Endpoint) . "</td></tr>\n";
        continue;
      }
 
      $max_dose   = round((float) $row->Max_Dose, 2);
      $ep_cleaned = htmlspecialchars($row->end_point_name_cleaned);
 
      // AUC bar.
      if (!is_null($row->AUC) && is_numeric($row->AUC)) {
        $auc_html = "<div class='value-bar' data-value='{$row->AUC}' style='--value: {$row->AUC};'><div class='bar-fill'></div></div>";
      }
      else {
        $auc_html = 'NA';
      }
 
      // BMD10 bar.
      if (!is_null($row->BMD10) && is_numeric($row->BMD10)) {
        $bmd10_val  = round((float) $row->BMD10, 4);
        $bmd10_pct  = min(1, (float) $row->BMD10 / (float) $row->Max_Dose);
        $bmd10_html = "<div class='value-bar' data-value='{$bmd10_val}/{$max_dose}' style='--value: {$bmd10_pct};'><div class='bar-fill'></div></div>";
      }
      else {
        $bmd10_html = 'NA';
      }
 
      // BMD50 bar.
      if (!is_null($row->BMD50) && is_numeric($row->BMD50)) {
        $bmd50_val  = round((float) $row->BMD50, 4);
        $bmd50_pct  = min(1, (float) $row->BMD50 / (float) $row->Max_Dose);
        $bmd50_html = "<div class='value-bar' data-value='{$bmd50_val}/{$max_dose}' style='--value: {$bmd50_pct};'><div class='bar-fill'></div></div>";
      }
      else {
        $bmd50_html = 'NA';
      }
 
      // Endpoint label — underline if data is present.
      $ep_label = ($row->data_present !== '')
        ? '<u>' . htmlspecialchars($row->Endpoint) . '</u>'
        : htmlspecialchars($row->Endpoint);
 
      $tbody .= "<tr>
        <td><a onclick='switchData(chartOptionsJSON_{$ep_cleaned})'>{$ep_label}</a></td>
        <td><a onclick='switchData(chartOptionsJSON_{$ep_cleaned})'>{$auc_html}</a></td>
        <td><a onclick='switchData(chartOptionsJSON_{$ep_cleaned})'>{$bmd10_html}</a></td>
        <td><a onclick='switchData(chartOptionsJSON_{$ep_cleaned})'>{$bmd50_html}</a></td>
      </tr>\n";
    }
 
    $descriptor = "<div class='zf-table element-descriptor'>"
      . "<strong>Zebrafish Morphological Measurements Table:</strong> "
      . "This table shows an <strong>area under the curve</strong> (AUC) measurement for each modeled line. "
      . "This value is scaled between 0 and 1, where 0 indicates a flat line at 0% and 1 indicates a flat line at 100%. "
      . "Hover over the bar for the true value. Similarly, the <strong>BMD10</strong> "
      . "(the concentration where 10% of the zebrafish were affected) and <strong>BMD50</strong> "
      . "are reported with a scale. Click the underlined name of each measurement (endpoint) to see its plot."
      . "</div>";
 
    $html = "<table id='endpoint-table' class='display'>
      <thead>
        <tr>
          <th class='endpoint-table-name'>Endpoint</th>
          <th class='endpoint-table-auc'>AUC</th>
          <th class='endpoint-table-bmd10'>BMD10</th>
          <th class='endpoint-table-bmd50'>BMD50</th>
        </tr>
      </thead>
      <tbody>{$tbody}</tbody>
    </table><br />{$descriptor}";
 
    // -------------------------------------------------------------------------
    // 5. Return a render array.
    //    Cache varies by URL query string so each ?id= gets its own cache entry.
    // -------------------------------------------------------------------------
    return [
      '#type'     => 'markup',
      '#markup'   => $html,
      '#attached' => [
        'html_head' => [
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'script',
              '#value' => "function switchData(dataName){
  Highcharts.chart('chartOptions_chemical_bmd_resp', dataName);
  console.log('switchData called', dataName);
}",
            ],
            'superfund_blocks_switch_data',
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['url.query_args:id'],
      ],
    ];
  }
 
}
