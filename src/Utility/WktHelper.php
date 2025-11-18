<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Utility;

/**
 * Small utility functions for WKT conversion and parsing.
 */
final class WktHelper {

  /**
   * Converts a Solr ENVELOPE string to a WKT POLYGON using the right-hand rule.
   *
   * @param string $envelope
   *   The ENVELOPE string in the format: ENVELOPE(minX, maxX, maxY, minY).
   *
   * @return string
   *   The WKT POLYGON string in counterclockwise order.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if the input string is invalid.
   */
  public static function envelopeWktToPolygonWkt(string $envelope): string {
    // Validate the ENVELOPE string format using a regex pattern.
    $_envelope = trim($envelope);
    if (!preg_match('/^ENVELOPE\((\s*-?\d+.?\d+),(\s*-?\d+.?\d+),(\s*-?\d+.?\d+),(\s*-?\d+.?\d+)\)/', $_envelope, $matches)) {
      throw new \InvalidArgumentException('Invalid ENVELOPE string format. Expected format: ENVELOPE(minX, maxX, maxY, minY).');
    }

    // Extract minX, maxX, maxY, and minY from the matched groups.
    $minX = (float) $matches[1];
    $maxX = (float) $matches[2];
    $maxY = (float) $matches[3];
    $minY = (float) $matches[4];

    // Ensure valid bounds for longitude and latitude.
    if ($minX < -180 || $maxX > 180 || $minY < -90 || $maxY > 90) {
      throw new \InvalidArgumentException('Invalid geographic coordinates. Longitude must be in [-180, 180] and latitude in [-90, 90].');
    }

    // Create WKT POLYGON using the right-hand rule.
    // The order of coordinates is counterclockwise:
    // (minX, minY), (maxX, minY), (maxX, maxY), (minX, maxY), (minX, minY).
    $polygon = sprintf(
    'POLYGON ((%1$f %4$f, %2$f %4$f, %2$f %3$f, %1$f %3$f, %1$f %4$f))',
    $minX,
    $maxX,
    $maxY,
    $minY
    );
    return $polygon;
  }

}
