<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

/**
 * Builds normalized metadata document structures for rendering.
 */
final class MetadataDocumentNormalizer {

  /**
   * Met vocabulary lookup service.
   *
   * @var \Drupal\metsis_drupal\Service\MetVocabServiceInterface
   */
  private readonly MetVocabServiceInterface $metVocabService;

  /**
   * Constructs the normalizer.
   */
  public function __construct(MetVocabServiceInterface $metVocabService) {
    $this->metVocabService = $metVocabService;
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
  public function buildSummary(array $document): array {
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
   * @param \Drupal\metsis_drupal\Service\LeafletMapRenderer|null $leafletMapRenderer
   *   Optional Leaflet map renderer service.
   *
   * @return array<int, array<string, mixed>>
   *   Structured section list.
   */
  public function buildSections(array $document, ?LeafletMapRenderer $leafletMapRenderer = NULL): array {
    $json_fields = $this->getStructuredJsonFields();

    $sections = [];
    foreach ($json_fields as $field_name => $label) {
      if (!array_key_exists($field_name, $document)) {
        continue;
      }

      $normalized = $this->normalizeStructuredValue($document[$field_name]);
      if ($normalized === NULL) {
        continue;
      }

      if ($field_name === 'personnel_json') {
        $personnel_groups = $this->buildPersonnelGroups($normalized);
        if ($personnel_groups === []) {
          continue;
        }

        $sections[] = [
          'title' => $label,
          'field' => $field_name,
          'is_structured' => TRUE,
          'value' => $normalized,
          'value_pretty' => $this->formatStructuredValue($normalized),
          'personnel_groups' => $personnel_groups,
        ];
        continue;
      }

      if ($field_name === 'dataset_citation_json') {
        $dataset_citation_entries = $this->buildDatasetCitationEntries($normalized);
        if ($dataset_citation_entries === []) {
          continue;
        }

        $dataset_citation_strings = [];
        foreach ($dataset_citation_entries as $entry) {
          $citation_text = $this->toInlineText($entry['citation_text'] ?? '');
          if ($citation_text === '') {
            continue;
          }
          $dataset_citation_strings[] = $citation_text;
        }

        $sections[] = [
          'title' => $label,
          'field' => $field_name,
          'is_structured' => TRUE,
          'value' => $normalized,
          'value_pretty' => $this->formatStructuredValue($normalized),
          'dataset_citation_entries' => $dataset_citation_entries,
          'dataset_citation_strings' => $dataset_citation_strings,
        ];
        continue;
      }

      if (in_array($field_name, ['related_information_json', 'data_access_json'], TRUE)) {
        $related_information_entries = $this->buildRelatedInformationEntries($normalized, $field_name);
        if ($related_information_entries === []) {
          continue;
        }

        $sections[] = [
          'title' => $label,
          'field' => $field_name,
          'is_structured' => TRUE,
          'value' => $normalized,
          'value_pretty' => $this->formatStructuredValue($normalized),
          'related_information_entries' => $related_information_entries,
        ];
        continue;
      }

      if ($field_name === 'platform_json') {
        $platform_entries = $this->buildPlatformEntries($normalized);
        if ($platform_entries === []) {
          continue;
        }

        $sections[] = [
          'title' => $label,
          'field' => $field_name,
          'is_structured' => TRUE,
          'value' => $normalized,
          'value_pretty' => $this->formatStructuredValue($normalized),
          'platform_entries' => $platform_entries,
        ];
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

    // Time and geography section with geometry map.
    $time_geography_data = $this->extractSimpleFieldsWithLabels($document, [
      'temporal_extent_start_date' => 'Start date',
      'temporal_extent_end_date' => 'End date',
      'geographic_extent_rectangle_srsName' => 'Spatial reference system',
      'geographic_extent_rectangle_north' => 'North',
      'geographic_extent_rectangle_south' => 'South',
      'geographic_extent_rectangle_east' => 'East',
      'geographic_extent_rectangle_west' => 'West',
    ]);
    $geometry = $this->extractGeometry($document);
    $geometry_render_array = NULL;

    if ($geometry !== NULL) {
      $geometry_type = $this->extractGeometryTypeLabel($geometry, $document);
      if ($geometry_type !== '') {
        $time_geography_data['Geometry type'] = $geometry_type;
      }
    }

    if ($geometry !== NULL && $leafletMapRenderer !== NULL) {
      $geometry_json = json_encode($geometry);
      $geometry_render_array = $leafletMapRenderer->buildLeafletMap($geometry_json, '280px');
    }

    if (!empty($time_geography_data) || $geometry_render_array !== NULL) {
      $sections[] = [
        'title' => 'Time and geography',
        'field' => 'time_geography',
        'is_structured' => FALSE,
        'is_two_column' => TRUE,
        'value' => $time_geography_data,
        'geometry_map' => $geometry_render_array,
      ];
    }

    // Keywords and classification section with vocabulary labels.
    $keywords_section = $this->buildKeywordsSection($document);
    if (!empty($keywords_section['value'])) {
      $sections[] = $keywords_section;
    }

    // Relations and references section.
    $relations_fields = [
      'related_dataset' => 'Related dataset',
      'related_dataset_id' => 'Related dataset ID',
      'dataset_citation_doi' => 'Dataset citation DOI',
      'descriptions' => 'Description',
      'related_information_type' => 'Related information type',
      'related_information_resource' => 'Related information resource',
      'related_information_description' => 'Related information description',
    ];
    $relations_data = $this->extractSimpleFieldsWithLabels($document, $relations_fields);
    if (!empty($relations_data)) {
      $sections[] = [
        'title' => 'Relations and references',
        'field' => 'relations',
        'is_structured' => FALSE,
        'value' => $relations_data,
      ];
    }

    // Storage and provenance section.
    $storage_fields = [
      'storage_information_file_name' => 'File name',
      'storage_information_file_format' => 'File format',
      'storage_information_file_size' => 'File size',
      'storage_information_file_size_unit' => 'File size unit',
      'storage_information_file_checksum' => 'File checksum',
      'storage_information_file_checksum_type' => 'Checksum type',
      'storage_information_file_storage_expiry_date' => 'Storage expiry date',
      'timestamp' => 'Last indexed',
    ];
    $storage_data = $this->extractSimpleFieldsWithLabels($document, $storage_fields);
    if (!empty($storage_data)) {
      $sections[] = [
        'title' => 'Storage and provenance',
        'field' => 'storage',
        'is_structured' => FALSE,
        'value' => $storage_data,
      ];
    }

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
  public function buildMetadataUpdates(array $document): array {
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
   * Build grouped personnel data prepared for template rendering.
   *
   * @param mixed $value
   *   Normalized personnel value.
   *
   * @return array<int, array<string, mixed>>
   *   Grouped personnel entries by role.
   */
  private function buildPersonnelGroups(mixed $value): array {
    $rows = $this->extractStructuredRows($value, ['role', 'name', 'organisation']);
    if ($rows === []) {
      return [];
    }

    $grouped = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }

      $entry = $this->buildPersonnelEntry($row);
      if ($entry === NULL) {
        continue;
      }

      $group_role = $entry['role'] !== '' ? $entry['role'] : 'Unspecified role';
      if (!isset($grouped[$group_role])) {
        $grouped[$group_role] = [
          'role' => $group_role,
          'entries' => [],
        ];
      }

      $grouped[$group_role]['entries'][] = $entry;
    }

    return array_values($grouped);
  }

  /**
   * Build dataset citation entries prepared for template rendering.
   *
   * @param mixed $value
   *   Normalized citation value.
   *
   * @return array<int, array<string, mixed>>
   *   Citation entries.
   */
  private function buildDatasetCitationEntries(mixed $value): array {
    $rows = $this->extractStructuredRows($value, ['title', 'author', 'publisher', 'publication_date']);
    if ($rows === []) {
      return [];
    }

    $entries = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }

      $entry = $this->buildDatasetCitationEntry($row);
      if ($entry === NULL) {
        continue;
      }

      $entries[] = $entry;
    }

    return $entries;
  }

  /**
   * Build related information entries prepared for template rendering.
   *
   * @param mixed $value
   *   Normalized related information value.
   * @param string $source_field
   *   Source Solr field name.
   *
   * @return array<int, array<string, mixed>>
   *   Related information entries.
   */
  private function buildRelatedInformationEntries(mixed $value, string $source_field): array {
    $rows = $this->extractStructuredRows($value, ['type', 'description', 'resource']);
    if ($rows === []) {
      return [];
    }

    $entries = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }

      $type = $this->toInlineText($row['type'] ?? '');
      $description = $this->toInlineText($row['description'] ?? '');
      $resource = $this->normalizeUri($row['resource'] ?? '');
      if ($resource === '') {
        continue;
      }

      $resource_link_text = $resource;

      if ($source_field === 'data_access_json' && strcasecmp(trim($type), 'opendap') === 0) {
        $resource = $this->normalizeOpendapLandingPageUrl($resource);
      }

      $link_text = $resource_link_text;
      if ($description !== '') {
        $link_text = $description;
      }

      if ($type !== '' && strcasecmp(trim($type), trim($description)) === 0) {
        $link_text = $resource_link_text;
      }

      $entries[] = [
        'type_label' => $type !== '' ? $type : 'Related information',
        'resource_url' => $resource,
        'link_text' => $link_text,
        'is_doi' => $this->getUriDomain($resource) === 'doi',
      ];
    }

    return $entries;
  }

