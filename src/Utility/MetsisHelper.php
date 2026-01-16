<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Utility;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\leaflet\LeafletService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Solarium\QueryType\Select\Query\Query;
use Drupal\metsis_drupal\MetsisConstants;
use Drupal\metsis_drupal\LoggerTrait;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Small service helper for Metsis search related utilities.
 *
 * This class is a thin wrapper for utility functions and provides a place to
 * add additional helper methods in the future. It may delegate to static
 * helpers such as WktHelper.
 */
final class MetsisHelper {

  use LoggerTrait;

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
   * The leaflet map service.
   *
   * @var \Drupal\leaflet\LeafletService
   */
  protected LeafletService $leaflet;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

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
   * The metsis_drupal.metadata_export config object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected ImmutableConfig $metadataExportConfig;

  /**
   * The metsis_drupal.license_icons config object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected ImmutableConfig $licenseIconsConfig;


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
    LeafletService $leaflet,
    RendererInterface $renderer,
    ModuleHandlerInterface $module_handler,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->leaflet = $leaflet;
    $this->renderer = $renderer;
    $this->moduleExtension = $module_handler->getModule('metsis_drupal');
    $this->configFactory = $config_factory;
    $this->settingsConfig = $config_factory->get('metsis_drupal.settings');
    $this->metadataExportConfig = $config_factory->get('metsis_drupal.metadata_export');
    $this->licenseIconsConfig = $config_factory->get('metsis_drupal.license_icons');
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
   * Convert a Solr ENVELOPE WKT to a POLYGON WKT.
   *
   * Delegates to WktHelper::envelopeWktToPolygonWkt for now.
   *
   * @param string $envelope_wkt
   *   The ENVELOPE WKT string.
   *
   * @return string
   *   The POLYGON WKT string.
   */
  public function envelopeToPolygon(string $envelope_wkt): string {
    return WktHelper::envelopeWktToPolygonWkt($envelope_wkt);
  }

  /**
   * Get a list of available collections in the index.
   *
   * @return array<string, string>
   *   An array of collections in Solr metsis Index.
   */
  public function getCollections(): array {
    // Create the select query.
    $solarium_query = $this->createSelectQuery();

    /** @var \Solarium\Component\FacetSet $facetSet */
    $facetSet = $solarium_query->getFacetSet();

    /** @var \Solarium\Component\Facet\Field $facetField */
    $facetField = $facetSet->createFacetField('collection');
    $facetField->setField('collection');

    /** @var \Solarium\QueryType\Select\Result\Result $result */
    $result = $this->getConnector()->execute($solarium_query);

    /** @var \Solarium\Component\Result\FacetSet $facetResSet */
    $facetResSet = $result->getFacetSet();

    /** @var \Solarium\Component\Result\Facet\Bucket $facet */
    $facet = $facetResSet->getFacet('collection');

    // Extract the collections from the facet result.
    $collection = [];
    foreach ($facet as $value => $count) {
      $collection[$value] = $value;
    }
    asort($collection);
    return $collection;
  }

  /**
   * Count the number of parents/children and integrity check.
   *
   * @param array $collections
   *   The Configured MMD collections for this site.
   *
   * @return array<string,int>
   *   The result of the parent child relations and unique counts.
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
   * Retrieves the leaflet service.
   *
   * @return \Drupal\leaflet\LeafletService
   *   The leaflet service.
   */
  public function getLeafletService() {
    return $this->leaflet;
  }

  /**
   * Build a render array for a Leaflet map from a geometry.
   *
   * Prefer returning a render array so controllers/blocks can handle rendering
   * and bubbleable metadata correctly.
   */
  public function buildLeafletMap(string $geometry): array {
    $maps = $this->leaflet->leafletMapGetInfo();

    // Work on the map you want to use.
    $map = $maps['OSM Mapnik'];
    $map['settings']['leaflet_markercluster'] = ['control' => FALSE];
    $map['settings']['zoomControl'] = FALSE;
    $map['settings']['zoom'] = 10;
    // $map['settings']['crs'] = "L.CRSEPSG4326";
    $feature = $this->leaflet->leafletProcessGeofield($geometry);

    return $this->leaflet->leafletRenderMap($map, $feature);
  }

  /**
   * Get the module path.
   *
   * @return string
   *   The module path.
   */
  public function getModulePath(): string {
    return $this->moduleExtension->getPath();
  }

  /**
   * Get the metsis_drupal.settings config object.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The config object.
   */
  public function getSettingsConfig(): ImmutableConfig {
    return $this->settingsConfig;
  }

  /**
   * Get the metsis_drupal.metadata_export config object.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The config object.
   */
  public function getMetadataExportConfig(): ImmutableConfig {
    return $this->metadataExportConfig;
  }

  /**
   * Get the metsis_drupal.license_icons config object.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The config object.
   */
  public function getLicenseIconsConfig(): ImmutableConfig {
    return $this->licenseIconsConfig;
  }

  /**
   * Create license icon markup for a given license code.
   *
   * @param string $license_code
   *   The license code.
   *
   * @return array
   *   The render array for the license icon component.
   */
  public function getLicenseIconMarkup(string $license_code): array {
    // Get the config for license icons mapping.
    $license_icons_config = $this->getLicenseIconsConfig();
    $licenses = $license_icons_config->get('license_icons');

    // Replace _ with . to match config keys.
    $license_type = str_replace('.', '_', $license_code);
    if (empty($licenses)) {
      return [];
    }
    if (!isset($licenses[$license_type])) {
      $this->getLogger()->warning('No license icon mapping found for license code: @code', ['@code' => $license_code]);
      return [];
    }
    $license = $licenses[$license_type];
    $icon_path = '/' . $this->getModulePath() . '/' . $license['icon_path'];
    $icon_alt_text = $license['icon_alt_text'] ?? 'License icon';

    return [
      '#theme' => 'metsis_license_icons_component',
      '#license_code' => $license_code,
      '#license_uri' => $license['license_uri'],
      '#icon_path' => $icon_path,
      '#icon_alt_text' => $icon_alt_text,
    ];
  }

}
