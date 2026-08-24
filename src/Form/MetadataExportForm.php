<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Form;

use Drupal\Core\Url;
use Drupal\Core\Htmx\Htmx;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\metsis_drupal\Service\MetadataExportService;
use Drupal\metsis_drupal\Utility\MetsisSolrUtilities;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Form for exporting metadata in configured formats.
 */
final class MetadataExportForm extends FormBase {

  /**
   * Metadata export service.
   *
   * @var \Drupal\metsis_drupal\Service\MetadataExportService
   */
  protected MetadataExportService $metadataExportService;

  /**
   * Metadata export config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $metadataExportConfig;

  /**
   * Constructs the form.
   */
  public function __construct(
    MetadataExportService $metadata_export_service,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->metadataExportService = $metadata_export_service;
    $this->metadataExportConfig = $config_factory->get('metsis_drupal.metadata_export');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('metsis_drupal.metadata_export_service'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'metsis_drupal.metadata_export.form';
  }

  /**
   * Build metadata export form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $id
   *   Solr id for the dataset/document.
   *
   * @return array
   *   Form render array.
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $id = ''): array {

    // Return a message if no ID is provided.
    if ($id === '') {
      $form['export'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Missing dataset identifier for export.'),
      ];
      return $form;
    }
    // Convert to Solr ID format and validate.
    if (!empty($id)) {
      $id = MetsisSolrUtilities::toSolrId($id);
    }
    // Validate the ID format before proceeding.
    if (!MetsisSolrUtilities::isValidIdentifier($id)) {
      $form['export'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Invalid dataset identifier supplied for export.'),
      ];
      return $form;
    }

    if (!$form_state->has('solr_id')) {
      $form_state->set('solr_id', $id);
    }
    $solr_id = (string) ($form_state->get('solr_id') ?? '');

    // Get the current route to determine if we are on a landing page and adjust the form action accordingly.
    $route = Url::fromRouteMatch($this->getRouteMatch());
    if ($route->getRouteName() === 'dynamic_landing_pages.landing_page') {
      $form['#action'] = Url::fromRoute('metsis_drupal.metadata_export_form', ['id' => $solr_id])->toString();
    }

    if (!$form_state->has('mmd') && $solr_id !== '') {
      $mmd = $this->metadataExportService->getMmd($solr_id);
      if ($mmd === NULL || $mmd === '') {
        $form['export'] = [
          '#type' => 'markup',
          '#markup' => $this->t('No MMD metadata available for dataset with identifier: @id', ['@id' => $solr_id]),
        ];
        return $form;
      }
      $form_state->set('mmd', $mmd);
    }
    $mmd = (string) ($form_state->get('mmd') ?? '');

    $form['export'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Export metadata'),
    ];

    // Persist values across HTMX requests in case the triggering element
    // only posts partial form data.
    $form['export']['solr_id'] = [
      '#type' => 'hidden',
      '#default_value' => $solr_id,
    ];
    $form['export']['mmd'] = [
      '#type' => 'hidden',
      '#default_value' => $mmd,
    ];

    // Cache options and default_export in form-state storage
    // to avoid recalculating on rebuild.
    if (!$form_state->has('export_options')) {
      $options = $this->metadataExportService->getEnabledExportOptions();
      if ($options === []) {
        $form['export']['message'] = [
          '#type' => 'markup',
          '#markup' => $this->t('No export formats are currently enabled.'),
        ];
        return $form;
      }
      $form_state->set('export_options', $options);
    }
    else {
      $options = $form_state->get('export_options');
    }

    if (!$form_state->has('default_export')) {
      $selected = $form_state->getValue('list');
      $default_export = is_string($selected) && isset($options[$selected])
        ? $selected
        : (string) array_key_first($options);
      $form_state->set('default_export', $default_export);
    }
    else {
      $default_export = (string) $form_state->get('default_export');
    }

    $descriptions = [];
    if ($this->metadataExportConfig !== NULL) {
      $descriptions = $this->metadataExportConfig->get('export_types_descriptions') ?? [];
    }
    $selected_value = (string) ($form_state->getValue('list') ?? $default_export);
    if (!isset($options[$selected_value])) {
      $selected_value = $default_export;
    }
    $description = '';
    if (is_array($descriptions) && isset($descriptions[$selected_value])) {
      $description = (string) $descriptions[$selected_value];
    }

    $form['export']['list'] = [
      '#type' => 'select',
      '#options' => $options,
      '#title' => $this->t('Select export format'),
      '#description' => $this->t('Select the format in which you want to export the metadata.'),
      '#default_value' => $default_export,
    ];

    $form['export']['spinner-container'] = [
      '#id' => 'spinner',
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'spinner-container',
          'htmx-indicator',
        ],
      ],
      '#allowed_tags' => ['div'],
    ];
    $form['export']['spinner-container']['indicator'] = [
      '#type' => 'icon',
      '#pack_id' => 'metsis_drupal_spinners',
      '#icon_id' => 'oval',
      '#settings' => [
        'stroke' => '#0074D9',
        'height' => '32',
        'width' => '32',
      ],
    ];

    $form['export']['dynamic'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'metsis-export-dynamic',
      ],
    ];
    $form['export']['dynamic']['description'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'metsis-export-description',
      ],
      'content' => [
        '#markup' => $description,
      ],
    ];

    $form['export']['dynamic']['actions'] = [
      '#type' => 'actions',
    ];
    $current_selected = $form_state->getValue('list') ?? $default_export;
    $form['export']['dynamic']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export @type', ['@type' => (string) ($options[$current_selected] ?? $current_selected)]),
      '#attributes' => [
        'id' => 'metsis-export-submit',
      ],
    ];
    (new Htmx())
      ->post()
      ->onlyMainContent()
      ->include('closest form')
      ->target('#metsis-export-dynamic')
      ->select('#metsis-export-dynamic')
      ->swap('outerHTML')
      ->indicator('#spinner')
      ->applyTo($form['export']['list']);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();

    $id = (string) ($values['solr_id'] ?? $form_state->get('solr_id') ?? '');
    $export_type = (string) ($values['list'] ?? '');
    $mmd = (string) ($values['mmd'] ?? $form_state->get('mmd') ?? '');

    if ($id === '' || $export_type === '') {
      $this->messenger()->addError($this->t('Missing export parameters.'));
      return;
    }

    if (!MetsisSolrUtilities::isValidIdentifier($id)) {
      $this->messenger()->addError($this->t('Invalid dataset identifier supplied for export.'));
      return;
    }

    $content = $mmd !== ''
      ? $this->metadataExportService->exportByMmd($mmd, $export_type)
      : $this->metadataExportService->exportById($id, $export_type);
    if ($content === NULL || $content === '') {
      $this->messenger()->addError($this->t('The export service is not available for this dataset or format.'));
      return;
    }

    $response = new Response();
    $response->headers->set('Content-Type', 'text/xml');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $id . '_' . $export_type . '.xml"');
    $response->setContent($content);

    $form_state->setResponse($response);
  }

}
