<?php

declare(strict_types=1);

namespace Drupal\nbs_extensions\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Htmx\Htmx;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\user\Form\UserLoginForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Scopes core username/password login to the NetCDF dialog.
 */
final class NetCDFModalLoginForm extends FormBase {

  /**
   * Constructs the scoped adapter without subclassing core's internal form.
   */
  public function __construct(
    protected readonly UserLoginForm $loginForm,
    protected readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $form = new self(
      $container->get('class_resolver')->getInstanceFromDefinition(UserLoginForm::class),
      $container->get('renderer'),
    );
    $form->setRequestStack($container->get('request_stack'));
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'nbs_extensions_netcdf_modal_login';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $datasetId = NULL): array {
    if ($datasetId === NULL || $datasetId === '') {
      throw new BadRequestHttpException('A dataset identifier is required.');
    }
    $form = $this->loginForm->buildForm($form, $form_state);
    $form_state->set('dataset_id', $datasetId);
    $url = Url::fromRoute('nbs_extensions.netcdf_modal_login', ['datasetId' => $datasetId]);
    $form['#action'] = $url->toString();
    $form['#cache']['max-age'] = 0;
    $form['#cache']['contexts'][] = 'user';
    (new Htmx())
      ->post($url)
      ->target('closest .metsis-metadata-dialog__body')
      ->swap('innerHTML')
      ->onlyMainContent()
      ->applyTo($form);
    return $form;
  }

  /**
   * Delegates authentication and flood checks to core without modification.
   */
  public function validateAuthentication(array &$form, FormStateInterface $form_state): void {
    $this->loginForm->validateAuthentication($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateFinal(array &$form, FormStateInterface $form_state): void {
    $this->loginForm->validateFinal($form, $form_state);

    // Core's flood denial is a bare 403 page, which HTMX would not swap.
    $response = $form_state->getResponse();
    if ($this->isHtmxRequest() && $response && $form_state->get('flood_control_triggered')) {
      $build = [
        '#type' => 'container',
        '#attributes' => ['id' => 'nbs-netcdf-modal-content', 'role' => 'alert'],
        'message' => [
          '#markup' => $this->t('Too many failed login attempts. Login is temporarily blocked. Try again later or <a href=":url">request a new password</a>.', [
            ':url' => Url::fromRoute('user.pass')->toString(),
          ]),
        ],
      ];
      $response->setContent((string) $this->renderer->renderInIsolation($build));
      $response->setStatusCode(200);
      $response->headers->set('Cache-Control', 'private, no-store');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->loginForm->submitForm($form, $form_state);
    if (!$form_state->get('uid')) {
      return;
    }
    if ($this->isHtmxRequest()) {
      $form_state->disableRedirect();
      // Fetch the protected form only after the new session reaches the client.
      $form_state->setResponse(new Response('', 204, [
        'HX-Trigger' => 'nbsNetcdfAuthenticated',
        'Cache-Control' => 'private, no-store',
      ]));
    }
    else {
      $form_state->setRedirect('nbs_extensions.netcdf_on_demand', [
        'datasetId' => $form_state->get('dataset_id'),
      ]);
    }
  }

}
