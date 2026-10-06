<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit\Plugin\Block;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\metsis_drupal\Plugin\Block\MetsisSearchExposedFormBlock;
use Drupal\metsis_drupal\Hook\MetsisThemeHooks;
use Drupal\metsis_drupal\Service\MetVocabServiceInterface;
use Drupal\views\Entity\View as ViewEntity;
use Drupal\views\Plugin\views\display\Page;
use Drupal\views\Plugin\views\exposed_form\ExposedFormPluginInterface;
use Drupal\views\Plugin\views\filter\StringFilter;
use Drupal\views\ViewExecutable;
use Drupal\views\ViewExecutableFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the METSIS search exposed form block.
 */
#[CoversClass(MetsisSearchExposedFormBlock::class)]
#[CoversClass(MetsisThemeHooks::class)]
#[Group('metsis_drupal')]
final class MetsisSearchExposedFormBlockTest extends TestCase {

  /**
   * Tests dynamic filter options and canonical exposed-form submission.
   */
  #[Test]
  public function testConfiguredFiltersAndFormAction(): void {
    $view = $this->createMock(ViewExecutable::class);
    $view->storage = $this->createMock(ViewEntity::class);
    $view->method('setDisplay')->with('results')->willReturn(TRUE);
    $view->method('initHandlers');
    $view->method('getUrlInfo')
      ->with('results')
      ->willReturn($this->getViewUrl('/metsis/search'));

    $hidden_filter = $this->createFilter('Hidden filter', [
      'value' => 'hidden_filter',
      'operator' => 'hidden_filter_op',
      'label' => 'Hidden filter',
    ]);
    $visible_filter = $this->createFilter('Visible filter', [
      'value' => 'visible_filter',
      'operator' => 'visible_filter_op',
      'label' => 'Visible filter',
    ]);

    $display = $this->getMockBuilder(Page::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getHandlers', 'getPlugin'])
      ->getMock();
    $display->method('getHandlers')
      ->with('filter')
      ->willReturn([
        'hidden_filter_plugin' => $hidden_filter,
        'visible_filter_plugin' => $visible_filter,
      ]);
    $exposed_form = $this->createMock(ExposedFormPluginInterface::class);
    $exposed_form->method('renderExposedForm')
      ->with(TRUE)
      ->willReturn([
        'hidden_filter' => ['#type' => 'textfield'],
        'hidden_filter_op' => ['#type' => 'select'],
        'visible_filter' => ['#type' => 'textfield'],
        'visible_filter_op' => ['#type' => 'select'],
        '#info' => [
          'filter-hidden_filter_plugin' => [],
          'filter-visible_filter_plugin' => [],
        ],
      ]);
    $display->method('getPlugin')
      ->with('exposed_form')
      ->willReturn($exposed_form);
    $view->display_handler = $display;

    $view_factory = $this->createMock(ViewExecutableFactory::class);
    $view_factory->method('get')->willReturn($view);
    $view_storage = $this->createMock(EntityStorageInterface::class);
    $view_storage->method('load')->with('metsis_search')
      ->willReturn($this->createMock(ViewEntity::class));
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('view')->willReturn($view_storage);

    $block = new MetsisSearchExposedFormBlock(
      [
        'disabled_filters' => ['hidden_filter_plugin'],
      ],
      'metsis_search_exposed_form',
      ['provider' => 'metsis_drupal', 'admin_label' => 'METSIS Search exposed form'],
      $view_factory,
      $entity_type_manager,
    );
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')
      ->willReturnCallback(static fn (string $string, array $args = []): string => strtr($string, $args));
    $block->setStringTranslation($translation);

    $configuration_form = $block->buildConfigurationForm([], new FormState());
    self::assertSame(
      [
        'hidden_filter_plugin' => 'Hidden filter',
        'visible_filter_plugin' => 'Visible filter',
      ],
      $configuration_form['disabled_filters']['#options'],
    );
    self::assertSame(
      ['hidden_filter_plugin'],
      $configuration_form['disabled_filters']['#default_value'],
    );

    $form = $block->build();
    self::assertArrayNotHasKey('hidden_filter', $form);
    self::assertArrayNotHasKey('hidden_filter_op', $form);
    self::assertArrayHasKey('visible_filter', $form);
    self::assertArrayHasKey('visible_filter_op', $form);
    self::assertSame('/metsis/search', $form['#action']);
    self::assertTrue($form['#metsis_search_exposed_form_block']);

    $theme_hooks = new MetsisThemeHooks($this->createMock(MetVocabServiceInterface::class));
    $block_suggestions = [];
    $theme_hooks->themeSuggestionsBlockAlter($block_suggestions, [
      'elements' => ['#plugin_id' => 'metsis_search_exposed_form'],
    ]);
    self::assertSame(
      ['block__metsis_search_exposed_form__metsis_search__results'],
      $block_suggestions,
    );

    $form_suggestions = [];
    $theme_hooks->themeHookSuggestion($form_suggestions, [
      'form' => [
        '#metsis_search_exposed_form_block' => TRUE,
        '#metsis_search_view_id' => 'metsis_search',
        '#metsis_search_display_id' => 'results',
      ],
    ]);
    self::assertSame([
      'views_exposed_form__metsis_search_block',
      'views_exposed_form__metsis_search_block__metsis_search__results',
    ], $form_suggestions);
  }

  /**
   * Creates an exposed Views filter handler mock.
   *
   * @param string $label
   *   The exposed filter label.
   * @param array $exposed_info
   *   Exposed form element metadata.
   *
   * @return \Drupal\views\Plugin\views\filter\StringFilter&\PHPUnit\Framework\MockObject\MockObject
   *   The filter handler mock.
   */
  private function createFilter(string $label, array $exposed_info): StringFilter&MockObject {
    $filter = $this->getMockBuilder(StringFilter::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['canExpose', 'isExposed', 'adminLabel', 'exposedInfo'])
      ->getMock();
    $filter->options = ['expose' => ['label' => $label]];
    $filter->method('canExpose')->willReturn(TRUE);
    $filter->method('isExposed')->willReturn(TRUE);
    $filter->method('adminLabel')->willReturn($label);
    $filter->method('exposedInfo')->willReturn($exposed_info);

    return $filter;
  }

  /**
   * Creates a View URL mock.
   */
  private function getViewUrl(string $path): Url&MockObject {
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn($path);

    return $url;
  }

}
