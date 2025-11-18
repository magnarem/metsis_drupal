<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\search_api\data_type;

use Drupal\search_api\DataType\DataTypePluginBase;

/**
 * A Search API data type for representing a point (lat/lng) location.
 *
 * This data type needs to be supported by the Search API backend and is
 * in the "lat,lng" format (decimal values separated with a comma). Search API
 * Solr provides this type configuration in the Solr schema files.
 *
 * @SearchApiDataType(
 *   id = "rpt",
 *   label = @Translation("WKT Location RPT"),
 *   description = @Translation("A geometry in WKT format."),
 * )
 */
class LocationRptDataType extends DataTypePluginBase {}
