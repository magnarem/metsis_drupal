<?php

namespace Drupal\metsis_drupal\Plugin\search_api\data_type;

use Drupal\search_api\Plugin\search_api\data_type\StringDataType;

/**
 * Provides WKT support.
 *
 * @SearchApiDataType(
 *   id = "wkt",
 *   label = @Translation("Solr WKT string field"),
 *   description = @Translation("A solr string field with stored WKT string"),
 *   storage_type = "string",
 *   prefix = "ss",
 * )
 */
class WktDataType extends StringDataType {}
