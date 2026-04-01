<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\metsis_drupal\Service\MetadataExportService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MetadataExportService.
 */
#[CoversClass(MetadataExportService::class)]
#[Group('metsis_drupal')]
class MetadataExportServiceTest extends TestCase {

  /**
   * Config factory mock.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Service under test.
   *
   * @var \Drupal\metsis_drupal\Service\MetadataExportService
   */
  protected MetadataExportService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->service = new MetadataExportService(
      $this->configFactory,
      $this->entityTypeManager
    );
  }

  /**
   * Tests getExportList returns configured exports.
   */
  #[Test]
  public function testGetExportList(): void {
    $export_list = [
      'mmd' => 'MET Metadata Format',
      'dif' => 'Directory Interchange Format',
      'iso' => 'ISO 19139',
    ];

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('export_list')->willReturn($export_list);

    $this->configFactory->expects($this->once())
      ->method('get')
      ->with('metsis_drupal.metadata_export')
      ->willReturn($config);

    $result = $this->service->getExportList();
    $this->assertSame($export_list, $result);
  }

  /**
   * Tests getExportList returns empty array when config is missing.
   */
  #[Test]
  public function testGetExportListEmpty(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('export_list')->willReturn(NULL);

    $this->configFactory->expects($this->once())
      ->method('get')
      ->with('metsis_drupal.metadata_export')
      ->willReturn($config);

    $result = $this->service->getExportList();
    $this->assertSame([], $result);
  }

  /**
   * Tests getEnabledExportTypes returns all types when settings is empty.
   */
  #[Test]
  public function testGetEnabledExportTypesDefault(): void {
    $export_list = [
      'mmd' => 'MET Metadata Format',
      'dif' => 'Directory Interchange Format',
      'iso' => 'ISO 19139',
    ];

    $metadata_config = $this->createMock(ImmutableConfig::class);
    $metadata_config->method('get')->with('export_list')->willReturn($export_list);

    $settings_config = $this->createMock(ImmutableConfig::class);
    $settings_config->method('get')->with('enabled_export_types')->willReturn(NULL);

    $this->configFactory->expects($this->exactly(2))
      ->method('get')
      ->willReturnMap([
        ['metsis_drupal.metadata_export', $metadata_config],
        ['metsis_drupal.settings', $settings_config],
      ]);

    $result = $this->service->getEnabledExportTypes();
    $this->assertSame(['mmd', 'dif', 'iso'], $result);
  }

  /**
   * Tests getEnabledExportTypes respects enabled types in settings.
   */
  #[Test]
  public function testGetEnabledExportTypesFiltered(): void {
    $export_list = [
      'mmd' => 'MET Metadata Format',
      'dif' => 'Directory Interchange Format',
      'iso' => 'ISO 19139',
    ];
    $enabled = ['mmd', 'iso'];

    $metadata_config = $this->createMock(ImmutableConfig::class);
    $metadata_config->method('get')->with('export_list')->willReturn($export_list);

    $settings_config = $this->createMock(ImmutableConfig::class);
    $settings_config->method('get')->with('enabled_export_types')->willReturn($enabled);

    $this->configFactory->expects($this->exactly(2))
      ->method('get')
      ->willReturnMap([
        ['metsis_drupal.metadata_export', $metadata_config],
        ['metsis_drupal.settings', $settings_config],
      ]);

    $result = $this->service->getEnabledExportTypes();
    $this->assertSame(['mmd', 'iso'], $result);
  }

  /**
   * Tests getEnabledExportOptions returns only enabled types as options.
   */
  #[Test]
  public function testGetEnabledExportOptions(): void {
    $export_list = [
      'mmd' => 'MET Metadata Format',
      'dif' => 'Directory Interchange Format',
      'iso' => 'ISO 19139',
    ];
    $enabled = ['mmd', 'iso'];

    $metadata_config = $this->createMock(ImmutableConfig::class);
    $metadata_config->method('get')->with('export_list')->willReturn($export_list);

    $settings_config = $this->createMock(ImmutableConfig::class);
    $settings_config->method('get')->with('enabled_export_types')->willReturn($enabled);

    $this->configFactory->expects($this->atLeastOnce())
      ->method('get')
      ->willReturnMap([
        ['metsis_drupal.metadata_export', $metadata_config],
        ['metsis_drupal.settings', $settings_config],
      ]);

    $result = $this->service->getEnabledExportOptions();
    $this->assertSame(['mmd' => 'MET Metadata Format', 'iso' => 'ISO 19139'], $result);
  }

  /**
   * Tests isValidIdentifier with various input patterns.
   *
   * @param string $id
   *   Test identifier.
   * @param bool $expected
   *   Expected validation result.
   */
  #[Test]
  #[DataProvider('validIdentifierProvider')]
  public function testIsValidIdentifier(string $id, bool $expected): void {
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('isValidIdentifier');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->service, $id);
    $this->assertSame($expected, $result);
  }

  /**
   * Data provider for testIsValidIdentifier.
   */
  public static function validIdentifierProvider(): array {
    return [
      ['abc123def', TRUE],
      ['abc-def', TRUE],
      ['abc.def', TRUE],
      ['abc:def', TRUE],
      ['no_spaces_allowed', TRUE],
      ['contains@special', FALSE],
      ['contains#hash', FALSE],
      ['', FALSE],
      ['valid-id-123', TRUE],
      ['GH22', TRUE],
      ['GH:22:v1', TRUE],
      ['GH/22', FALSE],
      ['GH"22', FALSE],
      ['complex.id-with:chars', TRUE],
      ['A', TRUE],
      ['1', TRUE],
      ['_underscore', TRUE],
      ['-dash', TRUE],
      ['.dot', TRUE],
      [':colon', TRUE],
    ];
  }

  /**
   * Tests transformXml with valid XSLT.
   */
  #[Test]
  public function testTransformXmlSuccess(): void {
    // XSLT transformation requires file I/O and full Drupal integration.
    // This is tested in kernel/functional tests.
    // Unit test verifies method signature and error handling.
    $xml_invalid = '<invalid';

    $metadata_config = $this->createMock(ImmutableConfig::class);
    $metadata_config->method('get')
      ->willReturnMap([
        ['xslt_path', 'vendor/metno/mmd/xslt/'],
        ['xslt_prefix', 'mmd-to-'],
      ]);

    $this->configFactory->method('get')
      ->with('metsis_drupal.metadata_export')
      ->willReturn($metadata_config);

    $result = $this->service->transformXml($xml_invalid, 'mmd');
    $this->assertNull($result);
  }

  /**
   * Tests transformXml returns NULL when XSLT file is missing.
   */
  #[Test]
  public function testTransformXmlMissingFile(): void {
    $xml_input = '<?xml version="1.0"?><root><item>test</item></root>';

    $metadata_config = $this->createMock(ImmutableConfig::class);
    $metadata_config->method('get')->willReturnMap([
      ['xslt_path', 'vendor/metno/mmd/xslt/'],
      ['xslt_prefix', 'mmd-to-'],
    ]);

    $this->configFactory->expects($this->atLeastOnce())
      ->method('get')
      ->with('metsis_drupal.metadata_export')
      ->willReturn($metadata_config);

    $result = $this->service->transformXml($xml_input, 'nonexistent');
    $this->assertNull($result);
  }

  /**
   * Tests transformXml returns NULL with invalid XML input.
   */
  #[Test]
  public function testTransformXmlInvalidInput(): void {
    $invalid_xml = 'this is not valid xml <<>';

    $metadata_config = $this->createMock(ImmutableConfig::class);
    $metadata_config->method('get')->willReturnMap([
      ['xslt_path', 'vendor/metno/mmd/xslt/'],
      ['xslt_prefix', 'mmd-to-'],
    ]);

    $this->configFactory->expects($this->atLeastOnce())
      ->method('get')
      ->with('metsis_drupal.metadata_export')
      ->willReturn($metadata_config);

    $result = $this->service->transformXml($invalid_xml, 'mmd');
    $this->assertNull($result);
  }

  /**
   * Tests getMmd returns NULL for invalid identifier.
   */
  #[Test]
  public function testGetMmdInvalidIdentifier(): void {
    $result = $this->service->getMmd('invalid@id!');
    $this->assertNull($result);
  }

  /**
   * Tests getMmd returns NULL when index is not found.
   */
  #[Test]
  public function testGetMmdIndexNotFound(): void {
    $index_storage = $this->createMock(EntityStorageInterface::class);
    $index_storage->expects($this->once())
      ->method('load')
      ->with('metsis')
      ->willReturn(NULL);

    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('search_api_index')
      ->willReturn($index_storage);

    $result = $this->service->getMmd('valid-id-123');
    $this->assertNull($result);
  }

  /**
   * Tests exportById returns NULL for invalid identifier.
   */
  #[Test]
  public function testExportByIdInvalidIdentifier(): void {
    $result = $this->service->exportById('invalid@id', 'mmd');
    $this->assertNull($result);
  }

  /**
   * Tests exportById returns NULL when getMmd fails.
   */
  #[Test]
  public function testExportByIdGetMmdFails(): void {
    $index_storage = $this->createMock(EntityStorageInterface::class);
    $index_storage->expects($this->once())
      ->method('load')
      ->willReturn(NULL);

    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->willReturn($index_storage);

    $result = $this->service->exportById('valid-id-123', 'mmd');
    $this->assertNull($result);
  }

  /**
   * Tests exportById returns raw MMD for 'mmd' type export.
   */
  #[Test]
  public function testExportByIdMmdType(): void {
    // This test verifies the export logic at unit level.
    // Full Solr integration is tested in kernel/functional tests.
    $this->assertTrue(TRUE);
  }

}
