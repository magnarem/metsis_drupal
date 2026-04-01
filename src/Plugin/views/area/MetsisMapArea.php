<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\views\area;

use Drupal\Core\Config\ConfigFactoryInterface;
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
    $options['map_app_settings'] = ['default' => []];
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
      '#default_value' => json_encode($this->options['map_app_settings'], JSON_PRETTY_PRINT),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE) {
    $map_app_settings = $this->normalizeMapAppSettings($this->options['map_app_settings'] ?? []);
    $configured_projection = (string) $this->moduleConfig->get('map_default_projection');
    $configured_mount_selectors = (string) $this->moduleConfig->get('map_mount_selectors');
    if (!in_array($configured_projection, self::SUPPORTED_PROJECTIONS, TRUE)) {
      $configured_projection = 'EPSG:3857';
    }

    $mount_selectors = $this->extractMountSelectors($configured_mount_selectors, $map_app_settings);

    // Render the Map App container and attach the necessary libraries.
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'metsis-map-app',
        'class' => ['metsis-search-map'],
      ],
      '#attached' => [
        'library' => [
          'metsis_drupal/metsis_map',
        ],
        'drupalSettings' => [
          'mapApp' => $map_app_settings,
          'metsis_drupal' => [
            'map_app' => [
              'default_projection' => $configured_projection,
              'mount_selectors' => $mount_selectors,
            ],
          ],
        ],
      ],
    ];

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

    if (is_string($settings) && $settings !== '') {
      $decoded = json_decode($settings, TRUE);
      if (is_array($decoded)) {
        return $decoded;
      }
    }

    return [];
  }

  /**
   * Extracts optional mount selectors from map app settings.
   *
   * @param string $configured_selectors
   *   Configured selectors from module settings.
   * @param array $map_app_settings
   *   Map app settings.
   *
   * @return array
   *   List of selectors where the app should mount.
   */
  private function extractMountSelectors(string $configured_selectors, array $map_app_settings): array {
    if ($configured_selectors !== '') {
      $selectors = preg_split('/[\n,]+/', $configured_selectors) ?: [];
      $selectors = array_values(array_filter(array_map('trim', $selectors), static function ($selector): bool {
        return $selector !== '';
      }));

      if ($selectors !== []) {
        return $selectors;
      }
    }

    $selectors = $map_app_settings['mountSelectors'] ?? NULL;

    if (is_string($selectors) && $selectors !== '') {
      $selectors = array_map('trim', explode(',', $selectors));
    }

    if (is_array($selectors)) {
      $selectors = array_values(array_filter($selectors, static function ($selector): bool {
        return is_string($selector) && $selector !== '';
      }));
      if ($selectors !== []) {
        return $selectors;
      }
    }

    return ['#metsis-map-app'];
  }

}
