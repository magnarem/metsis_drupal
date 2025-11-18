<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\metsis_drupal\Plugin\search_api\processor\Property\GeoJsonFieldProperty;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\metsis_drupal\MetsisConstants;

/**
 * Process geojson field transformer query.
 *
 * @SearchApiProcessor(
 *   id = "solr_geojson_field_transformer",
 *   label = @Translation("Solr geojson_transformer"),
 *   description = @Translation("Adds geosjon transformer field"),
 *   stages = {
 *     "add_properties" = 0,
 *     "preprocess_query" = 0,
 *     "postprocess_query" = 99,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class GeoJsonFieldTransformer extends ProcessorPluginBase {

  /**
   * {@inheritDoc}
   */
  public static function supportsIndex(IndexInterface $index) {
    if ($index->id() === MetsisConstants::METSIS_SOLR_INDEX_ID) {
      return TRUE;
    }
    return FALSE;

  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if ($datasource) {
      $definition = [
        'label' => $this->t('GeoJSON field transformer'),
        'description' => $this->t('Adds dummy field that gets its values via API, for example hook_search_api_solr_documents_alter(). To have these values as part of the result set you need to enable "Retrieve result data from Solr" in the server edit form.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
      ];
      $properties['metsis_drupal_geojson_field'] = new GeoJsonFieldProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritDoc}
   */
  public function preprocessSearchQuery(QueryInterface $query) {

    if ($index = $this->getIndex()) {
      $fields = $this->getFieldsHelper()
        ->filterForPropertyPath($index->getFields(TRUE), 'solr_document', 'metsis_drupal_geojson_field');
      foreach ($fields as $field) {
        $configuration = $field->getConfiguration();

        $query->setOption($field->getFieldIdentifier(), $configuration['geojson_field_value']);
        // dpm($query->getOptions());
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postprocessSearchResults(ResultSetInterface $results) {
    $query = $results->getQuery();

    // Ensure we have results to process.
    if (!$results->getResultCount() || $query->getProcessingLevel() !== QueryInterface::PROCESSING_FULL) {
      return;
    }

    // Array to store all GeoJSON features.
    $features = [];

    // Iterate over the result items.
    foreach ($results->getResultItems() as $result_item) {
      // Get the field value for the GeoJSON field.
      $geofield = $result_item->getField('metsis_drupal_geojson_field');
      if ($geofield) {
        $values = $geofield->getValues();

        // Check if the value is an GeoJsonArray.
        if (is_array($values) && isset($values[0]) && is_string($values[0]) && is_array($values[1])) {
          // Add the GeoJSON string as a Feature in the FeatureCollection.
          $features[] = [
            'type' => 'Feature',
            'geometry' => [
              'type' => $values[0],
              'coordinates' => $values[1],
            ],
            'properties' => [
              'id' => $result_item->getId(),
            ],
          ];
          // Convert the PHP array to a GeoJSON string.
          $geojson_string = $this->arrayToGeoJson($values);

          // Update the field value with the GeoJSON string.
          $geofield->setValues([$geojson_string]);
        }
      }
    }
    // Build the GeoJSON FeatureCollection.
    $feature_collection = [
      'type' => 'FeatureCollection',
      'features' => $features,
    ];
    // Add the FeatureCollection to the result set's extra data.
    $results->setExtraData('geojson_feature_collection', $feature_collection);
  }

  /**
   * Convert a PHP array (decoded GeoJSON) into a GeoJSON string.
   *
   * @param array $geojson_array
   *   The PHP array representing the GeoJSON structure.
   *
   * @return string
   *   The GeoJSON string.
   */
  protected function arrayToGeoJson(array $geojson_array): string {
    // GeoJSON must be an associative array with "type" and "coordinates".
    if (!isset($geojson_array[0]) || !isset($geojson_array[1])) {
      throw new \InvalidArgumentException('Invalid GeoJSON array structure.');
    }

    $geojson = [
      'type' => $geojson_array[0],
      'coordinates' => $geojson_array[1],
    ];

    return json_encode($geojson);
  }

}
