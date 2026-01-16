<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\metsis_drupal\Utility\MetsisHelper;
use Drupal\Core\Config\TypedConfigManagerInterface;

/**
 * Configuration form for the METSIS module.
 */
class MetsisSettingsForm extends ConfigFormBase {

  /**
   * The Metsis helper service.
   *
   * @var \Drupal\metsis_drupal\Utility\MetsisHelper
   */
  protected $metsisHelper;

  /**
   * MetsisSettingsForm constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $language_manager, MetsisHelper $metsis_helper) {
    parent::__construct($config_factory, $language_manager);
    $this->metsisHelper = $metsis_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
    $container->get('config.factory'),
    $container->get('config.typed'),
    $container->get('metsis_drupal.metsis_helper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'metsis_drupal_settings_form';
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string>
   *   The config names.
   */
  protected function getEditableConfigNames(): array {
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

    // Get a list of collections.
    $collections = $this->metsisHelper->getCollections();
    $form['solr_index']['collections'] = [
      '#title' => $this->t('Configure METSIS collections to include in search.'),
      '#description' => $this->t('Select which collections to include in search (if none are selected, all collections will be included in the search)'),
      '#type' => 'select',
      '#size' => 10,
      '#options' => $collections,
      '#multiple' => TRUE,
      '#default_value' => $config->get('selected_collections'),
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
    $form['map_app']['map_default_zoom'] = [
      '#type' => 'number',
      '#title' => $this->t('Default map zoom level'),
      '#default_value' => $config->get('map_default_zoom'),
    ];

    $form['map_app']['map_default_center_lat'] = [
      '#type' => 'number',
      '#title' => $this->t('Default map center latitude'),
      '#default_value' => $config->get('map_default_center_lat'),
    ];

    $form['map_app']['map_default_center_lon'] = [
      '#type' => 'number',
      '#title' => $this->t('Default map center longitude'),
      '#default_value' => $config->get('map_default_center_lon'),
    ];

    // METSIS Services configuration.
    $form['metsis_services'] = [
      '#type' => 'details',
      '#title' => $this->t('METSIS services configuration'),
      '#group' => 'metsis_vertical_tabs',
      '#open' => ($open_tab === 'metsis_services'),
      '#ajax' => [
        'callback' => '::ajaxSwitchTab',
        'wrapper' => 'metsis-config-ajax-wrapper',
        'event' => 'summaryToggle',
      ],
      '#attributes' => [
        'data-tab-id' => 'metsis_services',
      ],
    ];

    $form['metsis_services']['plot_service_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Plot Service URL'),
      '#default_value' => $config->get('plot_service_url'),
    ];

    // Build the rest of the form.
    $form = parent::buildForm($form, $form_state);
    return $form;
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
      ->set('map_default_zoom', $form_state->getValue(['map_app', 'map_default_zoom']))
      ->set('map_default_center_lat', $form_state->getValue(['map_app', 'map_default_center_lat']))
      ->set('map_default_center_lon', $form_state->getValue(['map_app', 'map_default_center_lon']))
      ->set('plot_service_url', $form_state->getValue('plot_service_url'))
      ->set('selected_collections', $form_state->getValue(['solr_index', 'collections']))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
