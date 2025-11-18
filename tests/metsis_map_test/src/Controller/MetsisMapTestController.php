<?php

namespace Drupal\metsis_map_test\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Returns responses for Metsis Map test routes.
 */
class MetsisMapTestController extends ControllerBase {

  /**
   * Returns the test page for the Metsis Map JS library.
   */
  public function testPage() {
    return [
      '#theme' => 'metsis_map_test_page',
      '#attached' => [
        'library' => [
          'metsis_drupal/metsis_map',
          // 'metsis_map_test/metsis_map_test_behaviours',
        ],
        'drupalSettings' => [
          'metsis_map_test' => [
            'message' => 'Metsis Map Test Page Loaded',
            'geojson' => '{
  "type": "Feature",
  "geometry": {
    "type": "Polygon",
    "coordinates": [
      [
        [
          170,
          -77
        ],
        [
          190,
          -77
        ],
        [
          190,
          -72
        ],
        [
          170,
          -72
        ],
        [
          170,
          -77
        ]
      ]
    ]
  },
  "properties": null
}',
          ],
        ],
      ],
    ];
  }

}
