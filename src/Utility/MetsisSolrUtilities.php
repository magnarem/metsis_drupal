<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Utility;

/**
 * Small utility functions for Metsis Solr implementation.
 */
final class MetsisSolrUtilities {

  /**
   * Allowed metadata/Solr identifier pattern.
   */
  private const SOLR_ID_PATTERN = '/^[A-Za-z0-9_.:-]+$/';

  /**
   * Converts a metadata identifier to a Solr-compatible ID field syntax.
   *
   * This function replaces ':', '/', and '.' with '-' in the input string.
   *
   * The same function is also used by the solr-indexer when converting
   * metadata_identifier to solr id field. Those two need to match
   *
   * @param string $id
   *   The metadata identifier to convert.
   *
   * @return string
   *   The Solr-compatible ID field syntax.
   */
  public static function toSolrId(string $id): string {
    // List of characters to replace.
    $idReplacements = [':', '/', '.'];

    // Replace each character in the list with a hyphen (-).
    $solr_id = str_replace($idReplacements, '-', $id);

    return $solr_id;
  }

  /**
   * Validate metadata identifier format used across search/export features.
   *
   * @param string $id
   *   Candidate identifier.
   *
   * @return bool
   *   TRUE when identifier is non-empty and follows the allowed pattern.
   */
  public static function isValidIdentifier(string $id): bool {
    return $id !== '' && preg_match(self::SOLR_ID_PATTERN, $id) === 1;
  }

}
