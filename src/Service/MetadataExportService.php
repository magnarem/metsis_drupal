<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\metsis_drupal\MetsisConstants;

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
   * Retrieves base64-encoded MMD metadata from Solr by document id.
   *
   * @param string $id
   *   Solr document id.
   *
   * @return string|null
   *   Base64-encoded MMD XML or NULL when unavailable.
   */
  public function getMmd(string $id): ?string {
    // Reject malformed ids early; this blocks query parser abuse attempts.
    if (!$this->isValidIdentifier($id)) {
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
    $query->setFields(['id', 'mmd_xml_file:[xml]']);
    $query->setResponseWriter('xml');

    /** @var \Solarium\QueryType\Select\Result\Result $result */
    $result = $connector->execute($query);
    foreach ($result as $doc) {
      $fields = $doc->getFields();
      if (isset($fields['mmd_xml_file']) && is_string($fields['mmd_xml_file']) && $fields['mmd_xml_file'] !== '') {
        return $fields['mmd_xml_file'];
      }
    }

    return NULL;
  }

  /**
   * Validate external identifier format.
   *
   * @param string $id
   *   Candidate Solr id.
   *
   * @return bool
   *   TRUE when id is safe and supported.
   */
  private function isValidIdentifier(string $id): bool {
    return $id !== '' && preg_match('/^[A-Za-z0-9_.:-]+$/', $id) === 1;
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

    $decoded = base64_decode($mmd, TRUE);
    if (!is_string($decoded)) {
      return NULL;
    }

    if ($type === 'mmd') {
      return $decoded;
    }

    return $this->transformXml($decoded, $type);
  }

}
