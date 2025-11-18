<?php

namespace Drupal\metsis_drupal\Plugin\search_api\processor\Property;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Processor\ConfigurablePropertyBase;

/**
 * Defines a GeoJSOn field transformer property.
 *
 * @see \Drupal\search_api_solr\Plugin\search_api\processor\DummyFields
 */
class GeoJsonFieldProperty extends ConfigurablePropertyBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'geojson_field_value' => 'geospatial_bounds',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(FieldInterface $field, array $form, FormStateInterface $form_state) {
    $configuration = $field->getConfiguration();

    $form['geojson_field_value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The solr field to use for the GeoJSON trensformer'),
      '#description' => $this->t('The name of the solr field in the solr index which supports the GeoJSON solr transformer.'),
      '#default_value' => $configuration['geojson_field_value'],
      '#required' => TRUE,
    ];

    return $form;
  }

}
