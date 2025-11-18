<?php

namespace Drupal\metsis_drupal\Plugin\search_api\data_type;

use Drupal\search_api\DataType\DataTypePluginBase;

/**
 * Provides the solr BBoxField data type support.
 *
 * @SearchApiDataType(
 *   id = "solr_bbox",
 *   label = @Translation("SolR Bbox field"),
 *   description = @Translation("Solr Bbx field data type implementation"),
 *   prefix = "bbox",
 *   storage_type = "string"
 * )
 */
class BboxDataType extends DataTypePluginBase {}
