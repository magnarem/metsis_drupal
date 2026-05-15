<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\search_api\processor;

use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\metsis_drupal\LoggerTrait;
use Drupal\search_api\IndexInterface;
use Drupal\metsis_drupal\MetsisConstants;

/**
 * Process geojson field transformer query.
 *
 * @SearchApiProcessor(
 *   id = "geojson_transformer",
 *   label = @Translation("Solr geojson_transformer"),
 *   description = @Translation("Adds geosjon transformer field"),
 *   stages = {
 *     "postprocess_query" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class GeoJsonTransformer extends ProcessorPluginBase {
  use LoggerTrait;

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
      $geofield = $result_item->getField('geometry_geojson');
      if ($geofield) {
        $values = $geofield->getValues();
        if (is_array($values) && count($values) == 1) {
          $values = $values[0];
        }
        if (empty($values)) {
          continue;
        }
        $geojsonString = (string) $values;
        $geoJsonArray = json_decode($geojsonString, TRUE);
        // Check if the value is an GeoJsonArray.
        if (is_array($geoJsonArray) && array_key_exists('type', $geoJsonArray) && array_key_exists('coordinates', $geoJsonArray)) {
          // Add the GeoJSON string as a Feature in the FeatureCollection.
          $features[] = [
            'type' => 'Feature',
            'geometry' => [
              'type' => $geoJsonArray['type'],
              'coordinates' => $geoJsonArray['coordinates'],
            ],
            'properties' => [
              'id' => $result_item->getId(),
              'title' => $result_item->getField('title')->getValues()[0] ?? '',
            ],
          ];
        }
      }
    }

    // Build the GeoJSON FeatureCollection.
    $feature_collection = [
      'type' => 'FeatureCollection',
      'features' => $features,
    ];
    // Get the view from the query object.
    // Add the geojson object to the views drupalSettings.
    // The map app will load it an render the features.
    if ($view = $results->getQuery()->getOption('search_api_view')) {
      /** @var \Drupal\views\ViewExecutable $view */
      $view->element['#attached']['drupalSettings']['metsis_drupal']['search']['results']['geojson_feature_collection'] = $feature_collection;
    }
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
    if (!isset($geojson_array['type']) && !isset($geojson_array['coordinates'])) {
      throw new \InvalidArgumentException('Invalid GeoJSON array structure.');
    }
    $geojson = [
      'type' => $geojson_array['type'],
      'coordinates' => $geojson_array['coordinates'],
    ];
    return json_encode($geojson);
  }

}
