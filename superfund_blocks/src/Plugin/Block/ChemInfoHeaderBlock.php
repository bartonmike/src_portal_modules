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
 * and structure image for the chemical identified by the ?id= or ?cas=
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
            `chemical_class` AS chemical_class
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
    $chemical_name_esc  = htmlspecialchars($row['chemical_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $description_esc    = htmlspecialchars($row['chemical_description'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $dtxcid_esc         = htmlspecialchars($row['dtxcid'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $chemical_class_esc = htmlspecialchars($row['chemical_class'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $dtxcid_url         = rawurlencode($row['dtxcid'] ?? '');

    // Subscript the digits in the molecular formula, e.g. C6H6 -> C<sub>6</sub>H<sub>6</sub>.
    $formula_esc = htmlspecialchars($row['molecular_formula'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $formula_esc = preg_replace('/(\d+)/', '<sub>$1</sub>', $formula_esc);

    $chemical_image_url = "https://comptox.epa.gov/ctx-api/chemical/file/image/search/by-dtxcid/{$dtxcid_url}";
    $dtxcid_link         = "https://comptox.epa.gov/dashboard/dsstoxdb/results?search={$dtxcid_url}";

    // NOTE: the whitespace between </div> and the next <div class='info-box'>
    // is load-bearing — the CSS Injector rule that spaces these boxes out
    // relies on text-align:justify distributing space between inline-block
    // children, which only works if there's whitespace between them to
    // distribute. Collapsing this to a single concatenated string with no
    // gaps makes the boxes jam together with no spacing.
    $html = "<div class='sample-info-header'><h1>{$chemical_name_esc}</h1><p>{$description_esc}</p></div>"
      . "<div class='sample-info'>"
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>DTXCID</h2></div>"
      . "<div class='field_value'><a href='{$dtxcid_link}'>{$dtxcid_esc}</a></div>"
      . '</div> '
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>Formula</h2></div>"
      . "<div class='field_value'>{$formula_esc}</div>"
      . '</div> '
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>Chemical Class</h2></div>"
      . "<div class='field_value'>{$chemical_class_esc}</div>"
      . '</div> '
      . "<div class='info-box'>"
      . "<div class='field_value chemical-structure'><img alt='{$chemical_name_esc}' src='{$chemical_image_url}'/></div>"
      . '</div>'
      . '</div>';

    return [
      '#markup' => $html,
      '#cache'  => [
        'contexts' => ['url.query_args:id', 'url.query_args:cas'],
      ],
    ];
  }

}
