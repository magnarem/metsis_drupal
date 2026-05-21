<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Utility;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\metsis_drupal\LoggerTrait;
use Drupal\metsis_drupal\Service\FeatureTypeLookupService;
use Drupal\metsis_drupal\Service\MetVocabService;
use Drupal\metsis_drupal\Service\SolrConnectorProvider;
use Drupal\metsis_drupal\Service\ConfigProvider;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api_solr\SearchApiSolrException;

/**
 * High-level search helper service for METSIS.
 *
 * Provides search-specific utilities like collections lookup, feature type
 * detection, and text processing. Lower-level Solr operations (connector,
 * query creation) are delegated to dedicated services.
 */
final class MetsisHelper {

  use LoggerTrait;
  use StringTranslationTrait;

  /**
   * The metsis_drupal module extension.
   *
   * @var \Drupal\Core\Extension\Extension
   */
  protected $moduleExtension;

  /**
   * The feature type lookup service.
   *
   * @var \Drupal\metsis_drupal\Service\FeatureTypeLookupService
   */
  protected FeatureTypeLookupService $featureTypeLookup;

  /**
   * The MET vocabulary service.
   *
   * @var \Drupal\metsis_drupal\Service\MetVocabService
   */
  protected MetVocabService $metVocabService;

  /**
   * The Solr connector provider.
   *
   * @var \Drupal\metsis_drupal\Service\SolrConnectorProvider
   */
  protected SolrConnectorProvider $connectorProvider;

  /**
   * The config provider.
   *
   * @var \Drupal\metsis_drupal\Service\ConfigProvider
   */
  protected ConfigProvider $configProvider;

  /**
   * Constructor.
   */
  public function __construct(
    ModuleHandlerInterface $module_handler,
    ConfigProvider $config_provider,
    FeatureTypeLookupService $feature_type_lookup_service,
    MetVocabService $met_vocab_service,
    SolrConnectorProvider $connector_provider,
  ) {
    $this->moduleExtension = $module_handler->getModule('metsis_drupal');
    $this->configProvider = $config_provider;
    $this->featureTypeLookup = $feature_type_lookup_service;
    $this->metVocabService = $met_vocab_service;
    $this->connectorProvider = $connector_provider;
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
    $connector = $this->connectorProvider->getConnector();
    $solarium_query = $connector->getSelectQuery();

    /** @var \Solarium\Component\FacetSet $facetSet */
    $facetSet = $solarium_query->getFacetSet();

    /** @var \Solarium\Component\Facet\Field $facetField */
    $facetField = $facetSet->createFacetField('collection');
    $facetField->setField('collection');

    /** @var \Solarium\QueryType\Select\Result\Result $result */
    $result = $connector->execute($solarium_query);

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
   * Get the module path.
   *
   * @return string
   *   The module path.
   */
  public function getModulePath(): string {
    return $this->moduleExtension->getPath();
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
   *   Returns 404 if not found. NULL when other errors.
   */
  public function lookupFeatureTypeSolr(?string $identifier = NULL, ?string $url = NULL): string|int|null {
    $connector = $this->connectorProvider->getConnector();
    $solr_query = $connector->getSelectQuery();
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
      $result = $connector->execute($solr_query);
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
      '/(?<![="\'])(https?:\/\/|www\.)[^\s<]+/i',
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
