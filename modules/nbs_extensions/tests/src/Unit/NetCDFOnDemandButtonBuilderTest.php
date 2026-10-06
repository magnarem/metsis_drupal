<?php

declare(strict_types=1);

namespace Drupal\Tests\nbs_extensions\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\nbs_extensions\Service\NetCDFOnDemandButtonBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the NetCDF on-demand button builder.
 */
#[CoversClass(NetCDFOnDemandButtonBuilder::class)]
#[Group('nbs_extensions')]
final class NetCDFOnDemandButtonBuilderTest extends TestCase {

  /**
   * Builds an instance configured with supported product types.
   *
   * @param string[] $products
   *   Supported product types.
   *
   * @return \Drupal\nbs_extensions\Service\NetCDFOnDemandButtonBuilder
   *   The button builder.
   */
  private function createBuilder(array $products): NetCDFOnDemandButtonBuilder {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->with('netcdf_ondemand_products')
      ->willReturn($products);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('nbs_extensions.settings')
      ->willReturn($config);

    return new NetCDFOnDemandButtonBuilder($config_factory);
  }

  /**
   * Builds a link for a configured product using the Solr document ID.
   */
  #[Test]
  public function buildsButtonForConfiguredProduct(): void {
    $builder = $this->createBuilder(['GRD', 'MSI-L1C']);
    $button = $builder->build([
      'id' => 'doi-10-1594-PANGAEA-995890',
      'platform_instrument_product_type' => ['OTHER', 'MSI-L1C'],
    ]);

    self::assertIsArray($button);
    self::assertSame('link', $button['#type']);
    self::assertInstanceOf(TranslatableMarkup::class, $button['#title']);
    self::assertSame('Request "CF-NetCDF file"', $button['#title']->getUntranslatedString());
    self::assertInstanceOf(Url::class, $button['#url']);
    self::assertSame('nbs_extensions.netcdf_on_demand', $button['#url']->getRouteName());
    self::assertSame(
      ['datasetId' => 'doi-10-1594-PANGAEA-995890'],
      $button['#url']->getRouteParameters(),
    );
    self::assertContains('nbs-netcdf-on-demand', $button['#attributes']['class']);
  }

  /**
   * Does not build a button for an unsupported product or invalid Solr ID.
   */
  #[Test]
  public function omitsUnsupportedProductsAndInvalidIds(): void {
    $builder = $this->createBuilder(['GRD']);

    self::assertNull($builder->build([
      'id' => 'some-solr-id',
      'platform_instrument_product_type' => 'MSI-L1C',
    ]));
    self::assertNull($builder->build([
      'id' => 'invalid/id',
      'platform_instrument_product_type' => 'GRD',
    ]));
  }

  /**
   * Suppresses the action for an existing HTTP NetCDF file, not OPeNDAP.
   */
  #[Test]
  public function omitsButtonWhenHttpNetCdfFileAlreadyExists(): void {
    $builder = $this->createBuilder(['GRD']);

    self::assertNull($builder->build([
      'id' => 'dataset-1',
      'platform_instrument_product_type' => 'GRD',
      'data_access_json' => [
        [
          'type' => 'HTTP',
          'resource' => 'https://data.example.test/dataset.nc?download=1',
        ],
      ],
    ]));

    self::assertNotNull($builder->build([
      'id' => 'dataset-1',
      'platform_instrument_product_type' => 'GRD',
      'data_access_json' => [
        [
          'type' => 'OPeNDAP',
          'resource' => 'https://data.example.test/dataset',
        ],
      ],
    ]));
  }

}