  /**
   * Convert OPeNDAP endpoint URL to THREDDS landing page URL.
   *
   * @param string $resource
   *   Resource URL.
   *
   * @return string
   *   Landing page URL with .html suffix.
   */
  private function normalizeOpendapLandingPageUrl(string $resource): string {
    if (preg_match('/\.html?(?:[?#]|$)/i', $resource) === 1) {
      return $resource;
    }

    if (preg_match('/^([^?#]+)([?#].*)?$/', $resource, $matches) !== 1) {
      return $resource;
    }

    $base = $matches[1];
    $suffix = $matches[2] ?? '';
    return $base . '.html' . $suffix;
  }

  /**
   * Return the structured JSON fields rendered in the metadata document.
   *
   * @return array<string, string>
   *   Field name to section title map.
   */
  private function getStructuredJsonFields(): array {
    return [
      'personnel_json' => 'Personnel',
      'dataset_citation_json' => 'Dataset citation',
      'data_access_json' => 'Data access',
      'related_information_json' => 'Related information',
      'platform_json' => 'Platforms and instruments',
    ];
  }

  /**
   * Build platform entries prepared for template rendering.
   *
   * @param mixed $value
   *   Normalized platform JSON value.
   *
   * @return array<int, array<string, mixed>>
   *   Platform entries.
   */
  private function buildPlatformEntries(mixed $value): array {
    $rows = $this->extractStructuredRows($value, ['short_name', 'long_name', 'resource']);
    if ($rows === []) {
      return [];
    }

    $entries = [];
    foreach ($rows as $index => $row) {
      if (!is_array($row)) {
        continue;
      }

      $entry = $this->buildPlatformEntry($row, (int) $index);
      if ($entry === NULL) {
        continue;
      }

      $entries[] = $entry;
    }

    return $entries;
  }

