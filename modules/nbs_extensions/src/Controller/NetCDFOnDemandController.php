<?php

declare(strict_types=1);

namespace Drupal\nbs_extensions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Htmx\Htmx;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\nbs_extensions\Form\NetCDFModalLoginForm;
use Drupal\nbs_extensions\Form\NetCDFOnDemandForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the NetCDF on-demand request form.
 */
final class NetCDFOnDemandController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected readonly FormBuilderInterface $nbsFormBuilder,
    protected readonly AccountProxyInterface $account,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('form_builder'), $container->get('current_user'));
  }

  /**
   * Returns the form for the requested dataset.
   *
   * @param string $datasetId
   *   The Solr document identifier.
   *
   * @return array
   *   The request form render array.
   */
  public function content(string $datasetId): array {
    return $this->nbsFormBuilder->getForm(
      NetCDFOnDemandForm::class,
      $datasetId,
    );
  }

  /**
   * Opens the shared dialog with the appropriate session-specific form.
   */
  public function modal(string $datasetId): array {
    return [
      '#theme' => 'metsis_metadata_document_dialog',
      '#title' => $this->t('NetCDF on demand'),
      '#content' => $this->account->isAuthenticated()
        ? $this->request($datasetId)
        : $this->login($datasetId),
      '#attached' => [
        'library' => ['nbs_extensions/netcdf_modal'],
      ],
      '#cache' => ['max-age' => 0, 'contexts' => ['user', 'url.path']],
    ];
  }

  /**
   * Returns the scoped core login form and authenticated continuation.
   */
  public function login(string $datasetId): array {
    $build = $this->fragment($this->nbsFormBuilder->getForm(NetCDFModalLoginForm::class, $datasetId));
    $build['continuation'] = [
      '#type' => 'container',
      '#attributes' => ['aria-hidden' => 'true'],
    ];
    (new Htmx())
      ->get(Url::fromRoute('nbs_extensions.netcdf_modal_request', ['datasetId' => $datasetId]))
      ->trigger('nbsNetcdfAuthenticated from:body')
      ->target('closest .metsis-metadata-dialog__body')
      ->swap('innerHTML')
      ->onlyMainContent()
      ->applyTo($build['continuation']);
    return $build;
  }

  /**
   * Returns the protected request fragment in a new authenticated request.
   */
  public function request(string $datasetId): array {
    return $this->fragment($this->nbsFormBuilder->getForm(NetCDFOnDemandForm::class, $datasetId, TRUE));
  }

  /**
   * Wraps forms and their validation messages for modal body replacement.
   */
  private function fragment(array $form): array {
    return [
      '#type' => 'container',
      '#attributes' => ['id' => 'nbs-netcdf-modal-content'],
      'form' => $form,
      '#attached' => [
        'library' => ['nbs_extensions/netcdf_modal'],
      ],
      '#cache' => ['max-age' => 0, 'contexts' => ['user', 'url.path']],
    ];
  }

}
