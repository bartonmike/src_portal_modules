<?php

namespace Drupal\superfund_blocks\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Module-level settings form for Superfund Blocks.
 *
 * Accessible at: /admin/config/custom-blocks/settings
 * Add any global settings that should apply across all your blocks here.
 */
class SuperfundBlocksSettingsForm extends ConfigFormBase {

  /**
   * Config object name — stores settings in Drupal's config system.
   */
  const CONFIG_NAME = 'superfund_blocks.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'superfund_blocks_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [static::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(static::CONFIG_NAME);

    $form['global_css_class'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Global CSS class'),
      '#description' => $this->t('A CSS class added to all custom block wrappers.'),
      '#default_value' => $config->get('global_css_class') ?? '',
    ];

    $form['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug mode'),
      '#description' => $this->t('When enabled, extra information is shown inside each block (visible to admins only).'),
      '#default_value' => $config->get('debug_mode') ?? FALSE,
    ];

    // Add more global settings here as needed.

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(static::CONFIG_NAME)
      ->set('global_css_class', $form_state->getValue('global_css_class'))
      ->set('debug_mode', (bool) $form_state->getValue('debug_mode'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
