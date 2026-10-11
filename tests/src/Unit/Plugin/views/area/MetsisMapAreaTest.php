<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit\Plugin\views\area;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\FormState;
use Drupal\metsis_drupal\Plugin\views\area\MetsisMapArea;
use Drupal\Tests\UnitTestCase;
use Drupal\views\Entity\View;
use Drupal\views\ViewExecutable;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests isolated map settings and validated Views options.
 */
#[CoversClass(MetsisMapArea::class)]
final class MetsisMapAreaTest extends UnitTestCase {

  /**
   * Tests map configuration, cache tags and invalid input.
   */
  public function testSettings(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn('EPSG:4326');
    $config->method('getCacheTags')->willReturn(['config:metsis_drupal.settings']);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $map = new MetsisMapArea([], 'metsis_map', [], $factory);
    $map->setStringTranslation($this->getStringTranslationStub());
    $view = new View(['id' => 'custom', 'base_table' => 'search_api_index_metsis'], 'view');
    $map->view = $this->createMock(ViewExecutable::class);
    $map->view->storage = $view;
    $map->view->method('id')->willReturn('custom');
    $map->view->current_display = 'page_1';
    $map->view->dom_id = 'instance-a';
    $map->options = [
      'id' => 'map',
      'map_app_settings' => '{"features":{"showAllWms":true}}',
      'map_height' => 250,
      'show_all_wms' => FALSE,
      'empty' => FALSE,
    ];
    $build = $map->render();
    $instances = $build['#attached']['drupalSettings']['metsis_drupal']['map_app']['instances'];
    $settings = reset($instances);
    self::assertSame(250, $settings['mapOptions']['height']);
    self::assertFalse($settings['features']['showAllWms']);
    self::assertSame('instance-a', $settings['features']['searchViewKey']);
    self::assertContains('config:metsis_drupal.settings', $build['#cache']['tags']);
    self::assertSame([], $map->render(TRUE));
    $map->view->dom_id = 'instance-b';
    self::assertNotSame($build['#attributes']['id'], $map->render()['#attributes']['id']);

    $form = [
      'map_app_settings' => ['#parents' => ['options', 'map_app_settings']],
      'map_height' => ['#parents' => ['options', 'map_height']],
    ];
    $state = (new FormState())->setValues(['options' => ['map_app_settings' => '[]', 'map_height' => '1001']]);
    $map->validateOptionsForm($form, $state);
    self::assertCount(2, $state->getErrors());
    foreach (['150', '400', '1000'] as $height) {
      $state = (new FormState())->setValues(['options' => ['map_app_settings' => '{}', 'map_height' => $height]]);
      $map->validateOptionsForm($form, $state);
      self::assertSame([], $state->getErrors());
      self::assertSame((int) $height, $state->getValue(['options', 'map_height']));
      $options = $map->submitFormCalculateOptions($map->options, $state->getValue('options'));
      self::assertSame((int) $height, $options['map_height']);
    }
    foreach (['149', '1001', '400.5', '400px', '', NULL] as $height) {
      $state = (new FormState())->setValues(['options' => ['map_app_settings' => '{}', 'map_height' => $height]]);
      $map->validateOptionsForm($form, $state);
      self::assertArrayHasKey('options][map_height', $state->getErrors());
    }
    $state = (new FormState())->setValues([
      'options' => ['map_app_settings' => '{"features":"invalid"}', 'map_height' => '400'],
    ]);
    $map->validateOptionsForm($form, $state);
    self::assertArrayHasKey('options][map_app_settings', $state->getErrors());
    $map->options['map_app_settings'] = '{invalid';
    $this->expectException(\UnexpectedValueException::class);
    $map->render();
  }

}
