<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\metsis_drupal\Service\MetadataExportService;
use Drupal\metsis_drupal\Utility\MetsisSolrUtilities;
use PHPUnit\Framework\MockObject\MockObject;
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
   * @var \Drupal\Core\Config\ConfigFactoryInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected ConfigFactoryInterface&MockObject $configFactory;

  /**
   * Entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface&MockObject $entityTypeManager;

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

    // Match runtime convention where DRUPAL_ROOT points to PROJECT_ROOT/web.
    if (!defined('DRUPAL_ROOT')) {
      define('DRUPAL_ROOT', dirname(__DIR__, 4) . '/web');
    }

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
   * Tests shared identifier validation with various input patterns.
   *
   * @param string $id
   *   Test identifier.
   * @param bool $expected
   *   Expected validation result.
   */
  #[Test]
  #[DataProvider('validIdentifierProvider')]
  public function testIsValidIdentifier(string $id, bool $expected): void {
    $result = MetsisSolrUtilities::isValidIdentifier($id);
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
   * Tests transformXml with valid XSLT transformation.
   *
   * Uses actual XSLT files from vendor/metno/mmd and module's static config.
   */
  #[Test]
  public function testTransformXmlSuccess(): void {
    // Verify DRUPAL_ROOT is defined and correct.
    $this->assertTrue(defined('DRUPAL_ROOT'));
    // XSLT files are at project root, not web root, so check parent directory.
    $xslt_file = dirname(DRUPAL_ROOT) . '/vendor/metno/mmd/xslt/mmd-to-dif.xsl';
    $this->assertFileExists($xslt_file);

    // Minimal valid MMD XML expected by the XSLT.
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<mmd:mmd xmlns:mmd="http://www.met.no/schema/mmd">
  <mmd:metadata_identifier>test-id-001</mmd:metadata_identifier>
  <mmd:title>Test Dataset</mmd:title>
  <mmd:abstract>A test dataset for unit testing metadata export.</mmd:abstract>
  <mmd:geographic_extent>
    <mmd:rectangle>
      <mmd:north>90</mmd:north>
      <mmd:west>-180</mmd:west>
      <mmd:east>180</mmd:east>
      <mmd:south>-90</mmd:south>
    </mmd:rectangle>
  </mmd:geographic_extent>
</mmd:mmd>
XML;

    // Use module's static config and actual XSLT files.
    $metadata_config = $this->createMock(ImmutableConfig::class);
    $metadata_config->method('get')
      ->willReturnMap([
        ['xslt_path', 'vendor/metno/mmd/xslt/'],
        ['xslt_prefix', 'mmd-to-'],
      ]);

    $this->configFactory->method('get')
      ->with('metsis_drupal.metadata_export')
      ->willReturn($metadata_config);

    // Transform MMD to DIF format using real XSLT.
    $result = $this->service->transformXml($xml, 'dif');

    $this->assertIsString($result);
    $this->assertStringContainsString('dif:DIF', $result);
  }

  /**
   * Tests transformXml to different export types with real XSLT files.
   */
  #[Test]
  #[DataProvider('exportTypeProvider')]
  public function testTransformXmlMultipleTypes(string $export_type, string $expected_root): void {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<mmd:mmd xmlns:mmd="http://www.met.no/schema/mmd">
  <mmd:metadata_identifier>test-id-001</mmd:metadata_identifier>
  <mmd:title>Test Dataset</mmd:title>
  <mmd:abstract>Test abstract</mmd:abstract>
  <mmd:geographic_extent>
    <mmd:rectangle>
      <mmd:north>90</mmd:north>
      <mmd:west>-180</mmd:west>
      <mmd:east>180</mmd:east>
      <mmd:south>-90</mmd:south>
    </mmd:rectangle>
  </mmd:geographic_extent>
</mmd:mmd>
XML;

    $metadata_config = $this->createMock(ImmutableConfig::class);
    $metadata_config->method('get')
      ->willReturnMap([
        ['xslt_path', 'vendor/metno/mmd/xslt/'],
        ['xslt_prefix', 'mmd-to-'],
      ]);

    $this->configFactory->method('get')
      ->with('metsis_drupal.metadata_export')
      ->willReturn($metadata_config);

    $result = $this->service->transformXml($xml, $export_type);

    $this->assertIsString($result);
    $this->assertStringContainsString($expected_root, $result);
  }

  /**
   * Data provider for export types and expected root elements.
   */
  public static function exportTypeProvider(): array {
    return [
      ['dif', 'dif:DIF'],
      ['iso', 'gmd:MD_Metadata'],
      ['dif10', 'dif:DIF'],
    ];
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

    $result = $this->service->transformXml($invalid_xml, 'dif');
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

}
