<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

use Solarium\QueryType\Select\Query\Query;
use Solarium\QueryType\Select\Result\Result;

/**
 * Factory for creating and managing Solarium select queries.
 *
 * Centralizes common query patterns and result extraction logic.
 */
final class SolrQueryFactory {

  /**
   * Constructor.
   */
  public function __construct(
    private readonly SolrConnectorProvider $connectorProvider,
  ) {}

  /**
   * Create a new Solarium select query.
   *
   * @return \Solarium\QueryType\Select\Query\Query
   *   The Solarium select query.
   */
  public function createSelectQuery(): Query {
    return $this->connectorProvider->getConnector()->getSelectQuery();
  }

  /**
   * Extract first document from a Solarium result using raw response data.
   *
   * @param \Solarium\QueryType\Select\Result\Result $result
   *   Solarium select result.
   *
   * @return array<string, mixed>|null
   *   Document or null if not found.
   */
  public function extractFirstDocument(Result $result): ?array {
    $data = $result->getData();
    $docs = $data['response']['docs'] ?? [];
    if (!is_array($docs) || $docs === [] || !is_array($docs[0] ?? NULL)) {
      return NULL;
    }
    return $docs[0];
  }

}
