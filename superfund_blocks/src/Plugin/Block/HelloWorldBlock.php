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
 *   id = "superfund_blocks_hello_world",
 *   admin_label = @Translation("Hello World Block"),
 *   category = @Translation("Superfund Blocks"),
 * )
 */
class HelloWorldBlock extends BlockBase implements BlockPluginInterface {

  /**
   * {@inheritdoc}
   *
   * Add per-block configuration fields here.
   * Access saved config via $this->configuration['my_key'].
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);

    $form['greeting'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Greeting message'),
      '#default_value' => $this->configuration['greeting'] ?? $this->t('Hello, World!'),
      '#description' => $this->t('The message to display in this block.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    parent::blockSubmit($form, $form_state);
    $this->configuration['greeting'] = $form_state->getValue('greeting');
  }

  /**
   * {@inheritdoc}
   *
   * Put your custom PHP logic here. Return a render array.
   */
  public function build(): array {
    // -----------------------------------------------------------
    // YOUR CUSTOM PHP LOGIC GOES HERE
    // -----------------------------------------------------------
    $greeting = $this->configuration['greeting'] ?? $this->t('Hello, World!');
    $timestamp = \Drupal::time()->getRequestTime();
    $formatted_time = \Drupal::service('date.formatter')->format($timestamp, 'short');

    $output = '<p>' . $this->t('@greeting', ['@greeting' => $greeting]) . '</p>';
    $output .= '<p>' . $this->t('Current time: @time', ['@time' => $formatted_time]) . '</p>';
    // -----------------------------------------------------------

    return [
      '#type' => 'markup',
      '#markup' => $output,
      // Uncomment to prevent caching (use sparingly):
      // '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Override cache settings per block if needed.
   */
  public function getCacheMaxAge(): int {
    // Return 0 to disable caching, or a number of seconds.
    return parent::getCacheMaxAge();
  }

}
