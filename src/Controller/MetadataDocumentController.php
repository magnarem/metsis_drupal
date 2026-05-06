<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\metsis_drupal\Service\SolrDocumentLoader;
use Drupal\metsis_drupal\Utility\MetsisSolrUtilities;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for rendering a single metadata document page from Solr.
 */
final class MetadataDocumentController extends ControllerBase {

  /**
   * Document loader service.
   *
   * @var \Drupal\metsis_drupal\Service\SolrDocumentLoader
   */
  protected SolrDocumentLoader $documentLoader;

  /**
   * Constructs the controller.
   */
  public function __construct(SolrDocumentLoader $documentLoader) {
    $this->documentLoader = $documentLoader;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('metsis_drupal.solr_document_loader')
    );
  }

  /**
   * Render a full metadata document page from the Solr id.
   *
   * @param string $id
   *   Solr id.
   *
   * @return array
   *   Render array.
   */
  public function view(string $id): array {
    if (!MetsisSolrUtilities::isValidIdentifier($id)) {
      throw new BadRequestHttpException('Invalid metadata identifier.');
    }

    $document = $this->loadDocument($id);
    if ($document === NULL) {
      throw new NotFoundHttpException('Metadata document not found.');
    }

    $title = (string) ($document['title'] ?? $document['title_en'] ?? $document['metadata_identifier'] ?? $id);

    return [
      '#theme' => 'metsis_metadata_document',
      '#id' => $id,
      '#abstract' => $this->toInlineText($document['abstract'] ?? $document['abstract_en'] ?? ''),
      '#title' => $title,
      '#metadata_updates' => $this->buildMetadataUpdates($document),
      '#summary' => $this->buildSummary($document),
      '#sections' => $this->buildSections($document),
      // '#raw' => $this->buildRaw($document),
      '#attached' => [
        'library' => [
          'metsis_drupal/metsis_metadata_document',
        ],
      ],
      '#cache' => [
        'contexts' => ['url.path'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Load a single Solr document by id using transformer syntax fields.
   *
   * First query gets available fields, second query applies [json] and [geo]
   * transformers for all *_json and *_geojson fields present in that document.
   *
   * @param string $id
   *   Solr id.
   *
   * @return array<string, mixed>|null
   *   Solr document or null if no match.
   */
  private function loadDocument(string $id): ?array {
    return $this->documentLoader->loadDocumentById($id, [
      '*',
      'personnel_json:[json]',
      'data_access_json:[json]',
      'platform_json:[json]',
      'related_information_json:[json]',
      'last_metadata_update_json:[json]',
      'dataset_citation_json:[json]',
    ]);
  }

  /**
   * Build compact summary card fields.
   *
   * @param array<string, mixed> $document
   *   Solr document.
   *
   * @return array<string, string>
   *   Label/value map for summary.
   */
  private function buildSummary(array $document): array {
    return [
      'Metadata identifier' => $this->toInlineText($document['metadata_identifier'] ?? ''),
      'Metadata status' => $this->toInlineText($document['metadata_status'] ?? ''),
      'Metadata source' => $this->toInlineText($document['metadata_source'] ?? ''),
      'Collection' => $this->toInlineText($document['collection'] ?? []),
      'Production status' => $this->toInlineText($document['dataset_production_status'] ?? ''),
      'Operational status' => $this->toInlineText($document['operational_status'] ?? ''),
      'Activity type' => $this->toInlineText($document['activity_type'] ?? ''),
      'Quality control' => $this->toInlineText($document['quality_control'] ?? ''),
      'Iso topic category' => $this->toInlineText($document['iso_topic_category'] ?? ''),
      'Feature type' => $this->toInlineText($document['feature_type'] ?? ''),
      'Access constraint' => $this->toInlineText($document['access_constraint'] ?? ''),
      'License' => $this->toInlineText($document['use_constraint_identifier'] ?? $document['use_constraint_resource'] ?? $document['use_constraint_license_text'] ?? ''),

    ];
  }

  /**
   * Build structured sections for the metadata page.
   *
   * @param array<string, mixed> $document
   *   Solr document.
   *
   * @return array<int, array<string, mixed>>
   *   Structured section list.
   */
  private function buildSections(array $document): array {
    $json_fields = [
      'personnel_json' => 'Personnel',
      'dataset_citation_json' => 'Dataset citation',
      'data_access_json' => 'Data access',
      'related_information_json' => 'Related information',
      'platform_json' => 'Platforms and instruments',
    ];

    $sections = [];
    foreach ($json_fields as $field_name => $label) {
      if (!array_key_exists($field_name, $document)) {
        continue;
      }

      $normalized = $this->normalizeStructuredValue($document[$field_name]);
      if ($normalized === NULL) {
        continue;
      }

      $sections[] = [
        'title' => $label,
        'field' => $field_name,
        'is_structured' => TRUE,
        'value' => $normalized,
        'value_pretty' => $this->formatStructuredValue($normalized),
      ];
    }

    $sections[] = [
      'title' => 'Time and geography',
      'field' => 'time_geography',
      'is_structured' => FALSE,
      'value' => $this->extractSimpleFields($document, [
        'temporal_extent_start_date',
        'temporal_extent_end_date',
        'temporal_extent_period_dr',
        'last_metadata_created_date',
        'last_metadata_updated_date',
        'geographic_extent_rectangle_srsName',
        'geographic_extent_rectangle_north',
        'geographic_extent_rectangle_south',
        'geographic_extent_rectangle_east',
        'geographic_extent_rectangle_west',
        'geospatial_bounds3d',
      ]),
    ];

    $sections[] = [
      'title' => 'Keywords and classification',
      'field' => 'keywords',
      'is_structured' => FALSE,
      'value' => $this->extractSimpleFields($document, [
        'keywords_gcmd',
        'keywords_gemet',
        'keywords_keyword',
        'keywords_vocabulary',
        'project_short_name',
        'project_long_name',
        'project_name',
      ]),
    ];

    $sections[] = [
      'title' => 'Relations and references',
      'field' => 'relations',
      'is_structured' => FALSE,
      'value' => $this->extractSimpleFields($document, [
        'related_dataset',
        'related_dataset_id',
        'dataset_citation_doi',
        'descriptions',
        'related_information_type',
        'related_information_resource',
        'related_information_description',
      ]),
    ];

    $sections[] = [
      'title' => 'Storage and provenance',
      'field' => 'storage',
      'is_structured' => FALSE,
      'value' => $this->extractSimpleFields($document, [
        'storage_information_file_name',
        'storage_information_file_format',
        'storage_information_file_size',
        'storage_information_file_size_unit',
        'storage_information_file_checksum',
        'storage_information_file_checksum_type',
        'storage_information_file_storage_expiry_date',
        'timestamp',
      ]),
    ];

    return array_values(array_filter($sections, static function (array $section): bool {
      if ($section['is_structured'] === TRUE) {
        return TRUE;
      }
      return !empty($section['value']);
    }));
  }

  /**
   * Build metadata update timeline rows for dedicated display.
   *
   * @param array<string, mixed> $document
   *   Solr document.
   *
   * @return array<int, array<string, string>>
   *   Ordered timeline rows.
   */
  private function buildMetadataUpdates(array $document): array {
    if (!array_key_exists('last_metadata_update_json', $document)) {
      return [];
    }

    $normalized = $this->normalizeStructuredValue($document['last_metadata_update_json']);
    if (!is_array($normalized) || $normalized === []) {
      return [];
    }

    $updates = [];
    foreach ($normalized as $row) {
      if (!is_array($row)) {
        continue;
      }

      $datetime = $this->toInlineText($row['datetime'] ?? '');
      $type = $this->toInlineText($row['type'] ?? '');
      $note = $this->toInlineText($row['note'] ?? '');

      if ($datetime === '' && $type === '' && $note === '') {
        continue;
      }

      $updates[] = [
        'datetime' => $datetime,
        'type' => $type,
        'note' => $note,
      ];
    }

    return $updates;
  }

  /**
   * Extract subset of simple fields with label mapping.
   *
   * @param array<string, mixed> $document
   *   Solr document.
   * @param string[] $fields
   *   Field names.
   *
   * @return array<string, string>
   *   Label/value map.
   */
  private function extractSimpleFields(array $document, array $fields): array {
    $values = [];
    foreach ($fields as $field) {
      if (!array_key_exists($field, $document)) {
        continue;
      }
      $text = $this->toInlineText($document[$field]);
      if ($text === '') {
        continue;
      }
      $values[$field] = $text;
    }
    return $values;
  }

  /**
   * Normalize JSON or GeoJSON values for structured rendering.
   *
   * @param mixed $value
   *   Field value from Solr.
   *
   * @return mixed
   *   Structured value or null.
   */
  private function normalizeStructuredValue(mixed $value): mixed {
    if (is_string($value)) {
      $decoded = json_decode($value, TRUE);
      if (json_last_error() === JSON_ERROR_NONE && $decoded !== NULL) {
        return $decoded;
      }
      return $value;
    }

    if (is_array($value)) {
      if ($value === []) {
        return NULL;
      }

      if (count($value) === 1 && is_string(reset($value))) {
        $first = (string) reset($value);
        $decoded = json_decode($first, TRUE);
        if (json_last_error() === JSON_ERROR_NONE && $decoded !== NULL) {
          return $decoded;
        }
      }

      return $value;
    }

    return $value;
  }

  /**
   * Convert Solr values to readable single-line text.
   *
   * @param mixed $value
   *   Any scalar/array/object.
   *
   * @return string
   *   Human-readable value.
   */
  private function toInlineText(mixed $value): string {
    if ($value === NULL) {
      return '';
    }

    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    if (is_array($value)) {
      return implode(', ', $value);
    }

    if (is_scalar($value)) {
      return trim((string) $value);
    }

    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($encoded) ? $encoded : '';
  }

  /**
   * Format structured value as pretty-printed text.
   *
   * @param mixed $value
   *   Structured value.
   *
   * @return string
   *   Pretty JSON string for preformatted output.
   */
  private function formatStructuredValue(mixed $value): string {
    if (is_scalar($value)) {
      return (string) $value;
    }
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($encoded) ? $encoded : '';
  }

}
