<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\metsis_drupal\MetsisConstants;

/**
 * Provides lightweight access to the Solr connector.
 *
 * This is a minimal wrapper that loads the search API index once and provides
 * the Solr connector for queries. Use this for Solr-only operations that don't
 * need the full MetsisHelper.
 */
final class SolrConnectorProvider {

  /**
   * The Solr connector instance.
   *
   * @var object|null
   */
  private $connector;

  /**
   * Constructor.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Get the Solr connector.
   *
   * @return object
   *   The Solr connector.
   *
   * @throws \RuntimeException
   *   When the Solr connector cannot be initialized.
   */
  public function getConnector(): object {
    if ($this->connector === NULL) {
      /** @var \Drupal\search_api\IndexInterface $index */
      $index = $this->entityTypeManager->getStorage('search_api_index')
        ->load(MetsisConstants::METSIS_SOLR_INDEX_ID);
      if (!$index) {
        throw new \RuntimeException('METSIS Solr index not configured or available.');
      }
      /** @var \Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend $backend */
      $backend = $index->getServerInstance()->getBackend();
      $this->connector = $backend->getSolrConnector();
    }
    return $this->connector;
  }

}
