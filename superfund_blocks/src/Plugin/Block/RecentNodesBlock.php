<?php

namespace Drupal\superfund_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Recent Nodes' block with a database query example.
 *
 * This block demonstrates:
 *  - Injecting the database service
 *  - Per-block config (limit setting)
 *  - Returning a themed list of results
 *
 * @Block(
 *   id = "superfund_blocks_recent_nodes",
 *   admin_label = @Translation("Recent Nodes Block"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class RecentNodesBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

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
  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);

    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of nodes to show'),
      '#default_value' => $this->configuration['limit'] ?? 5,
      '#min' => 1,
      '#max' => 50,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    parent::blockSubmit($form, $form_state);
    $this->configuration['limit'] = (int) $form_state->getValue('limit');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // -----------------------------------------------------------
    // YOUR CUSTOM PHP LOGIC GOES HERE
    // -----------------------------------------------------------
    $limit = $this->configuration['limit'] ?? 5;

    $query = $this->database->select('node_field_data', 'n')
      ->fields('n', ['nid', 'title', 'created'])
      ->condition('n.status', 1)
      ->orderBy('n.created', 'DESC')
      ->range(0, $limit);

    $results = $query->execute()->fetchAll();

    $items = [];
    foreach ($results as $row) {
      $items[] = [
        '#markup' => '<a href="/node/' . $row->nid . '">' . htmlspecialchars($row->title) . '</a>',
      ];
    }
    // -----------------------------------------------------------

    if (empty($items)) {
      return ['#markup' => $this->t('No nodes found.')];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#title' => $this->t('Recent Content'),
      '#cache' => [
        'tags' => ['node_list'],
        'contexts' => [],
      ],
    ];
  }

}