  /**
   * Build one platform entry prepared for template rendering.
   *
   * @param array<string, mixed> $row
   *   Platform row from Solr.
   * @param int $index
   *   Zero-based row index.
   *
   * @return array<string, mixed>|null
   *   Normalized platform entry or NULL when empty.
   */
  private function buildPlatformEntry(array $row, int $index): ?array {
    $name_node = $this->buildVocabularyNode(
      $row,
      ['Platform'],
      'platform-' . $index . '-name',
    );
    if ($name_node === NULL) {
      return NULL;
    }

    $details = $this->buildKeyValueRows(
      $row,
      [
        'orbit_direction' => 'Orbit direction',
        'orbit_relative' => 'Orbit relative',
        'orbit_absolute' => 'Orbit absolute',
        'mode' => 'Mode',
        'polarisation' => 'Polarisation',
        'product_type' => 'Product type',
      ],
      [
        'mode' => ['Instrument_Modes', 'Instrument Modes'],
        'polarisation' => ['Polarisation_Modes', 'Polarisation Modes'],
        'product_type' => ['Product_Types', 'Product Types'],
      ],
      [
        'short_name',
        'long_name',
        'resource',
        'instrument',
        'ancillary',
      ],
      'platform-' . $index . '-detail',
    );

    $instruments = $this->buildPlatformInstrumentEntries($row['instrument'] ?? NULL, $index);
    $ancillary = is_array($row['ancillary'] ?? NULL)
      ? $this->buildKeyValueRows(
          $row['ancillary'],
          [],
          [],
          [],
          'platform-' . $index . '-ancillary',
        )
      : [];

    return [
      'name' => $name_node,
      'details' => $details,
      'instruments' => $instruments,
      'ancillary' => $ancillary,
    ];
  }

  /**
   * Build nested instrument entries prepared for template rendering.
   *
   * @param mixed $value
   *   Instrument JSON value.
   * @param int $platform_index
   *   Zero-based parent platform index.
   *
   * @return array<int, array<string, mixed>>
   *   Instrument entries.
   */
  private function buildPlatformInstrumentEntries(mixed $value, int $platform_index): array {
    $rows = $this->extractStructuredRows($value, ['short_name', 'long_name', 'resource']);
    if ($rows === []) {
      return [];
    }

    $entries = [];
    foreach ($rows as $index => $row) {
      if (!is_array($row)) {
        continue;
      }

      $entry = $this->buildPlatformInstrumentEntry($row, $platform_index, (int) $index);
      if ($entry === NULL) {
        continue;
      }

      $entries[] = $entry;
    }

    return $entries;
  }

