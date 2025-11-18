<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Utility;

/**
 * Small service helper for Metsis search related utilities.
 *
 * This class is a thin wrapper for utility functions and provides a place to
 * add additional helper methods in the future. It may delegate to static
 * helpers such as WktHelper.
 */
final class MetsisSearchHelper {

  /**
   * Convert a Solr ENVELOPE WKT to a POLYGON WKT.
   *
   * Delegates to WktHelper::envelopeWktToPolygonWkt for now.
   *
   * @param string $envelope_wkt
   *   The ENVELOPE WKT string.
   *
   * @return string
   *   The POLYGON WKT string.
   */
  public function envelopeToPolygon(string $envelope_wkt): string {
    return WktHelper::envelopeWktToPolygonWkt($envelope_wkt);
  }

}
