<?php

declare(strict_types=1);

namespace Drupal\dynamic_landing_pages\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for the Dynamic Landing Pages module.
 */
class DynamicLandingPagesSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dynamic_landing_pages_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dynamic_landing_pages.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dynamic_landing_pages.settings');

    $form['naming_authority'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Naming authority'),
      '#description'   => $this->t(
        'The naming authority prefix prepended to the UUID path segment to form the full Solr document identifier. For example, <code>no.met.adc</code> maps <code>/dataset/my-uuid</code> to the Solr identifier <code>no.met.adc:my-uuid</code>.'
      ),
      '#default_value' => $config->get('naming_authority'),
      '#required'      => TRUE,
      '#maxlength'     => 255,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('dynamic_landing_pages.settings')
      ->set('naming_authority', trim((string) $form_state->getValue('naming_authority')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
