<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\metsis_drupal\Plugin\search_api\processor\GeoJsonTransformer;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\views\ViewExecutable;

/**
 * Tests map data isolation and clearing empty search results.
 */
final class GeoJsonTransformerTest extends UnitTestCase {

  /**
   * Tests raw geometry fallback and a subsequent empty result collection.
   */
  public function testScopedResults(): void {
    $view = $this->createMock(ViewExecutable::class);
    $view->dom_id = 'custom-view';
    $query = $this->createMock(QueryInterface::class);
    $query->method('getProcessingLevel')->willReturn(QueryInterface::PROCESSING_FULL);
    $query->method('getOption')->with('search_api_view')->willReturn($view);
    $item = $this->createMock(ItemInterface::class);
    $item->method('getExtraData')->with('search_api_solr_document')->willReturn([
      'geometry_geojson' => '{"type":"Point","coordinates":[10,60]}',
      'title' => 'Custom result',
    ]);
    $item->method('getId')->willReturn('dataset');
    $results = $this->createMock(ResultSetInterface::class);
    $results->method('getQuery')->willReturn($query);
    $results->method('getResultItems')->willReturn([$item]);
    $processor = new GeoJsonTransformer([], 'geojson_transformer', []);
    $processor->postprocessSearchResults($results);
    $collection = $view->element['#attached']['drupalSettings']['metsis_drupal']['search']['instances']['custom-view']['geojson_feature_collection'];
    self::assertSame('Custom result', $collection['features'][0]['properties']['title']);
    self::assertSame([10, 60], $collection['features'][0]['geometry']['coordinates']);
    $empty = $this->createMock(ResultSetInterface::class);
    $empty->method('getQuery')->willReturn($query);
    $empty->method('getResultItems')->willReturn([]);
    $processor->postprocessSearchResults($empty);
    self::assertSame([], $view->element['#attached']['drupalSettings']['metsis_drupal']['search']['instances']['custom-view']['geojson_feature_collection']['features']);
  }

}
