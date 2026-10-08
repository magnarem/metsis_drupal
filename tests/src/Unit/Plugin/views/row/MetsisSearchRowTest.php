<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit\Plugin\views\row;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\metsis_drupal\Plugin\views\row\MetsisSearchRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests METSIS search result row rendering.
 */
#[CoversClass(MetsisSearchRow::class)]
#[Group('metsis_drupal')]
final class MetsisSearchRowTest extends TestCase {

  /**
   * Tests data-access links use theme button styling and announce file details.
   */
  #[Test]
  public function testDataAccessLinksHaveThemeButtonStyleAndAccessibleLabels(): void {
    $row = (new \ReflectionClass(MetsisSearchRow::class))->newInstanceWithoutConstructor();
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')
      ->willReturnCallback(static fn ($markup): string => $markup->getUntranslatedString());
    $row->setStringTranslation($translation);

    /** @var array<string, mixed> $operations */
    $operations = [];
    $method = new \ReflectionMethod(MetsisSearchRow::class, 'buildDataAccessOptions');
    $method->invokeArgs($row, [
      &$operations,
      [
        'id' => 'dataset-1',
        'data_access_json' => [
          [
            'type' => 'HTTP',
            'resource' => 'https://example.test/files/data.nc?download=1',
            'description' => 'Direct access to the full data file.',
          ],
          [
            'type' => 'OPeNDAP',
            'resource' => 'https://example.test/thredds/dodsC/data',
          ],
          [
            'type' => 'HTTP',
            'resource' => 'https://nbstds.example/files/archive.zip?download=1',
          ],
        ],
      ],
      'row-1',
    ]);

    $netcdf_link = $operations['data_access_popover']['item_0']['#slots']['button'];
    self::assertSame('link', $netcdf_link['#type']);
    self::assertSame('Direct HTTP download (NetCDF file)', (string) $netcdf_link['#title']);
    self::assertContains('button', $netcdf_link['#attributes']['class']);
    self::assertArrayNotHasKey('aria-label', $netcdf_link['#attributes']);
    self::assertSame('Direct access to the full data file.', $netcdf_link['#attributes']['title']);
    self::assertTrue($netcdf_link['#attributes']['download']);

    $opendap_link = $operations['data_access_popover']['item_1']['#slots']['button'];
    self::assertSame(
      'OPeNDAP access (opens in a new tab)',
      (string) $opendap_link['#title'],
    );
    self::assertSame('_blank', $opendap_link['#attributes']['target']);
    self::assertContains('button', $opendap_link['#attributes']['class']);

    $zip_link = $operations['data_access_popover']['item_2']['#slots']['button'];
    self::assertSame(
      'Direct HTTP download (ZIP archive, SAFE)',
      (string) $zip_link['#title'],
    );
  }

}