  /**
   * Build one nested instrument entry.
   *
   * @param array<string, mixed> $row
   *   Instrument row from Solr.
   * @param int $platform_index
   *   Zero-based parent platform index.
   * @param int $instrument_index
   *   Zero-based instrument index.
   *
   * @return array<string, mixed>|null
   *   Normalized instrument entry or NULL when empty.
   */
  private function buildPlatformInstrumentEntry(array $row, int $platform_index, int $instrument_index): ?array {
    $name_node = $this->buildVocabularyNode(
      $row,
      ['Instrument'],
      'platform-' . $platform_index . '-instrument-' . $instrument_index . '-name',
    );
    if ($name_node === NULL) {
      return NULL;
    }

    $details = $this->buildKeyValueRows(
      $row,
      [
        'mode' => 'Mode',
        'polarisation' => 'Polarisation',
        'product_type' => 'Product type',
      ],
      [
        'mode' => ['Instrument_Modes', 'Instrument Modes'],
        'polarisation' => ['Polarisation_Modes', 'Polarisation Modes'],
        'product_type' => ['Product_Types', 'Product Types'],
      ],
      [
        'short_name',
        'long_name',
        'resource',
      ],
      'platform-' . $platform_index . '-instrument-' . $instrument_index . '-detail',
    );

    return [
      'name' => $name_node,
      'details' => $details,
    ];
  }

  /**
   * Build a label/value node enriched with vocabulary metadata when found.
   *
   * @param array<string, mixed> $row
   *   Source row.
   * @param string[] $collection_keys
   *   Vocabulary collection keys to search.
   * @param string $popover_id
   *   Popover DOM id to use when a vocabulary match exists.
   * @param string[] $candidate_keys
   *   Preferred source keys for the visible label.
   *
   * @return array<string, mixed>|null
   *   Renderable node data or NULL when empty.
   */
  private function buildVocabularyNode(
    array $row,
    array $collection_keys,
    string $popover_id,
    array $candidate_keys = ['long_name', 'short_name'],
  ): ?array {
    $candidates = [];
    foreach ($candidate_keys as $candidate_key) {
      $candidate = $this->toInlineText($row[$candidate_key] ?? '');
      if ($candidate === '') {
        continue;
      }
      $candidates[] = $candidate;
    }

    $resource_url = $this->normalizeUri($row['resource'] ?? '');
    $display_text = $candidates[0] ?? '';
    if ($display_text === '' && $resource_url !== '') {
      $display_text = $resource_url;
    }

    if ($display_text === '' && $resource_url === '') {
      return NULL;
    }

    return [
      'text' => $display_text,
      'resource_url' => $resource_url,
      'popover_id' => $popover_id,
      'vocabulary' => $this->resolveVocabularyConcept($collection_keys, $candidates, $resource_url),
    ];
  }

  /**
   * Build a normalized list of label/value rows.
   *
   * @param array<string, mixed> $source
   *   Source associative array.
   * @param array<string, string> $field_labels
   *   Field name to human-readable label mapping.
   * @param array<string, string[]> $vocabulary_fields
   *   Field name to vocabulary collection keys.
   * @param string[] $excluded_keys
   *   Fields that should never be rendered as generic rows.
   * @param string $popover_prefix
   *   Prefix used to generate predictable popover ids.
   *
   * @return array<int, array<string, mixed>>
   *   Renderable label/value rows.
   */
  private function buildKeyValueRows(array $source, array $field_labels = [], array $vocabulary_fields = [], array $excluded_keys = [], string $popover_prefix = 'metadata-value'): array {
    $rows = [];
    $processed_keys = array_fill_keys($excluded_keys, TRUE);

    foreach ($field_labels as $field => $label) {
      if (isset($processed_keys[$field])) {
        continue;
      }
      if (!array_key_exists($field, $source)) {
        continue;
      }

      $text = $this->toInlineText($source[$field]);
      if ($text === '') {
        continue;
      }

      $rows[] = [
        'label' => $label,
        'value' => $this->buildValueNode(
          $text,
          $source[$field],
          $vocabulary_fields[$field] ?? [],
          $popover_prefix . '-' . $field,
        ),
      ];
      $processed_keys[$field] = TRUE;
    }

    foreach ($source as $field => $raw_value) {
      if (!is_string($field) || isset($processed_keys[$field])) {
        continue;
      }
      if (is_array($raw_value)) {
        continue;
      }

      $text = $this->toInlineText($raw_value);
      if ($text === '') {
        continue;
      }

      $rows[] = [
        'label' => $this->humanizeFieldLabel($field),
        'value' => $this->buildValueNode(
          $text,
          $raw_value,
          $vocabulary_fields[$field] ?? [],
          $popover_prefix . '-' . $field,
        ),
      ];
      $processed_keys[$field] = TRUE;
    }

    return $rows;
  }

  /**
   * Build a single display value node.
   *
   * @param string $text
   *   Visible text.
   * @param mixed $raw_value
   *   Raw field value.
   * @param string[] $collection_keys
   *   Vocabulary collection keys to search.
   * @param string $popover_id
   *   Popover DOM id to use when a vocabulary match exists.
   *
   * @return array<string, mixed>
   *   Renderable value node.
   */
  private function buildValueNode(string $text, mixed $raw_value, array $collection_keys = [], string $popover_id = ''): array {
    $resource_url = $this->normalizeUri($raw_value);

    return [
      'text' => $text,
      'resource_url' => $resource_url,
      'popover_id' => $popover_id,
      'vocabulary' => $collection_keys !== []
        ? $this->resolveVocabularyConcept($collection_keys, [$text], $resource_url)
        : NULL,
    ];
  }

