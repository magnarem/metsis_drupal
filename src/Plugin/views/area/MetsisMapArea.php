<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\views\area;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Attribute\ViewsArea;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a Views area plugin to render the Map App.
 *
 * @ingroup views_area_handlers
 */
#[ViewsArea('metsis_map')]
class MetsisMapArea extends AreaPluginBase {

  /**
   * Supported map projections.
   */
  private const SUPPORTED_PROJECTIONS = [
    'EPSG:4326',
    'EPSG:3857',
    'EPSG:32661',
    'EPSG:32761',
  ];

  /**
   * Module configuration.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $moduleConfig;

  /**
   * Constructs a MetsisMapArea instance.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->moduleConfig = $config_factory->get('metsis_drupal.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defineOptions() {
    $options = parent::defineOptions();
    $options['map_app_settings'] = ['default' => '{}'];
    $options['map_height'] = ['default' => 400];
    $options['show_all_wms'] = ['default' => TRUE];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['map_app_settings'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Map App Settings'),
      '#description' => $this->t('Provide JSON configuration for the Map App.'),
      '#default_value' => is_string($this->options['map_app_settings']) ? $this->options['map_app_settings'] : json_encode($this->options['map_app_settings'], JSON_PRETTY_PRINT),
    ];
    $form['map_height'] = [
      '#type' => 'number',
      '#title' => $this->t('Map height in pixels'),
      '#default_value' => $this->options['map_height'],
      '#min' => 150,
      '#max' => 1000,
      '#required' => TRUE,
    ];
    $form['show_all_wms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the visualise all WMS products control'),
      '#default_value' => $this->options['show_all_wms'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state) {
    parent::validateOptionsForm($form, $form_state);
    $settings = trim((string) $form_state->getValue(['options', 'map_app_settings']));
    if ($settings !== '') {
      $decoded = json_decode($settings);
      if (json_last_error() !== JSON_ERROR_NONE || !$decoded instanceof \stdClass) {
        $form_state->setError($form['map_app_settings'], $this->t('Map settings must be a JSON object.'));
      }
      elseif ((isset($decoded->features) && !$decoded->features instanceof \stdClass)
        || (isset($decoded->mapOptions) && !$decoded->mapOptions instanceof \stdClass)) {
        $form_state->setError($form['map_app_settings'], $this->t('The features and mapOptions settings must be JSON objects.'));
      }
    }
    $height = filter_var($form_state->getValue(['options', 'map_height']), FILTER_VALIDATE_INT);
    if ($height === FALSE || $height < 150 || $height > 1000) {
      $form_state->setError($form['map_height'], $this->t('Map height must be an integer between 150 and 1000.'));
    }
    else {
      $form_state->setValue(['options', 'map_height'], $height);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE) {
    if ($empty && empty($this->options['empty'])) {
      return [];
    }
    $map_app_settings = $this->normalizeMapAppSettings($this->options['map_app_settings'] ?? []);
    $configured_projection = (string) $this->moduleConfig->get('map_default_projection');
    if (!in_array($configured_projection, self::SUPPORTED_PROJECTIONS, TRUE)) {
      $configured_projection = 'EPSG:3857';
    }

    $mount_id = Html::getId('metsis-map-' . $this->view->id() . '-' . $this->view->current_display . '-' . $this->view->dom_id . '-' . ($this->options['id'] ?? 'map'));
    $selector = '#' . $mount_id;
    $map_app_settings['mapOptions']['height'] = (int) $this->options['map_height'];
    $map_app_settings['features']['showAllWms'] = (bool) $this->options['show_all_wms'];
    $map_app_settings['features']['searchViewKey'] = $this->view->dom_id;
    $map_app_settings['features']['defaultProjection'] ??= $configured_projection;

    // Render the Map App container and attach the necessary libraries.
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $mount_id,
        'class' => ['metsis-search-map'],
      ],
      '#attached' => [
        'library' => [
          'metsis_drupal/metsis_map',
        ],
        'drupalSettings' => [
          'metsis_drupal' => [
            'map_app' => [
              'instances' => [$selector => $map_app_settings],
            ],
          ],
        ],
      ],
    ];
    CacheableMetadata::createFromObject($this->moduleConfig)->applyTo($build);

    return $build;
  }

  /**
   * Normalizes map app settings from the Views option.
   *
   * @param mixed $settings
   *   Map app settings from Views options.
   *
   * @return array
   *   Normalized settings.
   */
  private function normalizeMapAppSettings($settings): array {
    if (is_array($settings)) {
      return $settings;
    }

    if ($settings === '') {
      return [];
    }
    if (is_string($settings)) {
      $object = json_decode($settings);
      $decoded = json_decode($settings, TRUE);
      if ($object instanceof \stdClass && is_array($decoded)
        && (!isset($object->features) || $object->features instanceof \stdClass)
        && (!isset($object->mapOptions) || $object->mapOptions instanceof \stdClass)) {
        return $decoded;
      }
    }

    throw new \UnexpectedValueException('Stored METSIS map settings must contain valid JSON configuration.');
  }

}
