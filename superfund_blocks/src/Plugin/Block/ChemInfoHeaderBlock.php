<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\superfund_blocks\ChemicalIdResolverTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides the Chemical Info Header block.
 *
 * Displays the name, description, DTXCID, molecular formula, chemical class,
 * structure image, CAS number, DTXSID, PubChem CID, and average mass for the
 * chemical identified by the ?id= or ?cas=
 * query parameter. Meant to sit above the other chemical-page blocks.
 *
 * @Block(
 *   id = "superfund_blocks_chem_info_header",
 *   admin_label = @Translation("Chemical Info Header"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class ChemInfoHeaderBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

  use ChemicalIdResolverTrait;

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
    // 1. Resolve the chemical from ?cas= or ?id=.
    // -------------------------------------------------------------------------
    $request      = $this->requestStack->getCurrentRequest();
    $sanitized_id = $this->resolveChemicalId($request);

    $row = NULL;
    if ($sanitized_id !== NULL) {
      // -------------------------------------------------------------------------
      // 2. Fetch the chemical's info.
      // -------------------------------------------------------------------------
      $row = $this->database
        ->query(
          'SELECT
            `PREFERRED_NAME` AS chemical_name,
            `chemDescription` AS chemical_description,
            `DTXCID` AS dtxcid,
            `MOLECULAR_FORMULA` AS molecular_formula,
            `chemical_class` AS chemical_class,
            `cas_number` AS cas_number,
            `DTXSID` AS dtxsid,
            `PUBCHEM_CID` AS pubchem_cid,
            `AVERAGE_MASS` AS average_mass
          FROM `view_chemicals`
          WHERE `Chemical_ID` = :chem_id
          LIMIT 1',
          [':chem_id' => $sanitized_id]
        )
        ->fetchAssoc();
    }

    if (empty($row)) {
      return [
        '#markup' => "<div class='sample-info-header'><h1>Chemical Information</h1>"
          . "<p>We couldn't find that Chemical ID, try <a href='/chemicals'>selecting it again</a>, "
          . "otherwise <a href='/contact-us'>contact us</a> if this is in error.</p></div>",
        '#cache' => ['contexts' => ['url.query_args:id', 'url.query_args:cas']],
      ];
    }

    // -------------------------------------------------------------------------
    // 3. Escape fields and build the header markup.
    // -------------------------------------------------------------------------
    $esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Returns the trimmed field value, or NULL when it's missing: a real NULL,
    // empty, the literal string "NULL" (the import stores some missing values
    // that way), or a placeholder CAS like 3421_NO_CAS / 3411_No_CAS. Missing
    // fields are shown as "Unknown" with no link.
    $value_of = static function ($value): ?string {
      $value = trim((string) ($value ?? ''));
      return ($value === '' || strcasecmp($value, 'NULL') === 0 || preg_match('/_NO_CAS$/i', $value))
        ? NULL
        : $value;
    };

    $chemical_name_esc = $esc($row['chemical_name'] ?? '');
    // A missing description is left blank rather than showing "Unknown".
    $description_esc   = $esc($value_of($row['chemical_description'] ?? NULL) ?? '');

    $cas_number     = $value_of($row['cas_number'] ?? NULL);
    $dtxsid         = $value_of($row['dtxsid'] ?? NULL);
    $dtxcid         = $value_of($row['dtxcid'] ?? NULL);
    $pubchem_cid    = $value_of($row['pubchem_cid'] ?? NULL);
    $formula        = $value_of($row['molecular_formula'] ?? NULL);
    $chemical_class = $value_of($row['chemical_class'] ?? NULL);
    $average_mass   = $value_of($row['average_mass'] ?? NULL);

    $cas_html = $cas_number === NULL ? 'Unknown'
      : "<a target='_blank' rel='noopener' href='https://commonchemistry.cas.org/detail?cas_rn="
        . rawurlencode($cas_number) . "'>" . $esc($cas_number) . '</a>';

    // CompTox SID and PubChem ID link out to their source databases when present.
    $dtxsid_html = $dtxsid === NULL ? 'Unknown'
      : "<a target='_blank' rel='noopener' href='https://comptox.epa.gov/dashboard/chemical/details/"
        . rawurlencode($dtxsid) . "'>" . $esc($dtxsid) . '</a>';

    $pubchem_html = $pubchem_cid === NULL ? 'Unknown'
      : "<a target='_blank' rel='noopener' href='https://pubchem.ncbi.nlm.nih.gov/compound/"
        . rawurlencode($pubchem_cid) . "'>" . $esc($pubchem_cid) . '</a>';

    // CompTox detail pages are keyed by DTXSID, so the DTXCID links to the same
    // chemical's detail page when a DTXSID is available, else falls back to search.
    $dtxcid_url  = rawurlencode($dtxcid ?? '');
    $dtxcid_html = 'Unknown';
    if ($dtxcid !== NULL) {
      $dtxcid_link = $dtxsid !== NULL
        ? 'https://comptox.epa.gov/dashboard/chemical/details/' . rawurlencode($dtxsid)
        : "https://comptox.epa.gov/dashboard/dsstoxdb/results?search={$dtxcid_url}";
      $dtxcid_html = "<a href='{$dtxcid_link}'>" . $esc($dtxcid) . '</a>';
    }

    // Subscript the digits in the molecular formula, e.g. C6H6 -> C<sub>6</sub>H<sub>6</sub>.
    $formula_html = $formula === NULL ? 'Unknown'
      : preg_replace('/(\d+)/', '<sub>$1</sub>', $esc($formula));

    $chemical_class_html = $chemical_class === NULL ? 'Unknown' : $esc($chemical_class);
    $average_mass_html   = $average_mass === NULL ? 'Unknown' : $esc($average_mass);

    // Structure image, keyed by DTXSID. Omitted entirely (no column) when the
    // chemical has no DTXSID, so the name and first row take the full width.
    $structure_html = '';
    if ($dtxsid !== NULL) {
      $chemical_image_url = 'https://comptox.epa.gov/ctx-api/chemical/file/image/search/by-dtxsid/' . rawurlencode($dtxsid);
      $structure_html = "<div class='chem-info-structure'>"
        . "<img alt='Chemical structure of {$chemical_name_esc}' src='{$chemical_image_url}'/>"
        . '</div>';
    }

    // NOTE: the whitespace between </div> and the next <div class='info-box'>
    // is load-bearing — the CSS Injector rule that spaces these boxes out
    // relies on text-align:justify distributing space between inline-block
    // children, which only works if there's whitespace between them to
    // distribute. Collapsing this to a single concatenated string with no
    // gaps makes the boxes jam together with no spacing.
    //
    // Layout: .chem-info-top is a flex row holding the name + first row of
    // identifiers (.chem-info-main) on the left and the structure image on
    // the right; the second row of identifiers spans the full width below.
    $html = "<div class='chem-info-top'>"
      . "<div class='chem-info-main'>"
      . "<div class='sample-info-header'><h1>{$chemical_name_esc}</h1><p>{$description_esc}</p></div>"
      . "<div class='sample-info'>"
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>CAS Number</h2></div>"
      . "<div class='field_value'>{$cas_html}</div>"
      . '</div> '
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>Formula</h2></div>"
      . "<div class='field_value'>{$formula_html}</div>"
      . '</div> '
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>Chemical Class</h2></div>"
      . "<div class='field_value'>{$chemical_class_html}</div>"
      . '</div>'
      . '</div>'
      . '</div>'
      . $structure_html
      . '</div>'
      . "<div class='sample-info'>"
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>Average Mass</h2></div>"
      . "<div class='field_value'>{$average_mass_html}</div>"
      . '</div> '
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>PubChem ID</h2></div>"
      . "<div class='field_value'>{$pubchem_html}</div>"
      . '</div> '
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>CompTox DTXSID</h2></div>"
      . "<div class='field_value'>{$dtxsid_html}</div>"
      . '</div> '
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>CompTox DTXCID</h2></div>"
      . "<div class='field_value'>{$dtxcid_html}</div>"
      . '</div>'
      . '</div>';

    // Layout CSS goes in html_head rather than #markup: #markup is run through
    // Xss::filter(), which strips <style> tags and style attributes.
    $css = '.chem-info-top{display:flex;align-items:center;gap:24px;}'
      . '.chem-info-main{flex:1 1 auto;min-width:0;}'
      . '.chem-info-structure{flex:0 0 220px;text-align:center;}'
      . '.chem-info-structure img{max-width:100%;height:auto;}'
      . '@media (max-width:768px){'
      . '.chem-info-top{flex-direction:column;}'
      . '.chem-info-structure{flex-basis:auto;}'
      . '}';

    return [
      '#markup'   => $html,
      '#attached' => [
        'html_head' => [
          [
            [
              '#type'  => 'html_tag',
              '#tag'   => 'style',
              '#value' => $css,
            ],
            'superfund_chem_info_header_style',
          ],
        ],
      ],
      '#cache'    => [
        'contexts' => ['url.query_args:id', 'url.query_args:cas'],
      ],
    ];
  }

}
