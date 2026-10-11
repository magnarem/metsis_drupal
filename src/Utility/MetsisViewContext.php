<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Utility;

use Drupal\metsis_drupal\MetsisConstants;
use Drupal\search_api\Query\QueryInterface;
use Drupal\views\ViewExecutable;

/**
 * Identifies METSIS queries and their Views presentation context.
 */
final class MetsisViewContext {

  /**
   * Whether the query uses the METSIS index or its legacy explicit tag.
   */
  public static function isQuery(QueryInterface $query): bool {
    return $query->getIndex()->id() === MetsisConstants::METSIS_SOLR_INDEX_ID || $query->hasTag('metsis');
  }

  /**
   * Whether the View uses the METSIS index.
   */
  public static function isView(ViewExecutable $view): bool {
    return $view->storage->get('base_table') === 'search_api_index_' . MetsisConstants::METSIS_SOLR_INDEX_ID;
  }

  /**
   * Whether a View explicitly requests the shared METSIS layout.
   */
  public static function usesLayout(ViewExecutable $view): bool {
    $query = $view->getDisplay()->getOption('query');
    return $view->id() === MetsisConstants::METSIS_SEARCH_VIEW_ID
      || in_array('metsis', $query['options']['query_tags'] ?? [], TRUE);
  }

}
