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
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api_solr\SearchApiSolrException;

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
  ) {
    $this->leaflet = $leaflet;
    $this->renderer = $renderer;
    $this->moduleExtension = $module_handler->getModule('metsis_drupal');
    $this->configFactory = $config_factory;
    $this->settingsConfig = $config_factory->get('metsis_drupal.settings');
    $this->featureTypeLookup = $feature_type_lookup_service;
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
    $maps = $this->leaflet->leafletMapGetInfo();

    // Work on the map you want to use.
    $map = $maps['OSM Mapnik'];
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

    if ($license_code === 'Not provided') {
      return [];
    }

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

  /**
   * Generate Icon markup for collection image.
   *
   * @param string $parent_id
   *   The id of this collection.
   *
   * @return array
   *   The render array for this component.
   */
  public function getCollectionIconMarkup(string $parent_id): array {
    $image_path = '/' . $this->getModulePath() . '/assets/images/collection';

    return [
      '#theme' => 'metsis_collection_icon_component',
      '#image_path' => $image_path,
      '#parent_id' => $parent_id,
    ];
  }

  /**
   * Generate Icon markup for collection image.
   *
   * @param string $doi_uri
   *   The id of this collection.
   *
   * @return array
   *   The render array for this component.
   */
  public function getDoiIconMarkup(string $doi_uri): array {
    // Get the path to the DOI svg icon.
    // $doi_icon_path = '/' . $this->getModulePath() . '/assets/icons/DOI_logo.svg';
    // return [
    //   '#theme' => 'metsis_doi_icon_component',
    //   '#doi_uri' => $doi_uri,
    //   '#icon_path' => $doi_icon_path,
    // ];.
    return [
      '#prefix' => "<div class=\"doi-icon-wrapper\"><a href=\"{$doi_uri}\"",
      '#suffix' => '</a></div>',
      '#type' => 'icon',
      '#pack_id' => 'metsis_drupal',
      '#icon_id' => 'doi',
    ];
  }

  /**
   * Generate markup for child dataset count.
   *
   * @param array $solr_doc
   *   The solr document array.
   *
   * @return array
   *   The render array for this component.
   */
  public function getChildDatasetCountMarkup(array $solr_doc): array {
    $found_children = $solr_doc['found_children']['numFound'] ?? 0;
    $total_children = $solr_doc['total_children']['numFound'] ?? 0;

    $renderArray = [
      // '#prefix' => '<div class="metsis-child-dataset-count">',
      // '#suffix' => '</div>',
      '#markup' => $this->t('@found of @total', [
        '@found' => $found_children,
        '@total' => $total_children,
      ]),
    ];
    return $renderArray;
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
   * Generate a string for MMD fields that have both long and short names.
   *
   * If both Long name (short name) string is created.
   * Else the one that have value.
   *
   * @param string|null $short
   *   The short name for this field.
   * @param string|null $long
   *   The long name for this field.
   *
   * @return string
   *   The generated string given input
   */
  private function handleShortLong(?string $short = NULL, ?string $long = NULL): string {
    $generated_string = '';
    if (!empty($short) && !empty($long)) {
      $generated_string = $long . '(' . $short . ')';
    }
    elseif (!empty($long) && empty($short)) {
      $generated_string = $long;
    }
    elseif (empty($long) && !empty($short)) {
      $generated_string = $short;
    }
    return $generated_string;
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
  private function linkify(string $text): string {
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
