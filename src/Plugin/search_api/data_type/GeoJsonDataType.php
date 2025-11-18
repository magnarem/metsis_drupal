<?php

namespace Drupal\metsis_drupal\Plugin\search_api\data_type;

use Drupal\search_api\Plugin\search_api\data_type\StringDataType;

/**
 * Provides GeoJSON support.
 *
 * @SearchApiDataType(
 *   id = "geojson",
 *   label = @Translation("Solr GeoJSON string field"),
 *   description = @Translation("A solr string field with stored GeoJSON string"),
 *   storage_type = "string",
 *   prefix = "ss",
 * )
 */
class GeoJsonDataType extends StringDataType {}
