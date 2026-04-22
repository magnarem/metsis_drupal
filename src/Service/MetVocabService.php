<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\metsis_drupal\LoggerTrait;
use EasyRdf\Graph;
use EasyRdf\Literal;
use EasyRdf\RdfNamespace;
use EasyRdf\Resource as RdfResource;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Vocabulary service backed by the MMD Turtle vocabulary file.
 *
 * Parses the vendor-supplied TTL once with EasyRdf, builds a flat lookup index,
 * and stores it in the metsis_vocab cache bin. Cron calls refresh(force: FALSE)
 * which only rebuilds when the TTL has expired or the entry is missing.
 */
final class MetVocabService implements MetVocabServiceInterface {

  use LoggerTrait;

  /**
   * Cache key for the normalized vocabulary index.
   */
  private const CACHE_CID = 'metsis_vocab:index';

  /**
   * Cache key for the lightweight meta index (group keys + counts only).
   */
  private const INDEX_META_CID = 'metsis_vocab:index_meta';

  /**
   * Cache ID prefix for per-group payloads.
   *
   * Full CID: GROUP_CID_PREFIX . $group_key.
   */
  private const GROUP_CID_PREFIX = 'metsis_vocab:group:';

  /**
   * Cache ID prefix for per-concept payloads.
   *
   * Full CID: CONCEPT_CID_PREFIX . md5($concept_uri).
   */
  private const CONCEPT_CID_PREFIX = 'metsis_vocab:concept:';

  /**
   * Index schema version. Increment when the stored array shape changes.
   */
  private const INDEX_VERSION = 1;

  /**
   * In-memory copy of the index for the current request.
   *
   * @var array|null
   */
  private ?array $index = NULL;

  /**
   * Constructs a MetVocabService.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory, used to read vocab_source_path and vocab_cache_ttl.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The metsis_vocab cache bin.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The datetime.time service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {}

  // ---------------------------------------------------------------------------
  // MetVocabServiceInterface implementation.
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function lookupByLabel(string $collection_key, string $label, string $lang = 'en'): ?array {
    $index = $this->getIndex();
    $key = $this->resolveGroupKey($collection_key, $index);
    if ($key === NULL) {
      return NULL;
    }
    $lower = mb_strtolower($label);
    $uri = $index['label_index'][$key][$lower] ?? NULL;
    if ($uri === NULL) {
      return NULL;
    }
    return $this->buildConceptInfo($uri, $lang, $index);
  }

  /**
   * {@inheritdoc}
   */
  public function lookupByUri(string $uri, string $lang = 'en'): ?array {
    $index = $this->getIndex();
    if (!isset($index['concepts'][$uri])) {
      return NULL;
    }
    return $this->buildConceptInfo($uri, $lang, $index);
  }

