<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\metsis_drupal\Utility\MetsisHelper;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\metsis_drupal\MetsisConstants;

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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * MetsisSettingsForm constructor.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $language_manager,
    MetsisHelper $metsis_helper,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($config_factory, $language_manager);
    $this->metsisHelper = $metsis_helper;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
    $container->get('config.factory'),
    $container->get('config.typed'),
    $container->get('metsis_drupal.metsis_helper'),
    $container->get('entity_type.manager')
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
    $config = $this->configFactory()->get('metsis_drupal.settings');
    // Provide a link to the Search API server Solr connection settings.
    $server_storage = $this->entityTypeManager->getStorage('search_api_server');
    /** @var \Drupal\search_api\Entity\Server $server */
    $server = $server_storage->load(MetsisConstants::METSIS_SOLR_SERVER_ID);

    $link = Link::fromTextAndUrl(
    $this->t('Configure Solr connection'),
    Url::fromRoute('entity.search_api_server.edit_form', ['search_api_server' => MetsisConstants::METSIS_SOLR_SERVER_ID])
    )->toString();

    $solr_summary = '';
    if ($server) {

      $solr_config = $server->getBackendConfig()['connector_config'] ?? [];
      $solr_summary = $this->t('<code>@scheme://@host:@port/@context/@core</code>', [
        '@scheme' => $solr_config['scheme'] ?? '',
        '@host' => $solr_config['host'] ?? '',
        '@port' => $solr_config['port'] ?? '',
        '@context' => $solr_config['context'] ?? '',
        '@core' => $solr_config['core'] ?? '',
      ]);
    }

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
    $form['solr_index']['solr_connection'] = [
      '#type' => 'item',
      '#title' => $this->t('Solr Connection'),
      '#markup' => $solr_summary,
      '#description' => $this->t('Solr connection is managed via the Search API server configuration: @link', ['@link' => $link]),
    ];

    // Get a list of collections.
    $collections = $this->metsisHelper->getCollections();
    $form['solr_index']['collections'] = [
      '#title' => $this->t('Configure METSIS collections to include in search.'),
      '#description' => $this->t('Select which collections to include in search (if none are selected, all collections will be included in the search)'),
      '#type' => 'select',
      '#size' => count($collections),
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
      '#min' => 0,
      '#max' => 28,
      '#step' => 1,
    ];

    $form['map_app']['map_default_center_lat'] = [
      '#type' => 'number',
      '#title' => $this->t('Default map center latitude'),
      '#default_value' => $config->get('map_default_center_lat'),
      '#min' => -90,
      '#max' => 90,
      '#step' => 'any',
    ];

    $form['map_app']['map_default_center_lon'] = [
      '#type' => 'number',
      '#title' => $this->t('Default map center longitude'),
      '#default_value' => $config->get('map_default_center_lon'),
      '#min' => -180,
      '#max' => 180,
      '#step' => 'any',
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
    // Check if bokeh_plot_service_url is overridden via settings.php.
    $bokeh_overridden = $config->hasOverrides() && $config->get('bokeh_plot_service_url') !== $config->getRawData()['bokeh_plot_service_url'];
    if ($bokeh_overridden) {
      $form['metsis_services']['bokeh_plot_service_url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Bokeh Plot Service URL'),
        '#default_value' => $config->get('bokeh_plot_service_url'),
        '#disabled' => TRUE,
        '#description' => $this->t('This value has been overridden in settings.php and cannot be changed here.'),
      ];
    }
    else {
      $form['metsis_services']['bokeh_plot_service_url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Bokeh Plot Service URL'),
        '#default_value' => $config->get('bokeh_plot_service_url'),
      ];
    }
    // Check if bokeh_plot_service_url is overridden via settings.php.
    $bokeh_overridden = $config->hasOverrides() && $config->get('bokeh_plot_service_url') !== $config->getRawData()['bokeh_plot_service_url'];
    if ($bokeh_overridden) {
      $form['metsis_services']['feature_type_lookup_service'] = [
        '#type' => 'textfield',
        '#title' => $this->t('featureType lookup Service URL'),
        '#default_value' => $config->get('feature_type_lookup_service'),
        '#disabled' => TRUE,
        '#description' => $this->t('This value has been overridden in settings.php and cannot be changed here.'),
      ];
    }
    else {
      $form['metsis_services']['feature_type_lookup_service'] = [
        '#type' => 'textfield',
        '#title' => $this->t('featureType lookup Service URL'),
        '#default_value' => $config->get('feature_type_lookup_service'),
      ];
    }

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
    $im_config = $this->configFactory->get('metsis_drupal.settings');
    $config = $this->config('metsis_drupal.settings');
    $config
      ->set('map_default_zoom', $form_state->getValue(['map_app', 'map_default_zoom']))
      ->set('map_default_center_lat', $form_state->getValue(['map_app', 'map_default_center_lat']))
      ->set('map_default_center_lon', $form_state->getValue(['map_app', 'map_default_center_lon']))
      ->set('selected_collections', $form_state->getValue(['solr_index', 'collections']));

    // Only set bokeh_plot_service_url if not overridden.
    if (!($im_config->hasOverrides() && $im_config->get('bokeh_plot_service_url') !== $im_config->getRawData()['bokeh_plot_service_url'])) {
      $config->set('bokeh_plot_service_url', $form_state->getValue(['metsis_services', 'bokeh_plot_service_url']));
    }
    if (!($im_config->hasOverrides() && $im_config->get('feature_type_lookup_service') !== $im_config->getRawData()['feature_type_lookup_service'])) {
      $config->set('feature_type_lookup_service',
        $form_state->getValue(['metsis_services', 'feature_type_lookup_service']));
    }

    $this->config('metsis_drupal.settings')->save();
    parent::submitForm($form, $form_state);
  }

}
