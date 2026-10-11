<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\leaflet\LeafletService;
use Drupal\metsis_drupal\Service\LeafletMapRenderer;
use Drupal\Tests\UnitTestCase;

/**
 * Tests map identifiers remain unique across separately rendered fragments.
 */
final class LeafletMapRendererTest extends UnitTestCase {

  /**
   * Tests repeated renders never reuse a Leaflet container identifier.
   */
  public function testUniqueFragmentIds(): void {
    $leaflet = $this->createMock(LeafletService::class);
    $leaflet->method('leafletMapGetInfo')->willReturn(['settings' => []]);
    $leaflet->method('leafletProcessGeofield')->willReturn([]);
    $leaflet->method('leafletRenderMap')->willReturnCallback(
      static fn (array $map, array $features, string $height): array => ['#id' => $map['id'], '#height' => $height],
    );
    $renderer = new LeafletMapRenderer($leaflet);
    $first = $renderer->buildLeafletMap('{}', '400px', 'dataset');
    $second = $renderer->buildLeafletMap('{}', '400px', 'dataset');
    self::assertNotSame($first['#id'], $second['#id']);
    self::assertMatchesRegularExpression('/^geometry-map-[a-f0-9]{16}$/', $first['#id']);
    self::assertSame('400px', $first['#height']);
    self::assertSame('400px', $second['#height']);
  }

}
