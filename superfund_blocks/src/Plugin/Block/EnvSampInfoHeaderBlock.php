<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides the Environmental Sample Info Header block.
 *
 * Displays the sample name, project, location, technology, matrix, sample
 * date, tests run, and project link for the sample identified by the ?id=
 * query parameter. Meant to sit above the other sample-page blocks.
 *
 * @Block(
 *   id = "superfund_blocks_env_samp_info_header",
 *   admin_label = @Translation("Environmental Sample Info Header"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class EnvSampInfoHeaderBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

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

    $row = NULL;
    if (preg_match('/^\d+(-\d+)?$/', $sanitized_id)) {
      // -------------------------------------------------------------------------
      // 2. Fetch the sample's info.
      // -------------------------------------------------------------------------
      $sql = "SELECT DISTINCT
          `LocationName` AS location_name,
          `projectName`  AS project_name,
          `Sample_ID`    AS sample_id,
          `technology`   AS technology,
          `sample_matrix` AS sample_matrix,
          `SampleNumber` AS sample_number,
          `sampleName`   AS sample_name,
          `projectLink`  AS project_link,
          DATE_FORMAT(
            STR_TO_DATE(SUBSTRING_INDEX(`date_sampled`, ' ', 1), '%m/%d/%Y'),
            '%m/%d/%Y'
          ) AS retrieval_date,
          (
            SELECT GROUP_CONCAT(DISTINCT vsc.test_method)
            FROM view_samplesToChemicals vsc
            WHERE vsc.Sample_ID = vs.Sample_ID
          ) AS test_list
        FROM `view_samples` vs
        WHERE `Sample_ID` = :sample_id";

      $row = $this->database
        ->query($sql, [':sample_id' => $sanitized_id])
        ->fetchAssoc();
    }

    if (empty($row)) {
      return [
        '#markup' => "<div class='sample-info-header'><h1>Sample not found</h1>"
          . "<p>We couldn't find that Sample ID, try <a href='/samples'>selecting it again</a>, "
          . "otherwise <a href='/contact-us'>contact us</a> if this is in error.</p></div>",
        '#cache' => ['contexts' => ['url.query_args:id']],
      ];
    }

    // -------------------------------------------------------------------------
    // 3. Escape fields and build the header markup.
    // -------------------------------------------------------------------------
    $location_name_esc  = htmlspecialchars($row['location_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $project_name_esc   = htmlspecialchars($row['project_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $sample_id_esc      = htmlspecialchars($row['sample_id'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $technology_esc     = htmlspecialchars($row['technology'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $sample_matrix_esc  = htmlspecialchars($row['sample_matrix'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $sample_number_esc  = htmlspecialchars($row['sample_number'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $sample_name_esc    = htmlspecialchars($row['sample_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $retrieval_date_esc = htmlspecialchars($row['retrieval_date'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $test_list_esc      = htmlspecialchars($row['test_list'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $project_link_html = '';
    if (!empty($row['project_link'])) {
      $project_link_esc  = htmlspecialchars($row['project_link'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $project_link_html = "<a target='_blank' rel='noopener' href='{$project_link_esc}'>{$project_link_esc}</a>";
    }

    $html = "<div class='sample-info-header'><h1>Sample Information: {$sample_name_esc}</h1></div>"
      . "<div class='sample-info-header'><h2>Project Name: {$project_name_esc}</h2></div>"
      . "<div class='sample-info'>"
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>Location</h2></div>"
      . "<div class='field_value'>{$location_name_esc}</div>"
      . '</div>'
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>Sample IDs</h2></div>"
      . "<div class='field_value'>{$sample_id_esc}({$sample_number_esc})</div>"
      . '</div>'
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>Technology</h2></div>"
      . "<div class='field_value'>{$technology_esc}</div>"
      . '</div>'
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>Sample Matrix</h2></div>"
      . "<div class='field_value'>{$sample_matrix_esc}</div>"
      . '</div>'
      . '</div>'
      . "<div class='sample-info'>"
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>Sample Date</h2></div>"
      . "<div class='field_value'>{$retrieval_date_esc}</div>"
      . '</div>'
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>Tests Run</h2></div>"
      . "<div class='field_value'>{$test_list_esc}</div>"
      . '</div>'
      . "<div class='info-box'>"
      . "<div class='field_label'><h2>Project Link</h2></div>"
      . "<div class='field_value'>{$project_link_html}</div>"
      . '</div>'
      . '</div>';

    return [
      '#markup' => $html,
      '#cache'  => [
        'contexts' => ['url.query_args:id'],
      ],
    ];
  }

}
