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

  /**
   * {@inheritdoc}
   */
  public function lookupByLabel(string $collection_key, string $label, string $lang = 'en'): ?array {
    $key = $this->resolveCachedGroupKey($collection_key);
    if ($key === NULL) {
      return NULL;
    }
    $group = $this->getCachedGroup($key);
    if ($group === NULL) {
      return NULL;
    }
    $lower = mb_strtolower($label);
    $uri = $group['label_index'][$lower] ?? NULL;
    if ($uri === NULL) {
      return NULL;
    }
    return $this->buildConceptInfoFromCache($uri, $lang);
  }

  /**
   * {@inheritdoc}
   */
  public function lookupByUri(string $uri, string $lang = 'en'): ?array {
    return $this->buildConceptInfoFromCache($uri, $lang);
  }

  /**
   * {@inheritdoc}
   */
  public function getGroup(string $collection_key, string $lang = 'en'): ?array {
    $key = $this->resolveCachedGroupKey($collection_key);
    if ($key === NULL) {
      return NULL;
    }
    $group = $this->getCachedGroup($key);
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
    $concept = $this->getCachedConcept($uri);
    if ($concept === NULL || empty($concept['broader'])) {
      return NULL;
    }
    return $this->buildConceptInfoFromCache($concept['broader'][0], $lang);
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupConcepts(string $collection_key, string $lang = 'en'): array {
    $key = $this->resolveCachedGroupKey($collection_key);
    if ($key === NULL) {
      return [];
    }
    $group = $this->getCachedGroup($key);
    if ($group === NULL) {
      return [];
    }
    $result = [];
    foreach ($group['member_uris'] as $member_uri) {
      $info = $this->buildConceptInfoFromCache($member_uri, $lang);
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
    // Delete per-group and per-concept CIDs using the existing full index.
    $cached = $this->cache->get(self::CACHE_CID);
    if ($cached !== FALSE && is_array($cached->data)) {
      $cids = [];
      foreach (array_keys($cached->data['groups'] ?? []) as $key) {
        $cids[] = self::GROUP_CID_PREFIX . $key;
      }
      foreach (array_keys($cached->data['concepts'] ?? []) as $uri) {
        $cids[] = self::CONCEPT_CID_PREFIX . md5((string) $uri);
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
   * Return the lightweight cached meta index, warming it from the full index.
   *
   * @return array|null
   *   The cached meta index or NULL when it could not be resolved.
   */
  private function getMetaIndex(): ?array {
    $cached = $this->cache->get(self::INDEX_META_CID);
    if ($cached !== FALSE
        && is_array($cached->data)
        && ($cached->data['version'] ?? 0) === self::INDEX_VERSION) {
      return $cached->data;
    }

    $this->warmDedicatedCaches($this->getIndex());
    $cached = $this->cache->get(self::INDEX_META_CID);
    if ($cached !== FALSE
        && is_array($cached->data)
        && ($cached->data['version'] ?? 0) === self::INDEX_VERSION) {
      return $cached->data;
    }

    return NULL;
  }

  /**
   * Resolve a group key from the dedicated cached meta index.
   *
   * @param string $collection_key
   *   Group key (last path segment) or full group URI.
   *
   * @return string|null
   *   Internal group key, or NULL when not found.
   */
  private function resolveCachedGroupKey(string $collection_key): ?string {
    $meta = $this->getMetaIndex();
    if ($meta === NULL) {
      return NULL;
    }
    if (isset($meta['groups'][$collection_key])) {
      return $collection_key;
    }
    if (isset($meta['group_uri_map'][$collection_key])) {
      return $meta['group_uri_map'][$collection_key];
    }
    return NULL;
  }

  /**
   * Return a cached group payload by group key.
   *
   * @param string $group_key
   *   The internal group key.
   *
   * @return array|null
   *   The cached group payload or NULL when not found.
   */
  private function getCachedGroup(string $group_key): ?array {
    $cached = $this->cache->get(self::GROUP_CID_PREFIX . $group_key);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $this->warmDedicatedCaches($this->getIndex());
    $cached = $this->cache->get(self::GROUP_CID_PREFIX . $group_key);
    return ($cached !== FALSE && is_array($cached->data)) ? $cached->data : NULL;
  }

  /**
   * Return a cached concept payload by URI.
   *
   * @param string $uri
   *   The concept URI.
   *
   * @return array|null
   *   The cached concept payload or NULL when not found.
   */
  private function getCachedConcept(string $uri): ?array {
    $cached = $this->cache->get(self::CONCEPT_CID_PREFIX . md5($uri));
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }

    $this->warmDedicatedCaches($this->getIndex());
    $cached = $this->cache->get(self::CONCEPT_CID_PREFIX . md5($uri));
    return ($cached !== FALSE && is_array($cached->data)) ? $cached->data : NULL;
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
      $this->warmDedicatedCaches($index);
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
   * Assemble a public concept info array using the dedicated cache entries.
   *
   * @param string $uri
   *   The concept URI.
   * @param string $lang
   *   Language preference.
   *
   * @return array|null
   *   Concept info array or NULL when the concept is not cached.
   */
  private function buildConceptInfoFromCache(string $uri, string $lang): ?array {
    $concept = $this->getCachedConcept($uri);
    if ($concept === NULL) {
      return NULL;
    }

    $group = NULL;
    if (!empty($concept['group_uri'])) {
      $group_key = $this->resolveCachedGroupKey($concept['group_uri']);
      if ($group_key !== NULL) {
        $group = $this->getCachedGroup($group_key);
      }
    }

    $broader = [];
    foreach ($concept['broader'] as $broader_uri) {
      $broader_concept = $this->getCachedConcept($broader_uri);
      if ($broader_concept === NULL) {
        continue;
      }

      $broader_group = NULL;
      if (!empty($broader_concept['group_uri'])) {
        $broader_group_key = $this->resolveCachedGroupKey($broader_concept['group_uri']);
        if ($broader_group_key !== NULL) {
          $broader_group = $this->getCachedGroup($broader_group_key);
        }
      }

      $broader[] = [
        'uri'         => $broader_concept['uri'],
        'pref_label'  => $this->resolveLang($broader_concept['labels'], $lang),
        'alt_labels'  => $this->resolveLangMulti($broader_concept['alt_labels'], $lang),
        'definition'  => $this->resolveLang($broader_concept['definitions'], $lang),
        'group_uri'   => $broader_concept['group_uri'] ?? '',
        'group_label' => $broader_group !== NULL
          ? $this->resolveLang($broader_group['labels'], $lang)
          : '',
        'see_also'    => $broader_concept['see_also'] ?? [],
      ];
    }

    return [
      'uri'         => $concept['uri'],
      'pref_label'  => $this->resolveLang($concept['labels'], $lang),
      'alt_labels'  => $this->resolveLangMulti($concept['alt_labels'], $lang),
      'definition'  => $this->resolveLang($concept['definitions'], $lang),
      'group_uri'   => $concept['group_uri'] ?? '',
      'group_label' => $group !== NULL
        ? $this->resolveLang($group['labels'], $lang)
        : '',
      'see_also'    => $concept['see_also'] ?? [],
      'broader'     => $broader,
    ];
  }

  /**
   * Populate the dedicated meta, group, and concept caches from an index.
   *
   * @param array $index
   *   The full vocabulary index.
   */
  private function warmDedicatedCaches(array $index): void {
    $ttl = (int) ($this->configFactory
      ->get('metsis_drupal.settings')
      ->get('vocab_cache_ttl') ?? 86400);
    $expire = $this->time->getRequestTime() + $ttl;
    $meta = [
      'version'       => self::INDEX_VERSION,
      'groups'        => [],
      'group_uri_map' => [],
    ];

    foreach ($index['groups'] as $key => $group) {
      $meta['groups'][$key] = [
        'uri'          => $group['uri'],
        'label'        => $this->resolveLang($group['labels'], 'en'),
        'member_count' => count($group['member_uris']),
      ];
      $meta['group_uri_map'][$group['uri']] = $key;

      $group_payload = $group;
      $group_payload['label_index'] = $index['label_index'][$key] ?? [];
      $this->cache->set(self::GROUP_CID_PREFIX . $key, $group_payload, $expire, ['metsis_vocab']);

      foreach ($group['member_uris'] as $member_uri) {
        $concept = $index['concepts'][$member_uri] ?? NULL;
        if ($concept !== NULL) {
          $this->cache->set(self::CONCEPT_CID_PREFIX . md5($member_uri), $concept, $expire, ['metsis_vocab']);
        }
      }
    }

    $this->cache->set(self::INDEX_META_CID, $meta, $expire, ['metsis_vocab']);
  }

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
