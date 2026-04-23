<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

/**
 * Interface for the Met vocabulary service.
 *
 * Provides lookup methods over the MMD SKOS vocabulary parsed from the
 * vendor-supplied Turtle file (vendor/metno/mmd/thesauri/mmd-vocabulary.ttl).
 *
 * Results are keyed against a dedicated Drupal cache bin with a configurable
 * TTL (default 24 h) and refreshed on cron or on demand.
 *
 * Concept info arrays returned by all methods share the following shape:
 * @code
 *   [
 *     'uri'         => string,    // Full concept URI.
 *     'pref_label'  => string,    // Preferred label in the resolved language.
 *     'alt_labels'  => string[],  // Alternative labels in the resolved language.
 *     'definition'  => string,    // Human-readable definition.
 *     'group_uri'   => string,    // URI of the containing collection.
 *     'group_label' => string,    // Label of the containing collection.
 *     'see_also'    => string[],  // rdfs:seeAlso URIs (external links).
 *     'broader'     => array[],   // Direct broader concepts (same keys, no nested broader).
 *   ]
 * @endcode
 *
 * Language resolution order: requested $lang → 'en' → first available.
 */
interface MetVocabServiceInterface {

  /**
   * Look up a concept by label within a vocabulary collection.
   *
   * Matches case-insensitively against skos:prefLabel and skos:altLabel.
   *
   * @param string $collection_key
   *   The collection's last URI path segment, e.g. "Use_Constraint" or
   *   "Keywords_Vocabulary". The full URI is also accepted.
   * @param string $label
   *   The concept label to search for.
   * @param string $lang
   *   ISO 639-1 language preference.
   *
   * @return array|null
   *   Concept info array or NULL when no match is found.
   */
  public function lookupByLabel(string $collection_key, string $label, string $lang = 'en'): ?array;

  /**
   * Look up a concept by its full URI.
   *
   * @param string $uri
   *   Full concept URI ex. "https://vocab.met.no/mmd/Use_Constraint/CC-BY-4.0".
   * @param string $lang
   *   ISO 639-1 language preference.
   *
   * @return array|null
   *   Concept info array or NULL when the URI is not found.
   */
  public function lookupByUri(string $uri, string $lang = 'en'): ?array;

  /**
   * Return metadata about a vocabulary collection / concept group.
   *
   * @param string $collection_key
   *   Last URI path segment ("Use_Constraint") or full collection URI.
   * @param string $lang
   *   ISO 639-1 language preference.
   *
   * @return array|null
   *   Group info array:
   *   - uri (string) Full collection URI.
   *   - label (string) Collection label.
   *   - definition (string) Collection definition.
   *   - member_count (int) Number of direct member concepts.
   *   NULL when the collection is not found.
   */
  public function getGroup(string $collection_key, string $lang = 'en'): ?array;

  /**
   * Return the first broader (parent) concept of a concept URI.
   *
   * @param string $uri
   *   The full concept URI to find the parent for.
   * @param string $lang
   *   ISO 639-1 language preference.
   *
   * @return array|null
   *   Concept info array or NULL when no skos:broader is defined.
   */
  public function getParent(string $uri, string $lang = 'en'): ?array;

  /**
   * Return all concepts that are direct members of a collection.
   *
   * @param string $collection_key
   *   Last URI path segment or full collection URI.
   * @param string $lang
   *   ISO 639-1 language preference.
   *
   * @return array
   *   Indexed array of concept info arrays (same shape as lookupByUri()).
   *   Empty array when the collection is not found or has no members.
   */
  public function getGroupConcepts(string $collection_key, string $lang = 'en'): array;

  /**
   * Refresh the vocabulary cache.
   *
   * @param bool $force
   *   FALSE (default): only rebuild when the cache is missing or expired.
   *   TRUE: always re-parse the source regardless of TTL; useful for manual
   *   invalidation after a vocabulary file update.
   */
  public function refresh(bool $force = FALSE): void;

}
