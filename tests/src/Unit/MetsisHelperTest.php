<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\metsis_drupal\Service\FeatureTypeLookupService;
use Drupal\metsis_drupal\Utility\MetsisHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MetsisHelper.
 */
#[CoversClass(MetsisHelper::class)]
#[Group('metsis_drupal')]
final class MetsisHelperTest extends TestCase {

  /**
   * Tests linkify wraps plain text URLs.
   */
  #[Test]
  public function testLinkifyWrapsPlainTextUrls(): void {
    $helper = $this->createHelper();

    $text = 'See https://example.com/data and www.met.no for details.';
    $result = $helper->linkify($text);

    $this->assertStringContainsString('<a href="https://example.com/data" target="_blank" rel="noopener noreferrer">https://example.com/data</a>', $result);
    $this->assertStringContainsString('<a href="http://www.met.no" target="_blank" rel="noopener noreferrer">www.met.no</a>', $result);
  }

  /**
   * Tests linkify skips URLs that are already inside HTML attributes.
   */
  #[Test]
  public function testLinkifySkipsUrlsInsideHtmlAttributes(): void {
    $helper = $this->createHelper();

    $text = '<a href="https://example.com/image"><img src="https://example.com/image" alt="Map"></a> More info: https://example.com/page';
    $result = $helper->linkify($text);

    $this->assertStringContainsString('<a href="https://example.com/image"><img src="https://example.com/image" alt="Map"></a>', $result);
    $this->assertStringContainsString('<a href="https://example.com/page" target="_blank" rel="noopener noreferrer">https://example.com/page</a>', $result);
    $this->assertSame(2, substr_count($result, 'href="https://example.com'));
  }

  /**
   * Tests getModulePath returns the path from the stored module extension.
   */
  #[Test]
  public function testGetModulePathReturnsModuleExtensionPath(): void {
    $helper = $this->createHelper();
    $this->setProperty($helper, 'moduleExtension', new class {

      /**
       * Get the module path.
       */
      public function getPath(): string {
        return 'modules/custom/metsis_drupal';
      }

    });

    $this->assertSame('modules/custom/metsis_drupal', $helper->getModulePath());
  }

  /**
   * Tests envelope to polygon delegates to the WKT helper conversion.
   */
  #[Test]
  public function testEnvelopeToPolygonConvertsEnvelope(): void {
    $helper = $this->createHelper();

    $this->assertSame(
      'POLYGON ((5.000000 58.000000, 10.000000 58.000000, 10.000000 62.000000, 5.000000 62.000000, 5.000000 58.000000))',
      $helper->envelopeToPolygon('ENVELOPE(5.0, 10.0, 62.0, 58.0)')
    );
  }

  /**
   * Tests toSolrId normalizes separators and trims whitespace.
   */
  #[Test]
  public function testToSolrIdNormalizesAndTrims(): void {
    $helper = $this->createHelper();

    $this->assertSame('foo-bar-baz-qux', $helper->toSolrId(' foo/bar:baz.qux '));
  }

  /**
   * Tests lookupFeatureType uses the OPeNDAP service in opendap mode.
   */
  #[Test]
  public function testLookupFeatureTypeUsesOpendapService(): void {
    $helper = $this->createHelper();
    $feature_type_lookup = $this->createMock(FeatureTypeLookupService::class);
    $feature_type_lookup->expects($this->once())
      ->method('lookup')
      ->with('https://opendap.example/data')
      ->willReturn('timeSeries');
    $this->setProperty($helper, 'featureTypeLookup', $feature_type_lookup);

    $this->assertSame('timeSeries', $helper->lookupFeatureType(NULL, 'https://opendap.example/data', 'opendap'));
  }

  /**
   * Tests lookupFeatureType returns null when no lookup input is provided.
   */
  #[Test]
  public function testLookupFeatureTypeReturnsNullWithoutIdentifierOrUrl(): void {
    $helper = $this->createHelper();

    $this->assertNull($helper->lookupFeatureType(NULL, NULL, 'opendap'));
  }

  /**
   * Creates a helper instance without running the production constructor.
   */
  private function createHelper(): MetsisHelper {
    $reflection = new \ReflectionClass(MetsisHelper::class);
    /** @var \Drupal\metsis_drupal\Utility\MetsisHelper $helper */
    $helper = $reflection->newInstanceWithoutConstructor();
    return $helper;
  }

  /**
   * Sets a non-public property on the helper under test.
   */
  private function setProperty(MetsisHelper $helper, string $property_name, mixed $value): void {
    $reflection = new \ReflectionProperty(MetsisHelper::class, $property_name);
    $reflection->setValue($helper, $value);
  }

}