  /**
   * Resolve a vocabulary concept from candidate labels and optional URI.
   *
   * @param string[] $collection_keys
   *   Vocabulary collection keys to search in order.
   * @param string[] $candidate_values
   *   Candidate labels to search in order.
   * @param string|null $resource_url
   *   Optional resource URI.
   *
   * @return array<string, mixed>|null
   *   Matched concept info or NULL when no concept is found.
   */
  private function resolveVocabularyConcept(array $collection_keys, array $candidate_values, ?string $resource_url = NULL): ?array {
    $labels = [];
    foreach ($candidate_values as $candidate_value) {
      if (!is_scalar($candidate_value)) {
        continue;
      }

      $label = trim((string) $candidate_value);
      if ($label === '') {
        continue;
      }

      $labels[] = $label;
    }

    foreach ($collection_keys as $collection_key) {
      if (!is_string($collection_key) || $collection_key === '') {
        continue;
      }

      foreach ($labels as $label) {
        $concept = $this->metVocabService->lookupByLabel($collection_key, $label);
        if ($concept !== NULL) {
          return $concept;
        }
      }
    }

    $uri = $this->normalizeUri($resource_url ?? '');
    if ($uri === '') {
      return NULL;
    }

    return $this->metVocabService->lookupByUri($uri);
  }

  /**
   * Convert a machine field name to a human-readable label.
   *
   * @param string $field
   *   Field name.
   *
   * @return string
   *   Human-readable label.
   */
  private function humanizeFieldLabel(string $field): string {
    $label = str_replace('_', ' ', $field);
    $label = trim($label);
    if ($label === '') {
      return $field;
    }

    return ucfirst($label);
  }

  /**
   * Normalize one dataset citation row for template rendering.
   *
   * @param array<string, mixed> $row
   *   Citation row from Solr.
   *
   * @return array<string, mixed>|null
   *   Normalized citation entry.
   */
  private function buildDatasetCitationEntry(array $row): ?array {
    $normalized = $this->normalizeDatasetCitationRow($row);

    $ordered_labels = [
      'author' => 'Author',
      'title' => 'Title',
      'publisher' => 'Publisher',
      'publication_date' => 'Publication date',
      'publication_place' => 'Publication place',
      'series' => 'Series',
      'edition' => 'Edition',
      'version' => 'Version',
      'volume' => 'Volume',
      'issue' => 'Issue',
      'pages' => 'Pages',
      'isbn' => 'ISBN',
      'other' => 'Other',
    ];

    $field_rows = [];
    foreach ($ordered_labels as $key => $label) {
      $value = $this->toInlineText($normalized[$key] ?? '');
      if ($value === '') {
        continue;
      }

      $field_rows[] = [
        'label' => $label,
        'value' => $value,
      ];
    }

    $resource_url = $this->normalizeDatasetCitationResourceUrl($normalized);
    $resource_is_doi = $this->getUriDomain($resource_url) === 'doi';
    $citation_text = $this->buildDatasetCitationText($normalized, $resource_url);

    if ($field_rows === [] && $resource_url === '' && $citation_text === '') {
      return NULL;
    }

    return [
      'fields' => $field_rows,
      'resource_url' => $resource_url,
      'resource_is_doi' => $resource_is_doi,
      'resource_label' => 'Resource',
      'citation_text' => $citation_text,
    ];
  }

  /**
   * Normalize citation row keys for mixed Solr payload formats.
   *
   * @param array<string, mixed> $row
   *   Citation row.
   *
   * @return array<string, string>
   *   Normalized key/value map.
   */
  private function normalizeDatasetCitationRow(array $row): array {
    $result = [];
    foreach ($row as $raw_key => $raw_value) {
      if (!is_string($raw_key)) {
        continue;
      }

      $key = strtolower($raw_key);
      if (str_starts_with($key, 'dataset_citation_')) {
        $key = substr($key, strlen('dataset_citation_'));
      }

      $value = $this->toInlineText($raw_value);
      if ($value === '') {
        continue;
      }

      $result[$key] = $value;
    }

    return $result;
  }

