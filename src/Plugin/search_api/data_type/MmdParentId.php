<?php

namespace Drupal\metsis_drupal\Plugin\search_api\data_type;

use Drupal\search_api\Plugin\search_api\data_type\StringDataType;

/**
 * Provides WKT support.
 *
 * @SearchApiDataType(
 *   id = "mmd_parent_id",
 *   label = @Translation("A helper field for parent/child relations"),
 *   description = @Translation("A custom field for the parent child relations (related_dataset_id)"),
 *   storage_type = "string",
 *   prefix = "ss",
 * )
 */
class MmdParentId extends StringDataType {}
