<?php

namespace Drupal\metsis_drupal\Plugin\search_api\data_type;

use Drupal\search_api\DataType\DataTypePluginBase;

/**
 * Provides the an Url field, to map to Drupal URL field.
 *
 * @SearchApiDataType(
 *   id = "metsis_url",
 *   label = @Translation("Metsis URL field"),
 *   description = @Translation("Metsis URL field data type implementation"),
 *   storage_type = "string"
 * )
 */
class UrlDataType extends DataTypePluginBase {}
