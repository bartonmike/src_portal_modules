<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Chemical Overview Table block.
 *
 * Lists every chemical that has sample or endpoint data (site-wide, not
 * scoped to a single chemical), with DataTables column-filter dropdowns for
 * Chemical Class and Endpoints.
 *
 * @Block(
 *   id = "superfund_blocks_chem_overview_table",
 *   admin_label = @Translation("Chemical Overview Table"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class ChemOverviewTableBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

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
    // 1. Fetch every chemical with sample and/or endpoint data.
    // -------------------------------------------------------------------------
    $sql = "SELECT
        vc.Chemical_ID      AS chemical_id,
        vc.PREFERRED_NAME   AS chemical_name,
        vc.cas_number       AS cas_number,
        vc.chemical_class   AS chemical_class,
        COALESCE(sc.sample_count, 0) AS sample_count,
        CONCAT_WS(',',
          CASE WHEN COALESCE(zf.zf_count, 0) > 0 THEN 'ZF' END,
          CASE WHEN COALESCE(sg.sg_count, 0) > 0 THEN 'SG' END
        ) AS data_flags
      FROM view_chemicals vc
      LEFT JOIN (
        SELECT vstc.Chemical_ID, COUNT(DISTINCT vstc.Sample_ID) AS sample_count
        FROM view_samplesToChemicals vstc
        GROUP BY vstc.Chemical_ID
      ) sc ON sc.Chemical_ID = vc.Chemical_ID
      LEFT JOIN (
        SELECT zcdr.Chemical_ID, COUNT(zcdr.Dose) AS zf_count
        FROM view_zebrafishChemDoseResponse zcdr
        WHERE zcdr.Dose > 0
        GROUP BY zcdr.Chemical_ID
      ) zf ON zf.Chemical_ID = vc.Chemical_ID
      LEFT JOIN (
        SELECT sgs.Chemical_ID, COUNT(*) AS sg_count
        FROM view_sigGeneStats sgs
        GROUP BY sgs.Chemical_ID
      ) sg ON sg.Chemical_ID = vc.Chemical_ID
      WHERE (
        COALESCE(sc.sample_count, 0) != 0
        OR CONCAT_WS(',',
             CASE WHEN COALESCE(zf.zf_count, 0) > 0 THEN 'ZF' END,
             CASE WHEN COALESCE(sg.sg_count, 0) > 0 THEN 'SG' END
           ) != ''
      )
      ORDER BY sample_count DESC";

    $rows = $this->database->query($sql)->fetchAll();

    if (empty($rows)) {
      return ['#markup' => ''];
    }

    // -------------------------------------------------------------------------
    // 2. Build the table.
    // -------------------------------------------------------------------------
    $body_rows = [];
    foreach ($rows as $row) {
      $chemical_id_url    = rawurlencode($row->chemical_id ?? '');
      $chemical_name_esc  = htmlspecialchars($row->chemical_name ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $cas_number_esc     = htmlspecialchars($row->cas_number ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $chemical_class_esc = htmlspecialchars($row->chemical_class ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $sample_count_esc   = htmlspecialchars((string) $row->sample_count, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $endpoints_esc      = htmlspecialchars($row->data_flags ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

      $body_rows[] = "<tr>"
        . "<td><a href='/chemicals/view?id={$chemical_id_url}'>{$chemical_name_esc}</a></td>"
        . "<td>{$cas_number_esc}</td>"
        . "<td>{$chemical_class_esc}</td>"
        . "<td>{$sample_count_esc}</td>"
        . "<td>{$endpoints_esc}</td>"
        . '</tr>';
    }

    $html = "<table id='chemicals_table' class='display'>"
      . '<thead>'
      . '<tr>'
      . '<th>Chemical Name</th>'
      . '<th>Cas Number</th>'
      . '<th>Chemical Class</th>'
      . '<th># Samples</th>'
      . '<th>Endpoints</th>'
      . '</tr>'
      . "<tr class='filters'><th></th><th></th><th></th><th></th><th></th></tr>"
      . '</thead>'
      . '<tbody>' . implode('', $body_rows) . '</tbody>'
      . '</table>';

    // -------------------------------------------------------------------------
    // 3. DataTables init — column-filter dropdowns for Chemical Class (col 2)
    //    and Endpoints (col 4). Polls for the DataTable library since it's
    //    expected to be loaded as a sitewide library rather than by this
    //    block.
    // -------------------------------------------------------------------------
    $js = <<<JS
(function init() {
  if (typeof DataTable === 'undefined' || !document.getElementById('chemicals_table')) {
    setTimeout(init, 50);
    return;
  }

  new DataTable('#chemicals_table', {
    pageLength: 20,
    orderCellsTop: true,
    initComplete: function () {
      var api = this.api();

      // Filters needed: Chemical Class (col 2), Endpoints (col 4).
      // No filters for Chemical Name, CAS, Sample Count.
      var filterCols = [2, 4];

      filterCols.forEach(function (colIdx) {
        var column = api.column(colIdx);

        var cell = document.querySelector(
          '#chemicals_table thead tr.filters th:nth-child(' + (colIdx + 1) + ')'
        );
        cell.replaceChildren();

        var select = document.createElement('select');
        select.add(new Option(''));
        select.style.width = '100%';
        cell.appendChild(select);

        select.addEventListener('change', function () {
          column.search(this.value, { exact: true }).draw();
        });

        column
          .data()
          .unique()
          .sort()
          .each(function (d) {
            var tmp = document.createElement('div');
            tmp.innerHTML = d;
            var text = tmp.textContent || tmp.innerText || '';
            select.add(new Option(text, text));
          });
      });
    },
  });
})();
JS;

    return [
      '#type'     => 'markup',
      '#markup'   => $html,
      '#attached' => [
        'html_head' => [
          // jQuery + DataTables — not loaded sitewide, so this block brings
          // its own copies.
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://code.jquery.com/jquery-3.7.1.js'],
            ],
            'jquery_cdn_chem_overview_table',
          ],
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'link',
              '#attributes' => [
                'rel'  => 'stylesheet',
                'href' => 'https://cdn.datatables.net/2.3.2/css/dataTables.dataTables.css',
              ],
            ],
            'datatables_css_cdn_chem_overview_table',
          ],
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://cdn.datatables.net/2.3.2/js/dataTables.js'],
            ],
            'datatables_js_cdn_chem_overview_table',
          ],
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'script',
              '#value' => $js,
            ],
            'superfund_chem_overview_table_init',
          ],
        ],
      ],
      '#cache' => [
        'contexts' => [],
      ],
    ];
  }

}
