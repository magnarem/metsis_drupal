<?php

declare(strict_types=1);

namespace Drupal\nbs_extensions\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Htmx\Htmx;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\metsis_drupal\Service\SolrDocumentLoader;
use Drupal\nbs_extensions\Service\NetCDFOnDemandService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides the NetCDF on-demand request form.
 */
final class NetCDFOnDemandForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    protected readonly AccountProxyInterface $currentUser,
    protected readonly SolrDocumentLoader $documentLoader,
    protected readonly NetCDFOnDemandService $netcdfOnDemandService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $form = new self(
      $container->get('current_user'),
      $container->get('metsis_drupal.solr_document_loader'),
      $container->get('nbs_extensions.netcdf_on_demand'),
    );
    $form->setRequestStack($container->get('request_stack'));
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'nbs_extensions_netcdf_on_demand';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $datasetId = NULL, bool $modal = FALSE): array {
    if (!$this->currentUser->isAuthenticated() || !$this->currentUser->hasPermission('access content')) {
      throw new AccessDeniedHttpException();
    }
    if ($datasetId === NULL || $datasetId === '') {
      throw new BadRequestHttpException('A dataset identifier is required.');
    }

    $document = $this->documentLoader->loadDocumentById($datasetId, [
      'metadata_identifier',
      'title',
    ]);
    if ($document === NULL) {
      throw new NotFoundHttpException('Metadata document not found.');
    }

    $title = $this->firstStringValue($document['title'] ?? NULL);
    $productId = $title !== ''
      ? $title
      : ($this->firstStringValue($document['metadata_identifier'] ?? NULL) ?: $datasetId);
    $form_state->set('product_id', $productId);

    $form['#cache'] = ['max-age' => 0, 'contexts' => ['user', 'url.path']];
    $form['#attributes']['id'] = 'nbs-netcdf-on-demand-form';
    $route = $modal ? 'nbs_extensions.netcdf_modal_request' : 'nbs_extensions.netcdf_on_demand';
    $form['#action'] = Url::fromRoute($route, ['datasetId' => $datasetId])->toString();
    $form['#attached']['library'][] = 'core/drupal.states';
    if (!$modal) {
      $form['messages'] = [
        '#type' => 'status_messages',
        '#weight' => -100,
      ];
    }
    $form['message'] = [
      '#type' => 'item',
      '#markup' => $this->t(
        'A NetCDF file will be generated for <strong>@title</strong>. A download link will be sent to <em>@email</em>.',
        [
          '@title' => $title !== '' ? $title : $productId,
          '@email' => $this->currentUser->getEmail() ?? '',
        ],
      ),
    ];
    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I confirm that I want to request a CF-NetCDF file.'),
      '#required' => TRUE,
    ];

    $result = $form_state->get('request_result');
    if (is_array($result)) {
      $success = $result['success'] ?? FALSE;
      $message = $success
        ? (string) ($result['message'] ?? $this->t('Your NetCDF request was accepted.'))
        : $this->getFailureMessage($result);
      $form['result'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['messages', $success ? 'messages--status' : 'messages--error'],
          'role' => $success ? 'status' : 'alert',
        ],
        'message' => [
          '#plain_text' => $message,
        ],
      ];
    }

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send request'),
      '#disabled' => is_array($result) && ($result['success'] ?? FALSE),
    ];
    if (!is_array($result) || !($result['success'] ?? FALSE)) {
      $form['submit']['#states'] = [
        'disabled' => [
          ':input[name="confirm"]' => ['checked' => FALSE],
        ],
      ];
    }

    $htmx = (new Htmx())
      ->post(Url::fromRoute($route, ['datasetId' => $datasetId]))
      ->target($modal ? 'closest .metsis-metadata-dialog__body' : '#nbs-netcdf-on-demand-form')
      ->swap($modal ? 'innerHTML' : 'outerHTML')
      ->onlyMainContent();
    if (!$modal) {
      $htmx->select('#nbs-netcdf-on-demand-form');
    }
    $htmx->applyTo($form);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser->isAuthenticated() || !$this->currentUser->hasPermission('access content')) {
      throw new AccessDeniedHttpException();
    }
    $result = $form_state->get('request_result');
    if (is_array($result) && ($result['success'] ?? FALSE)) {
      return;
    }
    $email = $this->currentUser->getEmail();
    if ($email === NULL || trim($email) === '') {
      $form_state->set('request_result', [
        'success' => FALSE,
        'error' => 'missing_email',
      ]);
    }
    else {
      $form_state->set(
        'request_result',
        $this->netcdfOnDemandService->request(
          (string) $form_state->get('product_id'),
          $email,
        ),
      );
    }

    $form_state->setRebuild();
    if ($this->isHtmxRequest()) {
      $form_state->disableRedirect();
    }
    else {
      $result = $form_state->get('request_result');
      if ($result['success'] ?? FALSE) {
        $this->messenger()->addStatus($result['message'] ?? $this->t('Your NetCDF request was accepted.'));
      }
      else {
        $this->messenger()->addError($this->getFailureMessage(is_array($result) ? $result : []));
      }
    }
  }

  /**
   * Returns a user-facing message for a failed request.
   *
   * @param array<string, mixed> $result
   *   The backend service result.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The translated message.
   */
  private function getFailureMessage(array $result): TranslatableMarkup {
    return match ($result['error'] ?? '') {
      'missing_email' => $this->t('Your account does not have an email address. Add one before requesting a NetCDF file.'),
      'not_configured' => $this->t('The NetCDF service endpoint has not been configured.'),
      'http_error' => $this->t('The NetCDF service returned HTTP status @status.', [
        '@status' => (string) ($result['status'] ?? ''),
      ]),
      default => $this->t('The NetCDF request could not be sent. Please try again later.'),
    };
  }

  /**
   * Returns the first scalar string from a Solr field value.
   *
   * @param mixed $value
   *   The Solr field value.
   *
   * @return string
   *   The field value, or an empty string.
   */
  private function firstStringValue(mixed $value): string {
    if (is_array($value)) {
      $value = reset($value);
    }
    return is_scalar($value) ? trim((string) $value) : '';
  }

}
