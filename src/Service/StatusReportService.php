<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Solarium\QueryType\Select\Query\Query;
use Drupal\metsis_drupal\MetsisConstants;
use Drupal\metsis_drupal\LoggerTrait;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Small service helper for Metsis search related utilities.
 *
 * This class is a thin wrapper for utility functions and provides a place to
 * add additional helper methods in the future. It may delegate to static
 * helpers such as WktHelper.
 */
class StatusReportService {

  use LoggerTrait;
  use StringTranslationTrait;

  /**
   * The search_api_index entity instance.
   *
   * @var \Drupal\search_api\IndexInterface|null
   */
  protected $index;

  /**
   * The Solr connector instance.
   *
   * @var object|null
   *  The Solr connector.
   */
  protected $connector;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The metsis_drupal.settings config object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected ImmutableConfig $settingsConfig;

  /**
   * The module extension service.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */

  protected $moduleHandler;

  /**
   * Constructor.
   *
   * EntityTypeManager is injected so we can load the Search API index once
   * and reuse the Solr connector / query factory across methods.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandler $module_handler,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->moduleHandler = $module_handler;
    $this->configFactory = $config_factory;
    $this->index = $entity_type_manager->getStorage('search_api_index')
      ->load(MetsisConstants::METSIS_SOLR_INDEX_ID);
    if ($this->index) {
      /** @var \Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend $backend */
      $backend = $this->index->getServerInstance()->getBackend();
      $this->connector = $backend->getSolrConnector();
    }
  }

  /**
   * Create a new Solarium select query.
   *
   * Use this instead of repeating the Index/backend/connector boilerplate.
   *
   * @return \Solarium\QueryType\Select\Query\Query
   *   The Solarium select query.
   *
   * @throws \RuntimeException
   *   Thrown when the Solr connector could not be initialized.
   */
  public function createSelectQuery(): Query {
    if (!$this->connector) {
      throw new \RuntimeException('Solr connector not available. Is the search index configured?');
    }
    return $this->connector->getSelectQuery();
  }

  /**
   * Get the connector (rarely needed publicly).
   *
   * @return object
   *   The Solr connector.
   *
   * @throws \RuntimeException
   */
  public function getConnector() {
    if (!$this->connector) {
      throw new \RuntimeException('Solr connector not available.');
    }
    return $this->connector;
  }

  /**
   * Count the number of parents/children and integrity check.
   *
   * @param array $collections
   *   The Configured MMD collections for this site.
   *
   * @return array<string,int>
   *   The result of the parent child relations and unique counts.
   *
   * @todo Move to seperate MetsisSatatusService.
   */
  public function countParentChildRelations(array $collections): array {
    // Create the solr select query and add filters.
    $solarium_query = $this->createSelectQuery();
    $solarium_query->setRows(0);
    $solarium_query->setQuery('*:*');
    $solarium_query->createFilterQuery('active')->setQuery('metadata_status:Active');
    $solarium_query->createFilterQuery('collection')
      ->setQuery('collection:(' . implode(" ", array_keys($collections)) . ')');

    // Use JSON facet query to get all unique parent ids referenced in children.
    $jsonFacetSet = $solarium_query->getFacetSet();

    $jsonFacetSet->createFacetField('referenced_parents')
      ->setField('related_dataset')
      ->setLimit(-1)
      ->setMinCount(1);
    /** @var \Solarium\QueryType\Select\Result\Result $result */
    $result = $this->getConnector()->execute($solarium_query);

    // Get the list of unique parent ids referenced in children.
    /** @var \Solarium\Component\Result\Facet\Buckets $buckets */
    $buckets = $result->getFacetSet()->getFacet('referenced_parents');
    // dpm($uniqueParents, 'unique parents count');.
    $uniqueParents = $buckets->count();
    // Create a new select query and query for marked parents count.
    $solarium_query = $this->createSelectQuery();
    $solarium_query->setRows(0);
    $solarium_query->setQuery('*:*');
    $solarium_query->createFilterQuery('active')->setQuery('metadata_status:Active');
    $solarium_query->createFilterQuery('collection')
      ->setQuery('collection:(' . implode(" ", array_keys($collections)) . ')');
    $solarium_query->createFilterQuery('parents')
      ->setQuery('isParent:true');

    /** @var \Solarium\QueryType\Select\Result\Result $result */
    $result = $this->getConnector()->execute($solarium_query);
    $parentsCount = $result->getNumFound();

    $solarium_query->setRows($parentsCount);
    $solarium_query->setFields('metadata_identifier');
    $result = $this->getConnector()->execute($solarium_query);
    $marked_parent_ids = [];
    foreach ($result as $doc) {
      $marked_parent_ids[] = $doc->metadata_identifier;
    }
    return [
      'unique_parents' => $uniqueParents,
      'parents_count' => $parentsCount,
      'difference' => abs($uniqueParents - $parentsCount),
    ];
  }

  /**
   * Return other statistics for the METSIS index.
   *
   * @param array $collections
   *   The Configured MMD collections for this site.
   *
   * @return array<string,int>
   *   The result of the queries.
   *
   * @todo Move to seperate MetsisSatatusService.
   */
  public function getOtherStatistics(array $collections): array {
    // Create a new select query and query for marked parents count.
    $solarium_query = $this->createSelectQuery();
    $solarium_query->setRows(0);
    $solarium_query->setQuery('*:*');
    $solarium_query->createFilterQuery('active')->setQuery('metadata_status:Active');

    /** @var \Solarium\QueryType\Select\Result\Result $result */
    $result = $this->getConnector()->execute($solarium_query);
    $active_count = $result->getNumFound();

    $solarium_query->removeFilterQuery('active');
    $solarium_query->createFilterQuery('inactive')->setQuery('metadata_status:Inactive');
    $result = $this->getConnector()->execute($solarium_query);
    $inactive_count = $result->getNumFound();

    $solarium_query->removeFilterQuery('inactive');
    $solarium_query->createFilterQuery('collection')
      ->setQuery('collection:(' . implode(" ", array_keys($collections)) . ')');
    $result = $this->getConnector()->execute($solarium_query);
    $total_site_count = $result->getNumFound();

    $solarium_query->createFilterQuery('active')->setQuery('metadata_status:Active');
    $result = $this->getConnector()->execute($solarium_query);
    $total_site_active = $result->getNumFound();

    $solarium_query->removeFilterQuery('active');
    $solarium_query->createFilterQuery('inactive')->setQuery('metadata_status:Inactive');
    $result = $this->getConnector()->execute($solarium_query);
    $total_site_inactive = $result->getNumFound();

    return [
      'total_active' => $active_count,
      'total_inactive' => $inactive_count,
      'total_site' => $total_site_count,
      'total_site_active' => $total_site_active,
      'total_site_inactive' => $total_site_inactive,
    ];
  }

  /**
   * Getter function for module handler.
   *
   * @return \Drupal\Core\Extension\ModuleHandler
   *   return s the module handler.
   */
  public function getModuleHandler(): ModuleHandler {
    return $this->moduleHandler;
  }

  /**
   * Getter function for config factory.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   The config factory.
   */
  public function getConfigFactory(): ConfigFactoryInterface {
    return $this->configFactory;

  }

}
