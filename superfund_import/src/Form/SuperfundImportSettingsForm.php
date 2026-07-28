<?php

namespace Drupal\superfund_import\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin settings form for Superfund Import.
 */
class SuperfundImportSettingsForm extends ConfigFormBase {

  /**
   * Drupal state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->state = $container->get('state');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['superfund_import.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'superfund_import_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config   = $this->config('superfund_import.settings');
    $last_run = $this->state->get('superfund_import.last_run', 0);

    $form['zip_url'] = [
      '#type'          => 'url',
      '#title'         => $this->t('ZIP file URL'),
      '#description'   => $this->t('Full URL to the ZIP archive containing CSV files. Each CSV named <code>example.csv</code> will be imported into the <code>superfund_example</code> table.'),
      '#default_value' => $config->get('zip_url'),
      '#required'      => TRUE,
      '#maxlength'     => 2048,
    ];

    $form['cron_interval'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Import frequency'),
      '#description'   => $this->t('How often Drupal cron should trigger a new import.'),
      '#default_value' => $config->get('cron_interval') ?: 86400,
      '#options'       => [
        3600   => $this->t('Every hour'),
        21600  => $this->t('Every 6 hours'),
        43200  => $this->t('Every 12 hours'),
        86400  => $this->t('Daily'),
        604800 => $this->t('Weekly'),
      ],
    ];

    $form['status'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Status'),
    ];

    $form['status']['last_run'] = [
      '#markup' => $last_run
        ? $this->t('Last successful run: <strong>@time</strong>', ['@time' => \Drupal::service('date.formatter')->format($last_run, 'medium')])
        : $this->t('No import has run yet.'),
    ];

    $form['status']['run_now'] = [
      '#type'  => 'link',
      '#title' => $this->t('▶ Run import now'),
      '#url'   => \Drupal\Core\Url::fromRoute('superfund_import.run_now'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $url = $form_state->getValue('zip_url');
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      $form_state->setErrorByName('zip_url', $this->t('Please enter a valid URL.'));
    }
    if (!str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '.zip')) {
      $form_state->setErrorByName('zip_url', $this->t('The URL must point to a .zip file.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('superfund_import.settings')
      ->set('zip_url', $form_state->getValue('zip_url'))
      ->set('cron_interval', (int) $form_state->getValue('cron_interval'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
