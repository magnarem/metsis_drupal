<?php

declare(strict_types=1);

namespace Drupal\metsis_map_test\Plugin\search_api\backend;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Attribute\SearchApiBackend;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;

/**
 * Returns deterministic empty results without contacting Solr.
 */
#[SearchApiBackend(
  id: 'metsis_map_test',
  label: new TranslatableMarkup('METSIS map test'),
)]
final class MapTestBackend extends BackendPluginBase {

  /**
   * {@inheritdoc}
   */
  public function supportsDataType($type) {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    return array_keys($items);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids) {}

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {}

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    $query->getResults()->setResultCount(0);
  }

}
