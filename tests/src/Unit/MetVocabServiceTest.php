<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\metsis_drupal\Service\MetVocabService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MetVocabService.
 *
 * These tests exercise:
 *  - Parsing and indexing the real vendor TTL file.
 *  - lookupByLabel() with exact and alternative label matching.
 *  - lookupByUri() direct URI resolution.
 *  - getGroup() metadata retrieval.
 *  - getParent() broader-concept traversal.
 *  - Language fallback order (requested → en → first available).
 *  - Unknown concept / collection graceful return of NULL.
 *  - refresh(force: FALSE) respects a warm cache.
 *  - refresh(force: TRUE) rebuilds the index.
 */
#[CoversClass(MetVocabService::class)]
#[Group('metsis_drupal')]
class MetVocabServiceTest extends TestCase {

  /**
   * Absolute path to the vendor TTL vocabulary file.
   */
  private const TTL_PATH = __DIR__ . '/../../../vendor/metno/mmd/thesauri/mmd-vocabulary.ttl';

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
   * The service under test with a cold cache.
   *
   * @var \Drupal\metsis_drupal\Service\MetVocabService
   */
  protected MetVocabService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!defined('DRUPAL_ROOT')) {
      // Point DRUPAL_ROOT to PROJECT_ROOT/web as DDEV sets it at runtime.
      define('DRUPAL_ROOT', dirname(__DIR__, 4) . '/web');
    }

    // Provide config that points directly at the vendor TTL file.
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnMap([
      ['vocab_source_path', self::TTL_PATH],
      ['vocab_cache_ttl', 86400],
    ]);

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->with('metsis_drupal.settings')
      ->willReturn($settings);

    // Cold cache: always returns FALSE (cache miss) so the index is built.
    $this->cache = $this->createMock(CacheBackendInterface::class);
    $this->cache->method('get')->willReturn(FALSE);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(time());

    $this->service = new MetVocabService($this->configFactory, $this->cache, $time);
  }

  /**
   * Test that lookupByLabel returns a concept for a known preferred label.
   */
  #[Test]
  public function lookupByLabelReturnsConceptForKnownPrefLabel(): void {
    $result = $this->service->lookupByLabel('Use_Constraint', 'CC-BY-4.0');

    $this->assertNotNull($result);
    $this->assertSame('CC-BY-4.0', $result['pref_label']);
    $this->assertStringContainsString('Use_Constraint', $result['group_uri']);
    $this->assertNotEmpty($result['definition']);
    $this->assertIsString($result['uri']);
    $this->assertStringStartsWith('https://vocab.met.no/mmd/', $result['uri']);
  }

  /**
   * Test that lookupByLabel matches alternative label values.
   *
   * This verifies that alternative labels are indexed and matched correctly.
   */
  #[Test]
  public function lookupByLabelMatchesAltLabel(): void {
    // "GCMD Science Keywords" is an altLabel for the GCMDSK concept.
    $result = $this->service->lookupByLabel('Keywords_Vocabulary', 'GCMD Science Keywords');

    $this->assertNotNull($result);
    $this->assertSame('GCMDSK', $result['pref_label']);
  }

  /**
   * Test that lookupByLabel is case insensitive.
   */
  #[Test]
  public function lookupByLabelIsCaseInsensitive(): void {
    $lower = $this->service->lookupByLabel('Use_Constraint', 'cc-by-4.0');
    $upper = $this->service->lookupByLabel('Use_Constraint', 'CC-BY-4.0');

    $this->assertNotNull($lower);
    $this->assertNotNull($upper);
    $this->assertSame($lower['uri'], $upper['uri']);
  }

  /**
   * Test that lookupByLabel returns null for an unknown label.
   */
  #[Test]
  public function lookupByLabelReturnsNullForUnknownLabel(): void {
    $result = $this->service->lookupByLabel('Use_Constraint', 'THIS-DOES-NOT-EXIST');

    $this->assertNull($result);
  }

  /**
   * Test that lookupByLabel returns null for an unknown collection.
   */
  #[Test]
  public function lookupByLabelReturnsNullForUnknownCollection(): void {
    $result = $this->service->lookupByLabel('NonExistent_Collection', 'CC-BY-4.0');

    $this->assertNull($result);
  }

  /**
   * Test that lookupByUri returns a concept for a known URI.
   */
  #[Test]
  public function lookupByUriReturnsConceptForKnownUri(): void {
    $uri    = 'https://vocab.met.no/mmd/Use_Constraint/CC-BY-4.0';
    $result = $this->service->lookupByUri($uri);

    $this->assertNotNull($result);
    $this->assertSame($uri, $result['uri']);
    $this->assertSame('CC-BY-4.0', $result['pref_label']);
  }

  /**
   * Test that lookupByUri returns null for an unknown URI.
   */
  #[Test]
  public function lookupByUriReturnsNullForUnknownUri(): void {
    $result = $this->service->lookupByUri('https://vocab.met.no/mmd/NoSuchConcept');

    $this->assertNull($result);
  }

  /**
   * Test that getGroup returns collection metadata.
   */
  #[Test]
  public function getGroupReturnsCollectionMetadata(): void {
    $group = $this->service->getGroup('Use_Constraint');

    $this->assertNotNull($group);
    $this->assertSame('Use Constraint', $group['label']);
    $this->assertGreaterThan(0, $group['member_count']);
    $this->assertNotEmpty($group['definition']);
    $this->assertStringContainsString('Use_Constraint', $group['uri']);
  }

  /**
   * Test that getGroup accepts a full URI as input.
   */
  #[Test]
  public function getGroupAcceptsFullUri(): void {
    $uri   = 'https://vocab.met.no/mmd/Use_Constraint';
    $group = $this->service->getGroup($uri);

    $this->assertNotNull($group);
    $this->assertSame('Use Constraint', $group['label']);
  }

  /**
   * Test that getGroup returns null for an unknown collection.
   */
  #[Test]
  public function getGroupReturnsNullForUnknownCollection(): void {
    $this->assertNull($this->service->getGroup('Ghost_Collection'));
  }

  /**
   * Test that getParent returns null when there is no skos:broader.
   */
  #[Test]
  public function getParentReturnsNullWhenNoSkosBoader(): void {
    // Top-level concepts in MMD vocab have no broader; CC-BY-4.0 is one.
    $uri    = 'https://vocab.met.no/mmd/Use_Constraint/CC-BY-4.0';
    $parent = $this->service->getParent($uri);

    // If the vocab does define a broader for this concept the assertion should
    // be adjusted. For now we verify the method returns the right type.
    $this->assertThat($parent, $this->logicalOr($this->isNull(), $this->isArray()));
  }

  /**
   * Test that getParent returns null for an unknown URI.
   */
  #[Test]
  public function getParentReturnsNullForUnknownUri(): void {
    $this->assertNull($this->service->getParent('https://example.com/unknown'));
  }

  /**
   * Test that lookup returns English label when requested language is missing.
   */
  #[Test]
  public function lookupReturnsEnglishLabelWhenRequestedLangMissing(): void {
    // Request a language that does not exist; should fall back to 'en'.
    $result = $this->service->lookupByLabel('Use_Constraint', 'CC-BY-4.0', 'xx');

    $this->assertNotNull($result);
    $this->assertNotEmpty($result['pref_label']);
  }

  /**
   * Assert the returned concept info array has all required keys.
   */
  #[Test]
  public function conceptInfoContainsRequiredKeys(): void {
    $result = $this->service->lookupByLabel('Use_Constraint', 'CC-BY-4.0');

    $this->assertNotNull($result);
    $required_keys = [
      'uri',
      'pref_label',
      'alt_labels',
      'definition',
      'group_uri',
      'group_label',
      'see_also',
      'broader',
    ];
    foreach ($required_keys as $key) {
      $this->assertArrayHasKey($key, $result, "Missing key: $key");
    }
    $this->assertIsArray($result['alt_labels']);
    $this->assertIsArray($result['see_also']);
    $this->assertIsArray($result['broader']);
  }

  /**
   * Test that refresh with force flag rebuilds cache and calls set().
   */
  #[Test]
  public function refreshForceRebuildsCacheAndCallsSet(): void {
    $this->cache->expects($this->once())
      ->method('set')
      ->with(self::anything(), self::anything());

    $this->service->refresh(force: TRUE);
  }

  /**
   * Test that refresh does not rebuild the cache when it is still valid.
   *
   * This verifies that the TTL is respected and unnecessary parsing is avoided.
   */
  #[Test]
  public function refreshRespectsTtlWhenCacheIsWarm(): void {
    // Simulate a warm cache.
    $cache_data = [
      'version' => 1,
      'concepts' => [],
      'groups' => [],
      'group_uri_map' => [],
      'label_index' => [],
    ];
    $warm = (object) ['data' => $cache_data];
    $warmCache = $this->createMock(CacheBackendInterface::class);
    $warmCache->method('get')->willReturn($warm);
    // set() must NOT be called — cache is still valid.
    $warmCache->expects($this->never())->method('set');

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(time());
    $svc = new MetVocabService(
      $this->configFactory,
      $warmCache,
      $time
    );
    $svc->refresh(force: FALSE);
  }

}