  /**
   * Resolve citation resource URL from known fields.
   *
   * @param array<string, string> $normalized
   *   Normalized citation values.
   *
   * @return string
   *   Resource URL or empty string.
   */
  private function normalizeDatasetCitationResourceUrl(array $normalized): string {
    $resource_url = $this->normalizeUri($normalized['resource'] ?? '');
    if ($resource_url !== '') {
      return $resource_url;
    }

    $resource_url = $this->normalizeUri($normalized['url'] ?? '');
    if ($resource_url !== '') {
      return $resource_url;
    }

    $doi = $this->toInlineText($normalized['doi'] ?? '');
    if ($doi === '') {
      return '';
    }

    $doi_url = $this->normalizeUri($doi);
    if ($doi_url !== '') {
      return $doi_url;
    }

    $doi_identifier = preg_replace('/^https?:\/\/doi\.org\//i', '', $doi);
    if (!is_string($doi_identifier)) {
      return '';
    }

    $doi_identifier = trim($doi_identifier);
    if ($doi_identifier === '') {
      return '';
    }

    return 'https://doi.org/' . $doi_identifier;
  }

  /**
   * Build a scientific citation string from a normalized citation row.
   *
   * @param array<string, string> $normalized
   *   Normalized citation values.
   * @param string $resource_url
   *   Resolved resource URL.
   *
   * @return string
   *   Citation text.
   */
  private function buildDatasetCitationText(array $normalized, string $resource_url): string {
    $author = $this->toInlineText($normalized['author'] ?? '');
    $title = $this->toInlineText($normalized['title'] ?? '');
    $publisher = $this->toInlineText($normalized['publisher'] ?? '');
    $publication_place = $this->toInlineText($normalized['publication_place'] ?? '');
    $publication_date = $this->toInlineText($normalized['publication_date'] ?? '');
    $version = $this->toInlineText($normalized['version'] ?? '');

    $year = '';
    if ($publication_date !== '' && preg_match('/\b(\d{4})\b/', $publication_date, $matches) === 1) {
      $year = $matches[1];
    }

    $parts = [];
    if ($author !== '') {
      if ($year !== '') {
        $parts[] = $author . ' (' . $year . ').';
      }
      else {
        $parts[] = $author . '.';
      }
    }
    elseif ($year !== '') {
      $parts[] = '(' . $year . ').';
    }

    if ($title !== '') {
      $parts[] = $title . '.';
    }

    $publisher_part = $publisher;
    if ($publisher_part !== '' && $publication_place !== '') {
      $publisher_part .= ', ' . $publication_place;
    }
    elseif ($publisher_part === '' && $publication_place !== '') {
      $publisher_part = $publication_place;
    }
    if ($publisher_part !== '') {
      $parts[] = $publisher_part . '.';
    }

    if ($version !== '') {
      $parts[] = 'Version ' . $version . '.';
    }

    if ($resource_url !== '') {
      $parts[] = $resource_url;
    }

    return trim(implode(' ', $parts));
  }

  /**
   * Extract a flat list of row objects from mixed Solr structures.
   *
   * @param mixed $value
   *   Normalized structured field value.
   * @param string[] $signature_keys
   *   Keys that indicate a single row object.
   *
   * @return array<int, array<string, mixed>>
   *   Flat list of row objects.
   */
  private function extractStructuredRows(mixed $value, array $signature_keys = []): array {
    if (!is_array($value) || $value === []) {
      return [];
    }

    if (array_is_list($value)) {
      $first = reset($value);
      if (is_array($first) && array_is_list($first)) {
        $all_rows = TRUE;
        foreach ($first as $candidate) {
          if (!is_array($candidate)) {
            $all_rows = FALSE;
            break;
          }
        }
        if ($all_rows) {
          return $first;
        }
      }

      return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
    }

    foreach ($signature_keys as $key) {
      if (array_key_exists($key, $value) || array_key_exists('dataset_citation_' . $key, $value)) {
        return [$value];
      }
    }

    $rows = [];
    foreach ($value as $candidate) {
      if (is_array($candidate)) {
        $rows[] = $candidate;
      }
    }

    return $rows;
  }

  /**
   * Normalize one personnel row for template rendering.
   *
   * @param array<string, mixed> $row
   *   Personnel row from Solr.
   *
   * @return array<string, mixed>|null
   *   Normalized row or null when empty.
   */
  private function buildPersonnelEntry(array $row): ?array {
    $name = $this->toInlineText($row['name'] ?? '');
    $organisation = $this->toInlineText($row['organisation'] ?? '');
    $email = $this->toInlineText($row['email'] ?? '');
    $type = $this->toInlineText($row['type'] ?? '');
    $role = $this->toInlineText($row['role'] ?? '');

    $name_uri = $this->normalizeUri($row['name_uri'] ?? $row['orcid_uri'] ?? '');
    $org_uri = $this->normalizeUri($row['org_uri'] ?? $row['ror_uri'] ?? '');
    $contact_address = $this->normalizeContactAddress($row['contact_address'] ?? []);

    if ($name === '' && $organisation === '' && $email === '' && $type === '' && $name_uri === '' && $org_uri === '' && $contact_address === []) {
      return NULL;
    }

    return [
      'role' => $role,
      'name' => $name,
      'organisation' => $organisation,
      'email' => $email,
      'type' => $type,
      'name_uri' => $name_uri,
      'name_uri_domain' => $this->getUriDomain($name_uri),
      'org_uri' => $org_uri,
      'org_uri_domain' => $this->getUriDomain($org_uri),
      'contact_address' => $contact_address,
    ];
  }

