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
use Drupal\metsis_drupal\Service\FeatureTypeLookupService;
use Drupal\metsis_drupal\Service\MetVocabService;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api_solr\SearchApiSolrException;
use Solarium\QueryType\Select\Result\Result;

/**
 * Small service helper for Metsis search related utilities.
 *
 * This class is a thin wrapper for utility functions and provides a place to
 * add additional helper methods in the future. It may delegate to static
 * helpers such as WktHelper.
 */
class MetsisHelper {

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
   * The feature_type_lookup_service.
   *
   * @var \Drupal\metsis_drupal\Service\FeatureTypeLookupService
   */
  protected $featureTypeLookup;

  /**
   * The MET MMD Vocabulary Service ().
   *
   * @var \Drupal\metsis_drupal\Service\MetVocabService
   */
  protected MetVocabService $metVocabService;

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
    FeatureTypeLookupService $feature_type_lookup_service,
    MetVocabService $met_vocab_service,
  ) {
    $this->leaflet = $leaflet;
    $this->renderer = $renderer;
    $this->moduleExtension = $module_handler->getModule('metsis_drupal');
    $this->configFactory = $config_factory;
    $this->settingsConfig = $config_factory->get('metsis_drupal.settings');
    $this->featureTypeLookup = $feature_type_lookup_service;
    $this->metadataExportConfig = $config_factory->get('metsis_drupal.metadata_export');
    $this->licenseIconsConfig = $config_factory->get('metsis_drupal.license_icons');
    $this->metVocabService = $met_vocab_service;
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
   * Extract first document from a Solarium result using raw response data.
   *
   * @param \Solarium\QueryType\Select\Result\Result $result
   *   Solarium select result.
   *
   * @return array<string, mixed>|null
   *   Document or null.
   */
  public function extractFirstDocument(Result $result): ?array {
    $data = $result->getData();
    $docs = $data['response']['docs'] ?? [];
    if (!is_array($docs) || $docs === [] || !is_array($docs[0] ?? NULL)) {
      return NULL;
    }
    return $docs[0];
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
    // Try to fetch from vocabulary first.
    $concepts = $this->metVocabService->getGroupConcepts('Collection_Keywords', 'en');
    $collections = [];
    if (!empty($concepts)) {
      foreach ($concepts as $c) {
        $label = $c['pref_label'] ?? '';
        if ($label !== '') {
          $collections[$label] = $label;
        }
      }
      asort($collections);
      return $collections;
    }

    // Fall back to Solr query.
    $this->getLogger()->notice('No collection concepts found in vocabulary cache, falling back to Solr query for collections.');
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
  public function buildLeafletMap(string $geometry, string $height): array {
    $map = $this->leaflet->leafletMapGetInfo('openstreetmap');
    $map['settings']['leaflet_markercluster'] = ['control' => FALSE];
    $map['settings']['zoomControl'] = FALSE;
    $map['settings']['zoom'] = 10;
    // $map['settings']['crs'] = "L.CRSEPSG4326";
    $feature = $this->leaflet->leafletProcessGeofield($geometry);

    return $this->leaflet->leafletRenderMap($map, $feature, $height);
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
   * Convert MMD metadata_identifier to Solr id field.
   *
   * @param string $metadata_identifier
   *   The MMD metadata_identifier.
   *
   * @return string
   *   The Solr id.
   */
  public function toSolrId($metadata_identifier) {
    return trim(MetsisSolrUtilities::toSolrId($metadata_identifier));
  }

  /**
   * Fetch the feature type from solr or opendap given the OPeNDAP URL.
   *
   * If metadata_identifier is given, then use solr by default.
   *
   * @param string $identifier
   *   The identifier (optional).
   * @param string $url
   *   The provided OPeNDAP url (optional).
   * @param string $mode
   *   The lookup mode. Default solr.
   *
   * @return string|null|int
   *   The featureType fetched from solr or OPeNDAP.
   *   Returns 404 if not found (solr). NULL when other errors
   */
  public function lookupFeatureType(?string $identifier = NULL, ?string $url = NULL, string $mode = 'solr'): string|int|null {
    // Create a select query.
    if ($mode === 'solr' || $identifier != NULL) {
      return $this->lookupFeatureTypeSolr($identifier, $url);
    }
    if ($mode === 'opendap' && $url != NULL) {
      return $this->featureTypeLookup->lookup($url);
    }
    return NULL;
  }

  /**
   * Fetch the feature type from solr given url or identifier.
   *
   * @param string $identifier
   *   The identifier (optional).
   * @param string $url
   *   The provided OPeNDAP url (optional).
   *
   * @return string|null|int
   *   The featureType fetched from solr.
   *   Returns 404 if not found. NULL when other erros.
   */
  public function lookupFeatureTypeSolr(?string $identifier = NULL, ?string $url = NULL): string|int|null {
    // Create a select query.
    $solr_query = $this->createSelectQuery();
    if ($identifier !== NULL && $identifier !== '') {
      $solr_query->setQuery("metadata_identifier:\"{$identifier}\"");
    }

    elseif ($url !== NULL && $url !== '') {
      $solr_query->setQuery("data_access_url_opendap:\"{$url}\"");
    }
    else {
      // No valid input, return unknown.
      $this->getLogger()->warning('FeatureTypeSolrLookup: No URL or identifier provided for feature type lookup.');
      return NULL;
    }
    $solr_query->setRows(1);
    $solr_query->setFields(['feature_type']);
    $solr_query->createFilterQuery('active')->setQuery('metadata_status:Active');

    try {
      /** @var \Solarium\QueryType\Select\Result\Result $result */
      $result = $this->getConnector()->execute($solr_query);
      if ($result->getNumFound() > 0) {
        $featureType = $result->getDocuments()[0]->feature_type ?? NULL;
        return is_array($featureType) ? reset($featureType) : $featureType;
      }
      if ($result->getNumFound() == 0) {
        $this->getLogger()->warning('FeatureTypeSolrLookup: No product found with opendap url @url',
          ['@url' => $url]);
        return 404;
      }
      return NULL;

    }
    catch (SearchApiSolrException $e) {
      $this->getLogger()->error('lookupFeatureType: Solr exception: @message', [
        '@url' => $url,
        '@identifier' => $identifier,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Convert plain text URLs to anchor tags.
   *
   * @param string $text
   *   The input text.
   *
   * @return string
   *   The text with URLs converted to links.
   */
  public function linkify(string $text): string {
    return preg_replace_callback(
      '/(?<!href=")(https?:\/\/|www\.)[^\s<]+/i',
      function ($m) {
        $url = $m[0];
        $href = preg_match('/^www\./i', $url) ? 'http://' . $url : $url;
        $escapedHref = htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedText = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<a href="' . $escapedHref . '" target="_blank" rel="noopener noreferrer">' . $escapedText . '</a>';
      },
      $text
    ) ?? $text;
  }

}
