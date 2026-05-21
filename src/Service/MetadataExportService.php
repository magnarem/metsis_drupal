<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\metsis_drupal\MetsisConstants;
use Drupal\metsis_drupal\Utility\MetsisSolrUtilities;

/**
 * Service for exporting METSIS metadata to supported XML formats.
 */
final class MetadataExportService {

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the metadata export service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Returns the configured export type labels.
   *
   * @return array<string, string>
   *   Export type options, keyed by export machine name.
   */
  public function getExportList(): array {
    $config = $this->configFactory->get('metsis_drupal.metadata_export');
    $export_list = $config->get('export_list') ?? [];
    return is_array($export_list) ? $export_list : [];
  }

  /**
   * Returns per-type descriptions from config.
   *
   * @return array<string, string>
   *   Descriptions keyed by export machine name.
   */
  public function getDescriptions(): array {
    $config = $this->configFactory->get('metsis_drupal.metadata_export');
    $descriptions = $config->get('export_types_descriptions') ?? [];
    return is_array($descriptions) ? $descriptions : [];
  }

  /**
   * Returns export types enabled in settings.
   *
   * @return string[]
   *   Enabled export type keys.
   */
  public function getEnabledExportTypes(): array {
    $settings = $this->configFactory->get('metsis_drupal.settings');
    $enabled = $settings->get('enabled_export_types') ?? [];
    $available = array_keys($this->getExportList());

    if (!is_array($enabled) || $enabled === []) {
      return $available;
    }

    return array_values(array_intersect($available, $enabled));
  }

  /**
   * Returns enabled export options as key => label.
   *
   * @return array<string, string>
   *   Enabled export options.
   */
  public function getEnabledExportOptions(): array {
    $options = $this->getExportList();
    $enabled = array_flip($this->getEnabledExportTypes());
    return array_intersect_key($options, $enabled);
  }

  /**
   * Retrieves MMD metadata xml string from Solr by document id.
   *
   * @param string $id
   *   Solr document id.
   *
   * @return string|null
   *   Base64-encoded MMD XML or NULL when unavailable.
   */
  public function getMmd(string $id): ?string {
    // Reject malformed ids early; this blocks query parser abuse attempts.
    if (!MetsisSolrUtilities::isValidIdentifier($id)) {
      return NULL;
    }

    $index_storage = $this->entityTypeManager->getStorage('search_api_index');
    /** @var \Drupal\search_api\IndexInterface|null $index */
    $index = $index_storage->load(MetsisConstants::METSIS_SOLR_INDEX_ID);
    if (!$index) {
      return NULL;
    }

    /** @var \Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend $backend */
    $backend = $index->getServerInstance()->getBackend();
    $connector = $backend->getSolrConnector();

    /** @var \Solarium\QueryType\Select\Query\Query $query */
    $query = $connector->getSelectQuery();
    $query->setQuery('id:"' . $id . '"');
    $query->setRows(1);
    $query->setFields(['mmd_xml_file']);
    // Use the default JSON response writer so the [xml] transformer result is
    // JSON-encoded. Embedding raw XML inside an XML response body causes
    // parse failures when the MMD content contains unescaped & < > characters.

    /** @var \Solarium\QueryType\Select\Result\Result $result */
    $result = $connector->execute($query);

    $documents = $result->getDocuments();
    if (empty($documents)) {
      return NULL;
    }

    $mmd = $documents[0]['mmd_xml_file'] ?? NULL;
    return is_string($mmd) && $mmd !== '' ? $mmd : NULL;

  }

  /**
   * Transform MMD XML to target export type using configured XSLT.
   *
   * @param string $xml
   *   Plain XML string (not base64).
   * @param string $type
   *   Export type key.
   *
   * @return string|null
   *   Transformed XML or NULL on failure.
   */
  public function transformXml(string $xml, string $type): ?string {
    $config = $this->configFactory->get('metsis_drupal.metadata_export');
    $xslt_path = (string) ($config->get('xslt_path') ?? 'vendor/metno/mmd/xslt/');
    $xslt_prefix = (string) ($config->get('xslt_prefix') ?? 'mmd-to-');

    // DRUPAL_ROOT points to PROJECT_ROOT/web in this project.
    $styles_path = DRUPAL_ROOT . '/../' . trim($xslt_path, '/') . '/' . $xslt_prefix . $type . '.xsl';
    if (!is_file($styles_path)) {
      return NULL;
    }

    $style = file_get_contents($styles_path);
    if ($style === FALSE || $style === '') {
      return NULL;
    }

    $base_dir = DRUPAL_ROOT . '/../vendor/metno/mmd/';

    $loader = static function ($public, $system, $context) use ($base_dir) {
      if (is_string($system) && str_contains($system, 'thesauri/mmd-vocabulary.xml')) {
        $resolved = realpath($base_dir . 'thesauri/mmd-vocabulary.xml');
        return $resolved ?: $system;
      }
      if (is_string($system) && str_contains($system, 'thesauri/theme.en.rdf')) {
        $resolved = realpath($base_dir . 'thesauri/theme.en.rdf');
        return $resolved ?: $system;
      }
      if (is_string($system) && str_contains($system, 'thesauri/nasjonal-temainndeling.rdf')) {
        $resolved = realpath($base_dir . 'thesauri/nasjonal-temainndeling.rdf');
        return $resolved ?: $system;
      }
      return $system;
    };

    libxml_set_external_entity_loader($loader);

    $previous_use_internal_errors = libxml_use_internal_errors(TRUE);

    $xsl_doc = new \DOMDocument();
    if (!$xsl_doc->loadXML($style)) {
      libxml_clear_errors();
      libxml_use_internal_errors($previous_use_internal_errors);
      return NULL;
    }

    $xml_doc = new \DOMDocument();
    if (!$xml_doc->loadXML($xml)) {
      libxml_clear_errors();
      libxml_use_internal_errors($previous_use_internal_errors);
      return NULL;
    }

    libxml_clear_errors();
    libxml_use_internal_errors($previous_use_internal_errors);

    $processor = new \XSLTProcessor();
    $processor->importStylesheet($xsl_doc);
    $output = $processor->transformToXml($xml_doc);

    return is_string($output) ? $output : NULL;
  }

  /**
   * Build export payload for given Solr id and export type.
   *
   * @param string $id
   *   Solr id.
   * @param string $type
   *   Export type key.
   *
   * @return string|null
   *   Exported XML payload or NULL when unavailable.
   */
  public function exportById(string $id, string $type): ?string {
    $mmd = $this->getMmd($id);
    if ($mmd === NULL || $mmd === '') {
      return NULL;
    }

    if ($type === 'mmd') {
      return $mmd;
    }

    return $this->transformXml($mmd, $type);
  }

  /**
   * Build export payload for given mmd xml string and export type.
   *
   * @param string $mmd
   *   MMD XML string.
   * @param string $type
   *   Export type key.
   *
   * @return string|null
   *   Exported XML payload or NULL when unavailable.
   */
  public function exportByMmd(string $mmd, string $type): ?string {
    if (empty($mmd)) {
      return NULL;
    }

    if ($type === 'mmd') {
      return $mmd;
    }

    return $this->transformXml($mmd, $type);
  }

}
