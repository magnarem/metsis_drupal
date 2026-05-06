<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

/**
 * High-level Solr document loading utilities.
 *
 * Provides methods to load single documents by ID with support for field
 * transformers like [json] and [geo]. Use this for typical document fetch
 * operations instead of directly managing queries.
 */
final class SolrDocumentLoader {

  /**
   * Constructor.
   */
  public function __construct(
    private readonly SolrQueryFactory $queryFactory,
    private readonly SolrConnectorProvider $connectorProvider,
  ) {}

  /**
   * Load a single Solr document by ID.
   *
   * @param string $id
   *   The Solr document ID.
   * @param array<string> $fields
   *   List of fields to retrieve. Can include field transformers like
   *   'personnel_json:[json]' or 'geometry_geojson:[geo]'.
   *
   * @return array<string, mixed>|null
   *   The document or null if not found.
   */
  public function loadDocumentById(string $id, array $fields = ['*']): ?array {
    $query = $this->queryFactory->createSelectQuery();
    $query->setQuery('id:"' . $id . '"');
    $query->setRows(1);
    $query->setFields($fields);

    $connector = $this->connectorProvider->getConnector();
    $result = $connector->execute($query);

    $document = $this->queryFactory->extractFirstDocument($result);
    if ($document !== NULL) {
      unset($document['storage_information_file_location']);
    }
    return $document;
  }

  /**
   * Load multiple Solr documents by IDs.
   *
   * @param array<string> $ids
   *   List of Solr document IDs.
   * @param array<string> $fields
   *   List of fields to retrieve.
   * @param int $rows
   *   Maximum number of rows to return.
   *
   * @return array<int, array<string, mixed>>
   *   Array of documents.
   */
  public function loadDocumentsById(array $ids, array $fields = ['*'], int $rows = 100): array {
    if (empty($ids)) {
      return [];
    }

    $id_query = 'id:(' . implode(' OR ', array_map(fn(string $id) => '"' . $id . '"', $ids)) . ')';
    $query = $this->queryFactory->createSelectQuery();
    $query->setQuery($id_query);
    $query->setRows($rows);
    $query->setFields($fields);

    $connector = $this->connectorProvider->getConnector();
    $result = $connector->execute($query);

    $documents = [];
    foreach ($result->getDocuments() as $doc) {
      $data = $doc->getFields();
      unset($data['storage_information_file_location']);
      $documents[] = $data;
    }

    return $documents;
  }

}
