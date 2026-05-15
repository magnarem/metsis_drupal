<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

/**
 * Builds normalized metadata document structures for rendering.
 */
final class MetadataDocumentNormalizer {

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

      $sections[] = [
        'title' => $label,
        'field' => $field_name,
        'is_structured' => TRUE,
        'value' => $normalized,
        'value_pretty' => $this->formatStructuredValue($normalized),
      ];
    }

    // Time and geography section with geometry map.
    $time_geography_fields = [
      'temporal_extent_start_date' => 'Start date',
      'temporal_extent_end_date' => 'End date',
      'geographic_extent_rectangle_srsName' => 'Spatial reference system',
      'geographic_extent_rectangle_north' => 'North',
      'geographic_extent_rectangle_south' => 'South',
      'geographic_extent_rectangle_east' => 'East',
      'geographic_extent_rectangle_west' => 'West',
    ];
    $time_geography_data = $this->extractSimpleFieldsWithLabels($document, $time_geography_fields);
    $geometry = $this->extractGeometry($document);
    $geometry_render_array = NULL;

    if ($geometry !== NULL && $leafletMapRenderer !== NULL) {
      $geometry_json = \is_string($geometry) ? $geometry : json_encode($geometry);
      $geometry_render_array = $leafletMapRenderer->buildLeafletMap($geometry_json, '400px');
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
      'timestamp' => 'Last updated',
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
      $keywords_by_vocab['GCMDPROV Providers Keywords'] = $this->toInlineText($document['keywords_gcmdprov'] ?? []);
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
