<?php

namespace Drupal\metsis_drupal\Plugin\DataType;

use Drupal\Core\TypedData\Plugin\DataType\Uri;

/**
 * A data type for Solr date strings.
 *
 * The plain value of this data type is a URI string.
 *
 * @DataType(
 *   id = "solr_url",
 *   label = @Translation("Metsis Solr Url")
 * )
 */
class SolrUrl extends Uri {}
