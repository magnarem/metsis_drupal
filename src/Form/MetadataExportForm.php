<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\metsis_drupal\Service\MetadataExportService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Form for exporting metadata in configured formats.
 */
final class MetadataExportForm extends FormBase {

  /**
   * Allowed Solr id pattern.
   */
  private const SOLR_ID_PATTERN = '/^[A-Za-z0-9_.:-]+$/';

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
    $form['#prefix'] = '<div id="metsis-export-form">';
    $form['#suffix'] = '</div>';

    $form['solr_id'] = [
      '#type' => 'hidden',
      '#value' => $id,
    ];

    if ($id === '') {
      $form['export'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Missing dataset identifier for export.'),
      ];
      return $form;
    }

    if (!$this->isValidIdentifier($id)) {
      $form['export'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Invalid dataset identifier supplied for export.'),
      ];
      return $form;
    }

    if (!$form_state->has('mmd')) {
      $mmd = $this->metadataExportService->getMmd($id);
      if ($mmd === NULL || $mmd === '') {
        $form['export'] = [
          '#type' => 'markup',
          '#markup' => $this->t('The export service is not yet available for this dataset.'),
        ];
        return $form;
      }
      $form_state->set('mmd', $mmd);
    }

    $form['export'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Export metadata'),
    ];

    $options = $this->metadataExportService->getEnabledExportOptions();
    if ($options === []) {
      $form['export']['message'] = [
        '#type' => 'markup',
        '#markup' => $this->t('No export formats are currently enabled.'),
      ];
      return $form;
    }

    $selected = $form_state->getValue('list');
    $default_export = is_string($selected) && isset($options[$selected])
      ? $selected
      : (string) array_key_first($options);

    $descriptions = $this->metadataExportConfig->get('export_types_descriptions') ?? [];
    $description = '';
    if (is_array($descriptions) && isset($descriptions[$default_export])) {
      $description = (string) $descriptions[$default_export];
    }

    $form['export']['list'] = [
      '#type' => 'select',
      '#options' => $options,
      '#default_value' => $default_export,
      '#description' => $description,
      '#ajax' => [
        'wrapper' => 'metsis-export-form',
        'callback' => '::changeExportTypeCallback',
        'disable-refocus' => TRUE,
      ],
    ];

    $form['export']['actions'] = [
      '#type' => 'actions',
    ];
    $form['export']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export @type', ['@type' => (string) ($options[$default_export] ?? $default_export)]),
    ];

    return $form;
  }

  /**
   * AJAX callback for export type changes.
   */
  public function changeExportTypeCallback(array $form, FormStateInterface $form_state): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();

    $id = (string) ($values['solr_id'] ?? '');
    $export_type = (string) ($values['list'] ?? '');

    if ($id === '' || $export_type === '') {
      $this->messenger()->addError($this->t('Missing export parameters.'));
      return;
    }

    if (!$this->isValidIdentifier($id)) {
      $this->messenger()->addError($this->t('Invalid dataset identifier supplied for export.'));
      return;
    }

    $content = $this->metadataExportService->exportById($id, $export_type);
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

  /**
   * Validates Solr identifier format.
   */
  private function isValidIdentifier(string $id): bool {
    return preg_match(self::SOLR_ID_PATTERN, $id) === 1;
  }

}