  /**
   * Normalize URI values and keep only valid absolute URLs.
   *
   * @param mixed $value
   *   URI value.
   *
   * @return string
   *   Valid URL or empty string.
   */
  private function normalizeUri(mixed $value): string {
    if (is_array($value)) {
      foreach ($value as $item) {
        $uri = $this->normalizeUri($item);
        if ($uri !== '') {
          return $uri;
        }
      }
      return '';
    }

    if (!is_scalar($value)) {
      return '';
    }

    $uri = trim((string) $value);
    if ($uri === '') {
      return '';
    }

    return filter_var($uri, FILTER_VALIDATE_URL) ? $uri : '';
  }

  /**
   * Get URI domain class used by template for icon rendering.
   *
   * @param string $uri
   *   URI value.
   *
   * @return string
   *   Domain class: orcid, ror, other, or empty.
   */
  private function getUriDomain(string $uri): string {
    if ($uri === '') {
      return '';
    }

    $host = parse_url($uri, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
      return '';
    }

    $host = strtolower($host);
    if (str_starts_with($host, 'www.')) {
      $host = substr($host, 4);
    }

    if ($host === 'orcid.org' || str_ends_with($host, '.orcid.org')) {
      return 'orcid';
    }

    if ($host === 'ror.org' || str_ends_with($host, '.ror.org')) {
      return 'ror';
    }

    if ($host === 'doi.org' || str_ends_with($host, '.doi.org')) {
      return 'doi';
    }

    return 'other';
  }

  /**
   * Normalize personnel contact address into ordered display fields.
   *
   * @param mixed $value
   *   Contact address value.
   *
   * @return array<string, string>
   *   Label/value map.
   */
  private function normalizeContactAddress(mixed $value): array {
    if (!is_array($value) || $value === []) {
      return [];
    }

    $map = [
      'address' => 'Address',
      'city' => 'City',
      'province_or_state' => 'Province or state',
      'postal_code' => 'Postal code',
      'country' => 'Country',
    ];

    $normalized = [];
    foreach ($map as $key => $label) {
      if (!array_key_exists($key, $value)) {
        continue;
      }

      $text = $this->toInlineText($value[$key]);
      if ($text === '') {
        continue;
      }

      $normalized[$label] = $text;
    }

    return $normalized;
  }

  /**
   * Extract simple fields with explicit label mapping.
   *
   * @param array<string, mixed> $document
   *   Solr document.
   * @param array<string, string> $field_labels
   *   Field name to label mapping.
   *
   * @return array<string, string>
   *   Label/value map.
   */
  private function extractSimpleFieldsWithLabels(array $document, array $field_labels): array {
    $values = [];
    foreach ($field_labels as $field => $label) {
      if (!array_key_exists($field, $document)) {
        continue;
      }
      $text = $this->toInlineText($document[$field]);
      if ($text === '') {
        continue;
      }
      $values[$label] = $text;
    }
    return $values;
  }

  /**
   * Extract geometry GeoJSON for map rendering.
   *
   * @param array<string, mixed> $document
   *   Solr document.
   *
   * @return array<string, mixed>|null
   *   GeoJSON array or null.
   */
  private function extractGeometry(array $document): ?array {
    if (!array_key_exists('geometry_geojson', $document)) {
      return NULL;
    }

    $geometry_raw = $document['geometry_geojson'];

    if (is_array($geometry_raw)) {
      $geometry = $geometry_raw;
    }
    elseif (is_string($geometry_raw)) {
      $geometry = json_decode($geometry_raw, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE || !is_array($geometry)) {
        return NULL;
      }
    }
    else {
      return NULL;
    }

    if (!isset($geometry['type']) || !isset($geometry['coordinates'])) {
      return NULL;
    }

    return $geometry;
  }

  /**
   * Extract a human-readable geometry type label from GeoJSON.
   *
   * @param array<string, mixed> $geometry
   *   GeoJSON geometry array.
   * @param array<string, mixed> $document
   *   Solr document.
   *
   * @return string
   *   Human-readable geometry type or empty string.
   */
  private function extractGeometryTypeLabel(array $geometry, array $document): string {
    $type = $geometry['type'] ?? NULL;
    if (!is_string($type)) {
      return '';
    }

    $type = trim($type);
    if ($type === '') {
      return '';
    }

    if ($type === 'Polygon' && $this->isRectangularPolygon($document)) {
      return 'Rectangular polygon';
    }

    $label = preg_replace('/(?<!^)([A-Z])/', ' $1', $type);
    if (!is_string($label)) {
      return $type;
    }

    return trim($label);
  }

