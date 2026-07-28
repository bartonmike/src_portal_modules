<?php

namespace Drupal\superfund_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\superfund_import\Service\SuperfundImportService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Controller for manually triggering the Superfund import.
 */
class SuperfundImportController extends ControllerBase {

  public function __construct(
    protected SuperfundImportService $importer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('superfund_import.importer'),
    );
  }

  /**
   * Runs the import immediately and redirects back to the settings page.
   */
  public function runNow(): RedirectResponse {
    $result = $this->importer->run();

    if ($result['success']) {
      \Drupal::state()->set('superfund_import.last_run', \Drupal::time()->getRequestTime());
      $this->messenger()->addStatus(
        $this->t('Import complete: @tables table(s), @rows row(s) inserted.', [
          '@tables' => $result['tables'],
          '@rows'   => $result['rows'],
        ])
      );
    }
    else {
      $this->messenger()->addError(
        $this->t('Import failed: @error', ['@error' => $result['error']])
      );
    }

    return new RedirectResponse(Url::fromRoute('superfund_import.settings')->toString());
  }

}
