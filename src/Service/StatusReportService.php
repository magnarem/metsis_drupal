<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Solarium\QueryType\Select\Query\Query;
use Drupal\metsis_drupal\MetsisConstants;
use Drupal\metsis_drupal\LoggerTrait;
use Drupal\Core\Extension\ModuleHandlerInterface;
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
   * The metsis_drupal module extension.
   *
   * @var \Drupal\Core\Extension\Extension
   */

  protected $moduleExtension;

  /**
   * Constructor.
   *
   * EntityTypeManager is injected so we can load the Search API index once
   * and reuse the Solr connector / query factory across methods.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->moduleExtension = $module_handler->getModule('metsis_drupal');
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

    // Add a JSON facet for the HyperLogLog aggregation.
    $jsonFacetSet->createJsonFacetAggregation('unique_parents')
      ->setFunction('hll(related_dataset_id)');

    /** @var \Solarium\QueryType\Select\Result\Result $result */
    $result = $this->getConnector()->execute($solarium_query);

    /** @var \Solarium\Component\Result\FacetSet $facetResSet */
    $facetResSet = $result->getFacetSet();

    /** @var \Solarium\Component\Result\Facet\Aggregation $uniqueParentsRes */
    $uniqueParentsRes = $facetResSet->getFacet('unique_parents');

    $uniqueParents = $uniqueParentsRes->getValue();

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

}
