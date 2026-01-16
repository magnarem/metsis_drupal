<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\views\field;

use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\search_api\Plugin\views\field\SearchApiFieldTrait;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\leaflet\LeafletService;
use Drupal\views\ResultRow;

/**
 * Provides a custom Views field handler to render geometries as Leaflet maps.
 *
 * @ingroup views_field_handlers
*/
#[ViewsField('leaflet_geometry_field')]
class LeafletGeometryField extends FieldPluginBase {
  use SearchApiFieldTrait;

  /**
   * Denotes whether the plugin has an additional options form.
   *
   * @var bool
   */
  protected $usesOptions = TRUE;

  /**
   * The leaflet map service.
   *
   * @var \Drupal\leaflet\LeafletService
   */
  protected $leaflet;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $field */
    $field = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $field->setLeafletService($container->get('leaflet.service'));
    return $field;
  }

  /**
   * Retrieves the leaflet service.
   *
   * @return \Drupal\leaflet\LeafletService
   *   The leaflet service.
   */
  public function getLeafletService() {
    return $this->leaflet;
  }

  /**
   * Sets the leaflet service.
   *
   * @param \Drupal\leaflet\LeafletService $leaflet_service
   *   The new leaflet service.
   *
   * @return $this
   */
  public function setLeafletService(LeafletService $leaflet_service) {
    $this->leaflet = $leaflet_service;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function defineOptions() {
    $options = parent::defineOptions();
    // Default options needed by the trait.
    $options['link_to_item'] = ['default' => FALSE];
    $options['use_highlighting'] = ['default' => FALSE];
    // Our display as map option.
    $options['display_as_leaflet'] = ['default' => FALSE];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['display_as_leaflet'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display as Leaflet map'),
      '#description' => $this->t('If checked, the field will be rendered as a Leaflet map.'),
      '#default_value' => (bool) $this->options['display_as_leaflet'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $value = $this->getValue($values);
    if (TRUE == str_starts_with($value[0], 'ENVELOPE')) {
      $this->getLogger()->warning('LeafletGeometryField received a BBOX value instead of WKT geometry: @value', ['@value' => $value]);
      return $this->sanitizeValue('');
    }
    $render_as_map = (bool) $this->options['display_as_leaflet'];
    if ($render_as_map) {
      $map = $this->getLeafletService()->leafletMapGetInfo();
      // Configure the leaflet map. Remove some controls.
      $map['OSM Mapnik']['settings']['leaflet_markercluster'] = [
        'control' => FALSE,
      ];
      $map['OSM Mapnik']['settings']['zoomControl'] = FALSE;
      $map['OSM Mapnik']['settings']['zoom'] = 10;

      $feature = $this->getLeafletService()->leafletProcessGeofield($value);
      $map_markup = $this->getLeafletService()->leafletRenderMap($map['OSM Mapnik'], $feature);
      return $this->renderer->render($map_markup);
    }
    return $this->sanitizeValue(implode('', $value));
  }

}
