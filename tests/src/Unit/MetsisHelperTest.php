<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Tests\Utility;

use Drupal\metsis_drupal\Utility\MetsisHelper;
use PHPUnit\Framework\TestCase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\leaflet\LeafletService;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Drupal\metsis_drupal\Service\FeatureTypeLookupService;

/**
 * Unit tests for MetsisHelper.
 */
#[Group('metsis_drupal')]
class MetsisHelperTest extends TestCase {

  /**
   * Mocks and returns a MetsisHelper instance.
   */
  private function getHelper(): MetsisHelper {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $leaflet = $this->createMock(LeafletService::class);
    $renderer = $this->createMock(RendererInterface::class);
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $dummyConfig = $this->createMock(ImmutableConfig::class);
    $moduleHandler->method('getModule')->willReturn((object) ['getPath' => fn() => 'metsis_drupal']);
    $configFactory->method('get')->willReturn($dummyConfig);
    $entityStorage = $this->createMock(EntityStorageInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($entityStorage);
    $entityStorage->method('load')->willReturn(NULL);
    $featureTypeLookup = $this->createMock(FeatureTypeLookupService::class);
    $featureTypeLookup->method('lookup')->willReturn('timeSeries');
    return new MetsisHelper($entityTypeManager, $leaflet, $renderer, $moduleHandler, $configFactory, $featureTypeLookup);
  }

  /**
   * Tests the handleShortLong method.
   *
   * @see \Drupal\metsis_drupal\Utility\MetsisHelper::handleShortLong
   */
  #[Test]
  #[DataProvider('shortLongProvider')]
  public function testHandleShortLong($short, $long, $expected) {
    $helper = $this->getHelper();
    $reflection = new \ReflectionClass($helper);
    $method = $reflection->getMethod('handleShortLong');
    $method->setAccessible(TRUE);
    $result = $method->invoke($helper, $short, $long);
    $this->assertSame($expected, $result);
  }

  /**
   * Array of inputs and expected outputs for handleShortLong tests.
   */
  public static function shortLongProvider() {
    return [
          ['S', 'Long', 'Long(S)'],
          [NULL, 'Long', 'Long'],
          ['S', NULL, 'S'],
          [NULL, NULL, ''],
          ['', 'Long', 'Long'],
          ['S', '', 'S'],
          ['', '', ''],
    ];
  }

}
