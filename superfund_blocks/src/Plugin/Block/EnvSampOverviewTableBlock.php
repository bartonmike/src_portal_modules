<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an Environmental Samples Overview Table block.
 *
 * Lists every sample (site-wide, not scoped to a single sample), with
 * DataTables single-select filters for Sample Name/Location and multi-select
 * filters for Project/Sample Technology/Tests Run.
 *
 * @Block(
 *   id = "superfund_blocks_env_samp_overview_table",
 *   admin_label = @Translation("Environmental Samples Overview Table"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class EnvSampOverviewTableBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

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
    // 1. Fetch every sample.
    // -------------------------------------------------------------------------
    $sql = "SELECT
        sample_id,
        sample_number,
        sample_date,
        sample_name,
        location,
        project,
        sample_technology,
        test_list
      FROM view_samples_table";

    $rows = $this->database->query($sql)->fetchAll();

    if (empty($rows)) {
      return ['#markup' => ''];
    }

    // -------------------------------------------------------------------------
    // 2. Build the table.
    // -------------------------------------------------------------------------
    $body_rows = [];
    foreach ($rows as $row) {
      if (!empty($row->sample_date)) {
        $date_obj     = new \DateTime($row->sample_date);
        $display_date = $date_obj->format('m/d/Y');
        $sort_date    = $date_obj->format('Y-m-d');
      }
      else {
        $display_date = '';
        $sort_date    = '0000-00-00';
      }

      $sample_id_url         = rawurlencode($row->sample_id ?? '');
      $sample_number_esc     = htmlspecialchars($row->sample_number ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $sample_name_esc       = htmlspecialchars($row->sample_name ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $display_date_esc      = htmlspecialchars($display_date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $sort_date_esc         = htmlspecialchars($sort_date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $location_esc          = htmlspecialchars($row->location ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $project_esc           = htmlspecialchars($row->project ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $sample_technology_esc = htmlspecialchars($row->sample_technology ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $test_list_esc         = htmlspecialchars($row->test_list ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

      $body_rows[] = "<tr>"
        . "<td><a href='/samples/view?id={$sample_id_url}'>{$sample_number_esc}</a></td>"
        . "<td>{$sample_name_esc}</td>"
        . "<td data-order='{$sort_date_esc}'>{$display_date_esc}</td>"
        . "<td>{$location_esc}</td>"
        . "<td>{$project_esc}</td>"
        . "<td>{$sample_technology_esc}</td>"
        . "<td>{$test_list_esc}</td>"
        . '</tr>';
    }

    $html = "<table id='sample_locations' class='display'>"
      . '<thead>'
      . '<tr>'
      . '<th>Sample Number</th>'
      . '<th>Sample Name</th>'
      . '<th>Sample Date</th>'
      . '<th>Location</th>'
      . '<th>Project</th>'
      . '<th>Sample Technology</th>'
      . '<th>Tests Run</th>'
      . '</tr>'
      . "<tr class='filters-toggle'>"
      . "<th colspan='7' style='text-align:center;'>"
      . "<button type='button' class='filters-toggle-button'>"
      . "<i class='fa-solid fa-filter'></i> Show/hide filters"
      . '</button>'
      . '</th>'
      . '</tr>'
      . "<tr class='filters' style='display:none;'>"
      . '<th></th><th></th><th></th><th></th><th></th><th></th><th></th>'
      . '</tr>'
      . '</thead>'
      . '<tbody>' . implode('', $body_rows) . '</tbody>'
      . '</table>';

    $css = ".filters-toggle-button {"
      . 'background: #f5f5f5;'
      . 'color: #000;'
      . 'border: 1px solid #ccc;'
      . 'border-radius: 999px;'
      . 'padding: 6px 16px;'
      . 'cursor: pointer;'
      . '}'
      . '.filters-toggle-button:hover { background: #e6e6e6; }';

    // -------------------------------------------------------------------------
    // 3. DataTables init: filters-toggle button, single-select exact-match
    //    filters (Sample Name, Location), and multi-select filters
    //    (Project OR, Sample Technology OR, Tests Run AND on tokenized
    //    comma-separated values).
    // -------------------------------------------------------------------------
    $js = <<<JS
(function init() {
  if (typeof DataTable === 'undefined' || !document.getElementById('sample_locations')) {
    setTimeout(init, 50);
    return;
  }

  // Multi-select state (AND logic across categories).
  var selectedProjects = [];
  var selectedTech     = [];
  var selectedTests    = [];

  // Punctuation-agnostic global search state — see the ext.search.push
  // below for how this gets applied.
  var searchTokens = [];

  function tokenizeSearch(str) {
    var normalized = String(str)
      .toLowerCase()
      .replace(/[.,\/#!$%\^&*;:{}=\-_`~()\[\]<>'"?]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
    return normalized === '' ? [] : normalized.split(' ');
  }

  // Shows/hides every .filters row on the page by class, based on computed
  // style (not just this table's — matches the filter-toggle behavior used
  // elsewhere on the site).
  if (typeof window.toggleFilters !== 'function') {
    window.toggleFilters = function () {
      var elements = document.querySelectorAll('.filters');
      elements.forEach(function (el) {
        var currentDisplay = window.getComputedStyle(el).display;
        if (currentDisplay !== 'none') {
          el.style.display = 'none';
        }
        else {
          el.style.display = '';
        }
      });
    };
  }

  function stripHtml(v) {
    if (v == null) {
      return '';
    }
    var tmp = document.createElement('div');
    tmp.innerHTML = String(v);
    return (tmp.textContent || tmp.innerText || '').trim();
  }

  function tokenizeTests(cellValue) {
    var text = stripHtml(cellValue);
    if (!text) {
      return [];
    }
    return text.split(',').map(function (t) { return t.trim(); }).filter(Boolean);
  }

  // Custom filter: Project/Technology use OR within their own selections,
  // Tests Run requires ALL selected tokens to be present. Only applies to
  // this table, so it doesn't affect other DataTables on the same page.
  DataTable.ext.search.push(function (settings, data) {
    if (settings.nTable) {
      if (settings.nTable.id !== 'sample_locations') {
        return true;
      }
    }

    var projectCell = stripHtml(data[4]);
    var techCell    = stripHtml(data[5]);
    var testsTokens = tokenizeTests(data[6]);

    if (selectedProjects.length) {
      if (selectedProjects.indexOf(projectCell) === -1) {
        return false;
      }
    }
    if (selectedTech.length) {
      if (selectedTech.indexOf(techCell) === -1) {
        return false;
      }
    }
    if (selectedTests.length) {
      if (!selectedTests.every(function (t) { return testsTokens.indexOf(t) !== -1; })) {
        return false;
      }
    }

    return true;
  });

  // Punctuation-agnostic global search: strips punctuation/dashes/spaces/
  // brackets/parens from both the typed value and each row's text, then
  // requires every resulting piece to appear somewhere in the row (AND,
  // not OR) — so "50-00-0", "50 00 0", and "500 0" all match the same
  // CAS number. Only applies to this table.
  DataTable.ext.search.push(function (settings, data) {
    if (settings.nTable.id !== 'sample_locations' || !searchTokens.length) {
      return true;
    }
    var haystack = tokenizeSearch(data.map(stripHtml).join(' ')).join(' ');
    return searchTokens.every(function (token) {
      return haystack.indexOf(token) !== -1;
    });
  });

  function buildUniqueOptionsFromColumn(column) {
    var seen = [];
    column.data().each(function (d) {
      var text = stripHtml(d);
      if (text) {
        if (seen.indexOf(text) === -1) {
          seen.push(text);
        }
      }
    });
    return seen.sort();
  }

  function buildUniqueTestTokensFromColumn(column) {
    var seen = [];
    column.data().each(function (d) {
      tokenizeTests(d).forEach(function (tok) {
        if (seen.indexOf(tok) === -1) {
          seen.push(tok);
        }
      });
    });
    return seen.sort();
  }

  var table = new DataTable('#sample_locations', {
    pageLength: 20,
    orderCellsTop: true,
    columnDefs: [
      { targets: 0, width: '160px' },
    ],
    initComplete: function () {
      var api = this.api();

      var searchInput = document.querySelector('#sample_locations_wrapper input[type="search"]');
      if (searchInput) {
        // Replace the input to drop DataTables' own keyup listener — we
        // want our tokenized matching to be the only thing filtering,
        // not stacked on top of the default substring search.
        var freshInput = searchInput.cloneNode(true);
        searchInput.parentNode.replaceChild(freshInput, searchInput);

        var termsDisplay = document.createElement('div');
        termsDisplay.className = 'search-terms-display';
        termsDisplay.style.cssText = 'margin-top:4px;font-size:0.85em;color:#555;';
        freshInput.closest('div').insertAdjacentElement('afterend', termsDisplay);

        freshInput.addEventListener('input', function () {
          searchTokens = tokenizeSearch(this.value);
          termsDisplay.textContent = searchTokens.length
            ? 'Searching for: ' + searchTokens.join(', ')
            : '';
          api.draw();
        });
      }

      // Delegated on the table itself rather than a direct reference to the
      // button — DataTables can rebuild/rewrap header cells during init,
      // which would leave a directly-attached listener bound to a node
      // that's no longer the one on screen.
      document.getElementById('sample_locations').addEventListener('click', function (event) {
        if (event.target.closest('.filters-toggle-button')) {
          window.toggleFilters();
        }
      });

      // Single-select (exact match): Sample Name (1), Location (3).
      var singleSelectCols = [1, 3];
      // Multi-select: Project (4), Technology (5), Tests (6).
      var multiSelectCols = [4, 5, 6];

      singleSelectCols.forEach(function (colIdx) {
        var column = api.column(colIdx);
        var cell = document.querySelector(
          '#sample_locations thead tr.filters th:nth-child(' + (colIdx + 1) + ')'
        );
        cell.replaceChildren();

        var select = document.createElement('select');
        select.add(new Option(''));
        select.style.width = '100%';
        cell.appendChild(select);

        buildUniqueOptionsFromColumn(column).forEach(function (opt) {
          select.add(new Option(opt, opt));
        });

        select.addEventListener('change', function () {
          column.search(this.value, { exact: true }).draw();
        });
      });

      multiSelectCols.forEach(function (colIdx) {
        var column = api.column(colIdx);
        var cell = document.querySelector(
          '#sample_locations thead tr.filters th:nth-child(' + (colIdx + 1) + ')'
        );
        cell.replaceChildren();

        var select = document.createElement('select');
        select.multiple = true;
        select.size = 6;
        select.style.width = '100%';
        cell.appendChild(select);

        var options = colIdx === 6
          ? buildUniqueTestTokensFromColumn(column)
          : buildUniqueOptionsFromColumn(column);
        options.forEach(function (opt) {
          select.add(new Option(opt, opt));
        });

        select.addEventListener('change', function () {
          var picked = Array.from(this.selectedOptions).map(function (o) { return o.value; });

          if (colIdx === 4) {
            selectedProjects = picked;
          }
          if (colIdx === 5) {
            selectedTech = picked;
          }
          if (colIdx === 6) {
            selectedTests = picked;
          }

          api.draw();
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
            'jquery_cdn_env_samp_overview_table',
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
            'datatables_css_cdn_env_samp_overview_table',
          ],
          [
            [
              '#type'       => 'html_tag',
              '#tag'        => 'script',
              '#attributes' => ['src' => 'https://cdn.datatables.net/2.3.2/js/dataTables.js'],
            ],
            'datatables_js_cdn_env_samp_overview_table',
          ],
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'style',
              '#value' => $css,
            ],
            'superfund_env_samp_overview_table_style',
          ],
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'script',
              '#value' => $js,
            ],
            'superfund_env_samp_overview_table_init',
          ],
        ],
      ],
      '#cache' => [
        'contexts' => [],
      ],
    ];
  }

}