  /**
   * {@inheritdoc}
   */
  public function getGroup(string $collection_key, string $lang = 'en'): ?array {
    $index = $this->getIndex();
    $key = $this->resolveGroupKey($collection_key, $index);
    if ($key === NULL) {
      return NULL;
    }
    $group = $index['groups'][$key] ?? NULL;
    if ($group === NULL) {
      return NULL;
    }
    return [
      'uri'          => $group['uri'],
      'label'        => $this->resolveLang($group['labels'], $lang),
      'definition'   => $this->resolveLang($group['definitions'], $lang),
      'member_count' => count($group['member_uris']),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getParent(string $uri, string $lang = 'en'): ?array {
    $index = $this->getIndex();
    $concept = $index['concepts'][$uri] ?? NULL;
    if ($concept === NULL || empty($concept['broader'])) {
      return NULL;
    }
    return $this->buildConceptInfo($concept['broader'][0], $lang, $index);
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupConcepts(string $collection_key, string $lang = 'en'): array {
    $index = $this->getIndex();
    $key = $this->resolveGroupKey($collection_key, $index);
    if ($key === NULL) {
      return [];
    }
    $group = $index['groups'][$key] ?? NULL;
    if ($group === NULL) {
      return [];
    }
    $result = [];
    foreach ($group['member_uris'] as $member_uri) {
      $info = $this->buildConceptInfo($member_uri, $lang, $index);
      if ($info !== NULL) {
        $result[] = $info;
      }
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function refresh(bool $force = FALSE): void {
    if (!$force && $this->cache->get(self::CACHE_CID) !== FALSE) {
      $this->getLogger()->debug('MetVocabService: cache still valid, skipping cron refresh.');
      return;
    }
    // Delete per-group CIDs using the existing full index (if present).
    $cached = $this->cache->get(self::CACHE_CID);
    if ($cached !== FALSE && is_array($cached->data) && isset($cached->data['groups'])) {
      $cids = [];
      foreach (array_keys($cached->data['groups']) as $key) {
        $cids[] = self::GROUP_CID_PREFIX . $key;
      }
      if (!empty($cids)) {
        $this->cache->deleteMultiple($cids);
      }
    }
    $this->cache->delete(self::INDEX_META_CID);
    $this->cache->delete(self::CACHE_CID);
    $this->index = NULL;
    // Rebuild and warm all caches immediately.
    $this->getIndex();
  }

  // ---------------------------------------------------------------------------
  // Internal helpers.
  // ---------------------------------------------------------------------------

  /**
   * Return the cached index, rebuilding from source when missing or stale.
   *
   * @return array
   *   The normalized vocabulary index.
   */
  private function getIndex(): array {
    if ($this->index !== NULL) {
      return $this->index;
    }
    $cached = $this->cache->get(self::CACHE_CID);
    if ($cached !== FALSE
        && is_array($cached->data)
        && ($cached->data['version'] ?? 0) === self::INDEX_VERSION) {
      $this->index = $cached->data;
      return $this->index;
    }
    $this->index = $this->buildIndex();
    $ttl = (int) ($this->configFactory
      ->get('metsis_drupal.settings')
      ->get('vocab_cache_ttl') ?? 86400);
    $this->cache->set(
      self::CACHE_CID,
      $this->index,
      $this->time->getRequestTime() + $ttl,
      ['metsis_vocab'],
    );
    return $this->index;
  }

  /**
   * Parse the TTL source and build a fully-indexed array structure.
   *
   * @return array
   *   Normalized index or an empty index on parse failure.
   */
  private function buildIndex(): array {
    $config = $this->configFactory->get('metsis_drupal.settings');
    $path = (string) ($config->get('vocab_source_path')
      ?? 'vendor/metno/mmd/thesauri/mmd-vocabulary.ttl');

    // Resolve a relative path from the project root (parent of DRUPAL_ROOT).
    if (!str_starts_with($path, '/')) {
      $path = dirname(DRUPAL_ROOT) . '/' . ltrim($path, '/');
    }

    if (!is_readable($path)) {
      $this->getLogger()->error(
        'MetVocabService: vocabulary file not found or not readable at @path',
        ['@path' => $path],
      );
      return $this->emptyIndex();
    }

    try {
      $this->registerNamespaces();
      $graph = new Graph();
      $graph->parseFile($path, 'turtle');
      $index = $this->indexGraph($graph);
      $this->getLogger()->info(
        'MetVocabService: built index — @c concepts in @g groups.',
        ['@c' => count($index['concepts']), '@g' => count($index['groups'])],
      );
      // Warm per-group and per-concept caches so individual lookups can
      // deserialise only the slice they need instead of the full index.
      $ttl = (int) ($this->configFactory
        ->get('metsis_drupal.settings')
        ->get('vocab_cache_ttl') ?? 86400);
      $expire = $this->time->getRequestTime() + $ttl;
      $meta = ['version' => self::INDEX_VERSION, 'groups' => []];
      foreach ($index['groups'] as $key => $group) {
        $meta['groups'][$key] = [
          'uri'          => $group['uri'],
          'label'        => $this->resolveLang($group['labels'], 'en'),
          'member_count' => count($group['member_uris']),
        ];
        $this->cache->set(self::GROUP_CID_PREFIX . $key, $group, $expire, ['metsis_vocab']);
        foreach ($group['member_uris'] as $member_uri) {
          $concept = $index['concepts'][$member_uri] ?? NULL;
          if ($concept !== NULL) {
            $this->cache->set(self::CONCEPT_CID_PREFIX . md5($member_uri), $concept, $expire, ['metsis_vocab']);
          }
        }
      }
      $this->cache->set(self::INDEX_META_CID, $meta, $expire, ['metsis_vocab']);
      return $index;
    }
    catch (\Throwable $e) {
      $this->getLogger()->error(
        'MetVocabService: failed to parse vocabulary file: @msg',
        ['@msg' => $e->getMessage()],
      );
      return $this->emptyIndex();
    }
  }

  /**
   * Register SKOS and related prefix → URI mappings into EasyRdf.
   *
   * EasyRdf's RdfNamespace is a static registry, so registrations persist for
   * the full request. Safe to call multiple times.
   */
  private function registerNamespaces(): void {
    RdfNamespace::set('skos', 'http://www.w3.org/2004/02/skos/core#');
    RdfNamespace::set('isothes', 'http://purl.org/iso25964/skos-thes#');
    RdfNamespace::set('dc', 'http://purl.org/dc/terms/');
    RdfNamespace::set('sosa', 'http://www.w3.org/ns/sosa/');
    RdfNamespace::set('rdfs', 'http://www.w3.org/2000/01/rdf-schema#');
  }

  /**
   * Walk the EasyRdf graph and produce the serialisable index.
   *
   * @param \EasyRdf\Graph $graph
   *   The parsed graph.
   *
   * @return array
   *   The normalized index.
   */
  private function indexGraph(Graph $graph): array {
    $index = $this->emptyIndex();

    // 1. Index all collections / concept groups.
    foreach ($graph->allOfType('skos:Collection') as $collection) {
      try {
        $this->indexCollection($collection, $index);
      }
      catch (\Throwable $e) {
        $this->getLogger()->warning(
          'MetVocabService: skipping malformed collection @uri — @msg',
          ['@uri' => $collection->getUri(), '@msg' => $e->getMessage()],
        );
      }
    }

    // 2. Index all concepts.
    foreach ($graph->allOfType('skos:Concept') as $concept) {
      try {
        $this->indexConcept($concept, $index);
      }
      catch (\Throwable $e) {
        $this->getLogger()->warning(
          'MetVocabService: skipping malformed concept @uri — @msg',
          ['@uri' => $concept->getUri(), '@msg' => $e->getMessage()],
        );
      }
    }

    // 3. Back-link concepts to their containing group via skos:member.
    foreach ($index['groups'] as $key => $group) {
      foreach ($group['member_uris'] as $member_uri) {
        if (isset($index['concepts'][$member_uri])
            && $index['concepts'][$member_uri]['group_uri'] === NULL) {
          $index['concepts'][$member_uri]['group_uri'] = $group['uri'];
        }
      }
    }

    // 4. Build per-group label lookup indexes.
    foreach ($index['groups'] as $key => $group) {
      $index['label_index'][$key] = [];
      foreach ($group['member_uris'] as $member_uri) {
        $concept = $index['concepts'][$member_uri] ?? NULL;
        if ($concept === NULL) {
          continue;
        }
        // Index preferred labels (one per language).
        foreach ($concept['labels'] as $lbl) {
          $index['label_index'][$key][mb_strtolower($lbl)] = $member_uri;
        }
        // Index alternative labels (multiple per language).
        foreach ($concept['alt_labels'] as $alts) {
          foreach ($alts as $alt) {
            $index['label_index'][$key][mb_strtolower($alt)] = $member_uri;
          }
        }
      }
    }

    return $index;
  }

  /**
   * Add a single skos:Collection resource to the index.
   *
   * @param \EasyRdf\Resource $resource
   *   The collection resource.
   * @param array $index
   *   The index array, modified in place.
   */
  private function indexCollection(RdfResource $resource, array &$index): void {
    $uri = $resource->getUri();
    $key = $this->uriToKey($uri);

    $member_uris = [];
    foreach ($resource->all('skos:member') as $member) {
      if ($member instanceof RdfResource) {
        $member_uris[] = $member->getUri();
      }
    }

    $index['groups'][$key] = [
      'uri'         => $uri,
      'key'         => $key,
      'labels'      => $this->extractLiterals($resource, 'skos:prefLabel'),
      'definitions' => $this->extractLiterals($resource, 'skos:definition'),
      'member_uris' => $member_uris,
    ];
    // Allow reverse lookup by full URI.
    $index['group_uri_map'][$uri] = $key;
  }

  /**
   * Add a single skos:Concept resource to the index.
   *
   * @param \EasyRdf\Resource $resource
   *   The concept resource.
   * @param array $index
   *   The index array, modified in place.
   */
  private function indexConcept(RdfResource $resource, array &$index): void {
    $uri = $resource->getUri();

    $broader = [];
    foreach ($resource->all('skos:broader') as $b) {
      if ($b instanceof RdfResource) {
        $broader[] = $b->getUri();
      }
    }

    $see_also = [];
    foreach ($resource->all('rdfs:seeAlso') as $s) {
      if ($s instanceof RdfResource) {
        $see_also[] = $s->getUri();
      }
    }

    $index['concepts'][$uri] = [
      'uri'        => $uri,
      'labels'     => $this->extractLiterals($resource, 'skos:prefLabel'),
      'alt_labels' => $this->extractLiteralsMulti($resource, 'skos:altLabel'),
      'definitions' => $this->extractLiterals($resource, 'skos:definition'),
      'broader'    => $broader,
      'see_also'   => $see_also,
      // Filled by back-link pass in indexGraph().
      'group_uri'  => NULL,
    ];
  }

  /**
   * Assemble a public concept info array from the index.
   *
   * @param string $uri
   *   The concept URI.
   * @param string $lang
   *   Language preference.
   * @param array $index
   *   The full vocabulary index.
   *
   * @return array|null
   *   Concept info array or NULL when URI not present in index.
   */
  private function buildConceptInfo(string $uri, string $lang, array $index): ?array {
    $concept = $index['concepts'][$uri] ?? NULL;
    if ($concept === NULL) {
      return NULL;
    }

    $group_uri = $concept['group_uri'];
    $group_key = $group_uri !== NULL
      ? ($index['group_uri_map'][$group_uri] ?? NULL)
      : NULL;
    $group = $group_key !== NULL ? ($index['groups'][$group_key] ?? NULL) : NULL;

    // Build shallow broader list (no recursion).
    $broader = [];
    foreach ($concept['broader'] as $broader_uri) {
      $b = $index['concepts'][$broader_uri] ?? NULL;
      if ($b === NULL) {
        continue;
      }
      $b_group_uri = $b['group_uri'] ?? NULL;
      $b_group_key = $b_group_uri !== NULL
        ? ($index['group_uri_map'][$b_group_uri] ?? NULL)
        : NULL;
      $b_group = $b_group_key !== NULL ? ($index['groups'][$b_group_key] ?? NULL) : NULL;
      $broader[] = [
        'uri'         => $b['uri'],
        'pref_label'  => $this->resolveLang($b['labels'], $lang),
        'alt_labels'  => $this->resolveLangMulti($b['alt_labels'], $lang),
        'definition'  => $this->resolveLang($b['definitions'], $lang),
        'group_uri'   => $b_group_uri ?? '',
        'group_label' => $b_group !== NULL
          ? $this->resolveLang($b_group['labels'], $lang)
          : '',
        'see_also'    => $b['see_also'] ?? [],
      ];
    }

    return [
      'uri'         => $uri,
      'pref_label'  => $this->resolveLang($concept['labels'], $lang),
      'alt_labels'  => $this->resolveLangMulti($concept['alt_labels'], $lang),
      'definition'  => $this->resolveLang($concept['definitions'], $lang),
      'group_uri'   => $group_uri ?? '',
      'group_label' => $group !== NULL
        ? $this->resolveLang($group['labels'], $lang)
        : '',
      'see_also'    => $concept['see_also'] ?? [],
      'broader'     => $broader,
    ];
  }

  // ---------------------------------------------------------------------------
  // Low-level helpers.
  // ---------------------------------------------------------------------------

  /**
   * Extract a single literal per language for a property.
   *
   * When a language has multiple values only the first one is kept.
   *
   * @param \EasyRdf\Resource $resource
   *   The RDF resource.
   * @param string $property
   *   The prefixed property name (e.g. "skos:prefLabel").
   *
   * @return array<string, string>
   *   Map of language code → literal string, e.g. ['en' => 'CC-BY-4.0'].
   */
  private function extractLiterals(RdfResource $resource, string $property): array {
    $result = [];
    foreach ($resource->all($property) as $value) {
      if (!($value instanceof Literal)) {
        continue;
      }
      $lang = $value->getLang() ?: 'und';
      // Keep only first value per language.
      $result[$lang] ??= (string) $value;
    }
    return $result;
  }

  /**
   * Extract all literals per language for a property.
   *
   * @param \EasyRdf\Resource $resource
   *   The RDF resource.
   * @param string $property
   *   The prefixed property name (e.g. "skos:altLabel").
   *
   * @return array<string, string[]>
   *   Map of language code → list of literal strings.
   */
  private function extractLiteralsMulti(RdfResource $resource, string $property): array {
    $result = [];
    foreach ($resource->all($property) as $value) {
      if (!($value instanceof Literal)) {
        continue;
      }
      $lang = $value->getLang() ?: 'und';
      $result[$lang][] = (string) $value;
    }
    return $result;
  }

  /**
   * Resolve a single string from a lang-keyed map with fallback.
   *
   * Fallback order: $lang → 'en' → first available.
   *
   * @param array<string, string> $values
   *   Lang-keyed string map.
   * @param string $lang
   *   Preferred language.
   *
   * @return string
   *   Resolved string or empty string when map is empty.
   */
  private function resolveLang(array $values, string $lang): string {
    return $values[$lang]
      ?? $values['en']
      ?? (empty($values) ? '' : (string) reset($values));
  }

  /**
   * Resolve a string list from a lang-keyed map with fallback.
   *
   * Fallback order: $lang → 'en' → first available.
   *
   * @param array<string, string[]> $values
   *   Lang-keyed lists map.
   * @param string $lang
   *   Preferred language.
   *
   * @return string[]
   *   Resolved list or empty array.
   */
  private function resolveLangMulti(array $values, string $lang): array {
    return $values[$lang]
      ?? $values['en']
      ?? (empty($values) ? [] : (array) reset($values));
  }

  /**
   * Extract the last path segment of a URI as a stable group key.
   *
   * E.g. "https://vocab.met.no/mmd/Use_Constraint" → "Use_Constraint".
   *
   * @param string $uri
   *   The full URI.
   *
   * @return string
   *   The last path segment.
   */
  private function uriToKey(string $uri): string {
    return basename($uri);
  }

  /**
   * Resolve a user-supplied key or full URI to an internal group key.
   *
   * @param string $collection_key
   *   Group key (last path segment) or full group URI.
   * @param array $index
   *   The vocabulary index.
   *
   * @return string|null
   *   Internal group key, or NULL when not found.
   */
  private function resolveGroupKey(string $collection_key, array $index): ?string {
    // Direct key match.
    if (isset($index['groups'][$collection_key])) {
      return $collection_key;
    }
    // Attempt reverse lookup via full URI.
    if (isset($index['group_uri_map'][$collection_key])) {
      return $index['group_uri_map'][$collection_key];
    }
    return NULL;
  }

  /**
   * Return an empty but structurally valid index.
   *
   * @return array
   *   Empty index with correct structure.
   */
  private function emptyIndex(): array {
    return [
      'version'       => self::INDEX_VERSION,
      'concepts'      => [],
      'groups'        => [],
      'group_uri_map' => [],
      'label_index'   => [],
    ];
  }

}
