<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\metsis_drupal\EventSubscriber\SearchApiSolrSubscriber;
use Drupal\metsis_drupal\Service\ConfigProvider;
use Drupal\metsis_drupal\Utility\MetsisHelper;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\ParseMode\ParseModeInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api_solr\Event\PostConvertedQueryEvent;
use Drupal\search_api_solr\Event\PreQueryEvent;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Solarium\QueryType\Select\Query\Query;

/**
 * Tests complete METSIS Solarium query modes without a mandatory tag.
 */
#[CoversClass(SearchApiSolrSubscriber::class)]
final class SearchApiSolrSubscriberTest extends UnitTestCase {

  /**
   * Tests transformer/parameter consistency and status isolation.
   */
  #[DataProvider('modes')]
  public function testModes(bool $flat, bool $inactive, string $index_id): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn([]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $subscriber = new SearchApiSolrSubscriber(
      new ConfigProvider($factory),
      (new \ReflectionClass(MetsisHelper::class))->newInstanceWithoutConstructor(),
      $logger_factory,
    );
    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn($index_id);
    $parse = $this->createMock(ParseModeInterface::class);
    $parse->method('getPluginId')->willReturn('edismax');
    $query = $this->createMock(QueryInterface::class);
    $query->method('getIndex')->willReturn($index);
    $query->method('getParseMode')->willReturn($parse);
    $query->method('getKeys')->willReturn('wind');
    $query->method('hasTag')->willReturnCallback(static fn (string $tag): bool => ($tag === 'flat_search' && $flat) || ($tag === 'inactive' && $inactive));
    $solr = new Query();
    $solr->setQuery('full_text:wind');
    $solr->createFilterQuery('facet_test')->setQuery('activity_type:Observation')->addTag('facet:activity');
    $subscriber->preQuery(new PreQueryEvent($query, $solr));
    $subscriber->postConvertedQuery(new PostConvertedQueryEvent($query, $solr));
    $request = $solr->getRequestBuilder()->build($solr);
    self::assertNotEmpty($request->getParams());

    if ($index_id !== 'metsis') {
      self::assertNull($solr->getFilterQuery('active_filter'));
      self::assertNotContains('total_children:[subquery]', $solr->getFields());
      return;
    }
    $status = $inactive ? 'Inactive' : 'Active';
    self::assertSame('metadata_status:' . $status, $solr->getFilterQuery('active_filter')->getQuery());
    if ($flat) {
      self::assertNotContains('total_children:[subquery]', $solr->getFields());
      self::assertNotContains('found_children:[subquery]', $solr->getFields());
      self::assertContains('parent:[subquery]', $solr->getFields());
      self::assertSame(['isParent:"true"', 'metadata_status:' . $status], $solr->getParams()['parent.fq']);
      self::assertArrayNotHasKey('metsis_child_join_q', $solr->getParams());
      self::assertSame('activity_type:Observation', $solr->getFilterQuery('facet_test')->getQuery());
      self::assertSame('full_text:wind', $solr->getQuery());
    }
    else {
      self::assertContains('found_children:[subquery]', $solr->getFields());
      self::assertContains('total_children:[subquery]', $solr->getFields());
      self::assertStringContainsString('metadata_status:' . $status, $solr->getParams()['metsis_child_text_q']);
      self::assertNotContains('collection:()', $solr->getParams()['total_children.fq']);
      self::assertContains('metadata_status:' . $status, $solr->getParams()['total_children.fq']);
    }
  }

  /**
   * Provides query modes.
   */
  public static function modes(): array {
    return [
      'untagged grouped active' => [FALSE, FALSE, 'metsis'],
      'untagged grouped inactive' => [FALSE, TRUE, 'metsis'],
      'flat active' => [TRUE, FALSE, 'metsis'],
      'flat inactive' => [TRUE, TRUE, 'metsis'],
      'unrelated index' => [FALSE, FALSE, 'other'],
    ];
  }

}
