<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\metsis_drupal\Service\MetVocabService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MetVocabService internal logic.
 */
#[CoversClass(MetVocabService::class)]
#[Group('metsis_drupal')]
class MetVocabServiceTest extends TestCase {

  /**
   * Config factory mock.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected ConfigFactoryInterface&MockObject $configFactory;

  /**
   * Cache backend mock.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected CacheBackendInterface&MockObject $cache;

  /**
   * Logger mock.
   *
   * @var \Psr\Log\LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected LoggerInterface&MockObject $logger;

  /**
   * Service under test.
   *
   * @var \Drupal\metsis_drupal\Service\MetVocabService
   */
  protected MetVocabService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnMap([
      ['vocab_source_path', '/tmp/non-existent-vocab.ttl'],
      ['vocab_cache_ttl', 86400],
    ]);

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->with('metsis_drupal.settings')
      ->willReturn($settings);

    $this->cache = $this->createMock(CacheBackendInterface::class);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(time());

    $this->logger = $this->createMock(LoggerInterface::class);

    $this->service = new MetVocabService($this->configFactory, $this->cache, $time);
    $this->service->setLogger($this->logger);
  }

  /**
   * Tests fallback order in resolveLang().
   */
  #[Test]
  public function resolveLangUsesRequestedThenEnglishThenFirst(): void {
    $method = new \ReflectionMethod(MetVocabService::class, 'resolveLang');

    $value = $method->invoke(
      $this->service,
      ['nb' => 'Norsk', 'en' => 'English'],
      'nb',
    );
    $this->assertSame('Norsk', $value);

    $value = $method->invoke(
      $this->service,
      ['en' => 'English', 'fr' => 'Francais'],
      'xx',
    );
    $this->assertSame('English', $value);

    $value = $method->invoke(
      $this->service,
      ['fr' => 'Francais'],
      'xx',
    );
    $this->assertSame('Francais', $value);

    $value = $method->invoke(
      $this->service,
      [],
      'xx',
    );
    $this->assertSame('', $value);
  }

  /**
   * Tests fallback order in resolveLangMulti().
   */
  #[Test]
  public function resolveLangMultiUsesRequestedThenEnglishThenFirst(): void {
    $method = new \ReflectionMethod(MetVocabService::class, 'resolveLangMulti');

    $value = $method->invoke(
      $this->service,
      ['nb' => ['en', 'to'], 'en' => ['one']],
      'nb',
    );
    $this->assertSame(['en', 'to'], $value);

    $value = $method->invoke(
      $this->service,
      ['en' => ['one'], 'fr' => ['un']],
      'xx',
    );
    $this->assertSame(['one'], $value);

    $value = $method->invoke(
      $this->service,
      ['fr' => ['un']],
      'xx',
    );
    $this->assertSame(['un'], $value);

    $value = $method->invoke(
      $this->service,
      [],
      'xx',
    );
    $this->assertSame([], $value);
  }

  /**
   * Tests that uriToKey() returns the URI basename.
   */
  #[Test]
  public function uriToKeyReturnsLastPathSegment(): void {
    $method = new \ReflectionMethod(MetVocabService::class, 'uriToKey');

    $this->assertSame(
      'Use_Constraint',
      $method->invoke($this->service, 'https://vocab.met.no/mmd/Use_Constraint'),
    );
  }

  /**
   * Tests that refresh(false) exits early on warm top-level cache.
   */
  #[Test]
  public function refreshRespectsWarmCache(): void {
    $warm = (object) [
      'data' => [
        'version' => 1,
        'concepts' => [],
        'groups' => [],
        'group_uri_map' => [],
        'label_index' => [],
      ],
    ];

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn($warm);
    $cache->expects($this->never())->method('set');

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(time());

    $service = new MetVocabService($this->configFactory, $cache, $time);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('debug');
    $service->setLogger($logger);

    $service->refresh(force: FALSE);
  }

  /**
   * Tests that refresh(true) rebuilds and stores the top-level index.
   */
  #[Test]
  public function refreshForceRebuildsIndexEvenWithColdCache(): void {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->atLeastOnce())->method('set');

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(time());

    $service = new MetVocabService($this->configFactory, $cache, $time);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->atLeastOnce())->method('error');
    $service->setLogger($logger);

    $service->refresh(force: TRUE);
  }

}
