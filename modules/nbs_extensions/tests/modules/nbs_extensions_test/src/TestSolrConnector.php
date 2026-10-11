<?php

declare(strict_types=1);

namespace Drupal\nbs_extensions_test;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\metsis_drupal\Service\SolrConnectorProvider;
use Solarium\Core\Client\Response;
use Solarium\QueryType\Select\Query\Query;
use Solarium\QueryType\Select\Result\Result;

/**
 * Supplies an in-memory Solr response without a running Solr server.
 */
final class TestSolrConnector {

  /**
   * Seeds the provider's connector cache without changing production services.
   */
  public static function provider(EntityTypeManagerInterface $entityTypeManager): SolrConnectorProvider {
    $provider = new SolrConnectorProvider($entityTypeManager);
    (new \ReflectionProperty($provider, 'connector'))->setValue($provider, new self());
    return $provider;
  }

  /**
   * Creates the real query used by the document loader.
   */
  public function getSelectQuery(): Query {
    return new Query();
  }

  /**
   * Returns a document for the functional test's dataset.
   */
  public function execute(Query $query): Result {
    $documents = $query->getQuery() === 'id:"dataset-1"'
      ? [['metadata_identifier' => 'dataset-1', 'title' => 'NBS test product']]
      : [];
    return new Result($query, new Response(
      (string) json_encode(['response' => ['docs' => $documents]]),
      ['HTTP/1.1 200 OK'],
    ));
  }

}
