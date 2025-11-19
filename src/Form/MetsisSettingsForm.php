<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for the METSIS module.
 */
class MetsisSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'metsis_drupal.admin_config_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'metsis_drupal.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('metsis_drupal.settings');

    $form['#tree'] = TRUE;
    $form['metsis_vertical_tabs'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('METSIS Configuration'),
    ];

    // AJAX wrapper for the whole form.
    $form['#prefix'] = '<div id="metsis-config-ajax-wrapper">';
    $form['#suffix'] = '</div>';

    // Track which tab is open.
    $open_tab = $form_state->get('open_tab');
    if (!$open_tab) {
      $open_tab = 'solr_index';
      $form_state->set('open_tab', $open_tab);
    }

    // Solr and index configuration.
    $form['solr_index'] = [
      '#type' => 'details',
      '#title' => $this->t('Solr and index configuration'),
      '#group' => 'metsis_vertical_tabs',
      '#open' => ($open_tab === 'solr_index'),
      '#ajax' => [
        'callback' => '::ajaxSwitchTab',
        'wrapper' => 'metsis-config-ajax-wrapper',
        'event' => 'summaryToggle',
      ],
      '#attributes' => [
        'data-tab-id' => 'solr_index',
      ],
    ];
    $form['solr_index']['solr_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Solr URL'),
      '#default_value' => $config->get('solr_url'),
    ];

    // Search configuration.
    $form['search_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Search configuration'),
      '#group' => 'metsis_vertical_tabs',
      '#open' => ($open_tab === 'search_config'),
      '#ajax' => [
        'callback' => '::ajaxSwitchTab',
        'wrapper' => 'metsis-config-ajax-wrapper',
        'event' => 'summaryToggle',
      ],
      '#attributes' => [
        'data-tab-id' => 'search_config',
      ],
    ];
    $form['search_config']['search_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Search result limit'),
      '#default_value' => $config->get('search_limit'),
    ];

    // Metsis map app configuration.
    $form['map_app'] = [
      '#type' => 'details',
      '#title' => $this->t('Metsis map app configuration'),
      '#group' => 'metsis_vertical_tabs',
      '#open' => ($open_tab === 'map_app'),
      '#ajax' => [
        'callback' => '::ajaxSwitchTab',
        'wrapper' => 'metsis-config-ajax-wrapper',
        'event' => 'summaryToggle',
      ],
      '#attributes' => [
        'data-tab-id' => 'map_app',
      ],
    ];
    $form['map_app']['map_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Map API Key'),
      '#default_value' => $config->get('map_api_key'),
    ];

    // Bbox map filter configuration.
    $form['bbox_filter'] = [
      '#type' => 'details',
      '#title' => $this->t('Bbox map filter configuration'),
      '#group' => 'metsis_vertical_tabs',
      '#open' => ($open_tab === 'bbox_filter'),
      '#ajax' => [
        'callback' => '::ajaxSwitchTab',
        'wrapper' => 'metsis-config-ajax-wrapper',
        'event' => 'summaryToggle',
      ],
      '#attributes' => [
        'data-tab-id' => 'bbox_filter',
      ],
    ];
    $form['bbox_filter']['bbox_default'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default Bbox'),
      '#default_value' => $config->get('bbox_default'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * AJAX callback for switching tabs.
   */
  public function ajaxSwitchTab(array &$form, FormStateInterface $form_state) {
    // Determine which tab was opened.
    $triggering_element = $form_state->getTriggeringElement();
    if (!empty($triggering_element['#attributes']['data-tab-id'])) {
      $form_state->set('open_tab', $triggering_element['#attributes']['data-tab-id']);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('metsis_drupal.settings')
      ->set('solr_url', $form_state->getValue('solr_url'))
      ->set('search_limit', $form_state->getValue('search_limit'))
      ->set('map_api_key', $form_state->getValue('map_api_key'))
      ->set('bbox_default', $form_state->getValue('bbox_default'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