  /**
   * Determine whether bounds indicate a rectangular polygon.
   *
   * @param array<string, mixed> $document
   *   Solr document.
   *
   * @return bool
   *   TRUE when bounds are represented as a Solr ENVELOPE WKT.
   */
  private function isRectangularPolygon(array $document): bool {
    $bounds = $document['geospatial_bounds3d'] ?? NULL;
    if ($bounds === NULL) {
      return FALSE;
    }

    if (is_array($bounds)) {
      $bounds = (string) reset($bounds);
    }
    elseif (!is_scalar($bounds)) {
      return FALSE;
    }

    $bounds_wkt = trim((string) $bounds);
    if ($bounds_wkt === '') {
      return FALSE;
    }

    return preg_match('/^ENVELOPE\s*\(/i', $bounds_wkt) === 1;
  }

  /**
   * Build keywords section with vocabulary-grouped display.
   *
   * @param array<string, mixed> $document
   *   Solr document.
   *
   * @return array<string, mixed>
   *   Keywords section array.
   */
  private function buildKeywordsSection(array $document): array {
    $vocabularies = $this->toInlineText($document['keywords_vocabulary'] ?? []);
    $vocab_list = [];
    if ($vocabularies !== '') {
      $vocab_list = array_filter(array_map('trim', explode(',', $vocabularies)));
    }

    // Build keyword entries grouped by vocabulary.
    $keywords_by_vocab = [];

    if (array_key_exists('keywords_gcmd', $document) && in_array('GCMDSK', $vocab_list, TRUE)) {
      $keywords_by_vocab['GCMDSK Earth Science Keywords'] = $this->toInlineText($document['keywords_gcmd'] ?? []);
    }

    if (array_key_exists('keywords_gcmdplt', $document) && in_array('GCMDPLT', $vocab_list, TRUE)) {
      $keywords_by_vocab['GCMDPLT Platform Keywords'] = $this->toInlineText($document['keywords_gcmdplt'] ?? []);
    }

    if (array_key_exists('keywords_gcmdinst', $document) && in_array('GCMDINST', $vocab_list, TRUE)) {
      $keywords_by_vocab['GCMDINST Instrument Keywords'] = $this->toInlineText($document['keywords_gcmdinst'] ?? []);
    }

    if (array_key_exists('keywords_gcmdloc', $document) && in_array('GCMDLOC', $vocab_list, TRUE)) {
      $keywords_by_vocab['GCMDLOC Location Keywords'] = $this->toInlineText($document['keywords_gcmdloc'] ?? []);
    }

    if (array_key_exists('keywords_gcmdprov', $document) && in_array('GCMDPROV', $vocab_list, TRUE)) {
      $keywords_by_vocab['GCMDPROV Provider Keywords'] = $this->toInlineText($document['keywords_gcmdprov'] ?? []);
    }
    if (array_key_exists('keywords_cfstdn', $document) && in_array('GCFSTDN', $vocab_list, TRUE)) {
      $keywords_by_vocab['GCFSTDN CF Standard Names'] = $this->toInlineText($document['keywords_cfstdn'] ?? []);
    }

    if (array_key_exists('keywords_gemet', $document) && in_array('GEMET', $vocab_list, TRUE)) {
      $keywords_by_vocab['GEMET - INSPIRE Keywords'] = $this->toInlineText($document['keywords_gemet'] ?? []);
    }

    if (array_key_exists('keywords_northemes', $document) && in_array('NORTHEMES', $vocab_list, TRUE)) {
      $keywords_by_vocab['NORTHEMES Keywords'] = $this->toInlineText($document['keywords_northemes'] ?? []);
    }

    if (array_key_exists('keywords_none', $document) && in_array('NONE', $vocab_list, TRUE)) {
      $keywords_by_vocab['Other Keywords'] = $this->toInlineText($document['keywords_none'] ?? []);
    }

    // Project information.
    $project_data = [];
    if (array_key_exists('project_short_name', $document)) {
      $project_data['Project short name'] = $this->toInlineText($document['project_short_name'] ?? []);
    }
    if (array_key_exists('project_long_name', $document)) {
      $project_data['Project long name'] = $this->toInlineText($document['project_long_name'] ?? []);
    }
    if (array_key_exists('project_name', $document)) {
      $project_data['Project name'] = $this->toInlineText($document['project_name'] ?? []);
    }

    $all_values = array_merge($keywords_by_vocab, $project_data);

    return [
      'title' => 'Keywords and classification',
      'field' => 'keywords',
      'is_structured' => FALSE,
      'value' => $all_values,
      'keywords_by_vocab' => $keywords_by_vocab,
    ];
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
