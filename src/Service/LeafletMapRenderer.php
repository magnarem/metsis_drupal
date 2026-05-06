<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

use Drupal\leaflet\LeafletService;

/**
 * Renders Leaflet maps from geometry data.
 *
 * Encapsulates Leaflet-specific rendering logic separate from search helpers.
 */
final class LeafletMapRenderer {

  /**
   * Constructor.
   */
  public function __construct(
    private readonly LeafletService $leaflet,
  ) {}

  /**
   * Build a render array for a Leaflet map from geometry.
   *
   * @param string $geometry
   *   The geometry data (typically GeoJSON or WKT).
   * @param string $height
   *   The map container height (CSS string, e.g., "400px").
   *
   * @return array
   *   A render array for the Leaflet map.
   */
  public function buildLeafletMap(string $geometry, string $height): array {
    $map = $this->leaflet->leafletMapGetInfo('openstreetmap');
    $map['settings']['leaflet_markercluster'] = ['control' => FALSE];
    $map['settings']['zoomControl'] = FALSE;
    $map['settings']['zoom'] = 10;
    $feature = $this->leaflet->leafletProcessGeofield($geometry);

    return $this->leaflet->leafletRenderMap($map, $feature, $height);
  }

  /**
   * Get the underlying Leaflet service.
   *
   * @return \Drupal\leaflet\LeafletService
   *   The Leaflet service.
   */
  public function getLeafletService(): LeafletService {
    return $this->leaflet;
  }

}
