<?php

declare(strict_types=1);

namespace Drupal\nbs_extensions\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for NBS Extensions.
 */
final class NbsExtensionsSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'nbs_extensions_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['nbs_extensions.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['netcdf_ondemand_service_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('NetCDF on-demand service endpoint'),
      '#description' => $this->t('The HTTPS or HTTP URL used to submit NetCDF generation requests.'),
      '#default_value' => $this->config('nbs_extensions.settings')->get('netcdf_ondemand_service_endpoint') ?? '',
      '#required' => TRUE,
      '#maxlength' => 2048,
      '#element_validate' => [[static::class, 'validateEndpoint']],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Validates that the configured endpoint uses HTTP or HTTPS.
   */
  public static function validateEndpoint(array &$element, FormStateInterface $form_state, array &$complete_form): void {
    $endpoint = trim((string) $element['#value']);
    $parts = parse_url($endpoint);
    if (
      !is_array($parts)
      || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], TRUE)
      || empty($parts['host'])
    ) {
      $form_state->setError($element, t('Enter a valid HTTP or HTTPS endpoint URL.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('nbs_extensions.settings')
      ->set(
        'netcdf_ondemand_service_endpoint',
        trim((string) $form_state->getValue('netcdf_ondemand_service_endpoint')),
      )
      ->save();

    parent::submitForm($form, $form_state);
  }

}
