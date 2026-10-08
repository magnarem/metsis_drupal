<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit\Plugin\Block;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\metsis_drupal\Hook\MetsisThemeHooks;
use Drupal\metsis_drupal\Plugin\Block\MetsisSearchExposedFormBlock;
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
    $account = $this->createMock(AccountInterface::class);
    $view = $this->createMock(ViewExecutable::class);
    $view->storage = $this->createMock(ViewEntity::class);
    $view->method('setDisplay')->with('results')->willReturn(TRUE);
    $view->method('access')->with('results', $account)->willReturn(TRUE);
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
    $facet_filter = $this->createFilter('Collection facet', [
      'value' => 'collection',
      'operator' => '',
      'label' => 'Collection facet',
    ], 'facets_filter');

    $display = $this->getMockBuilder(Page::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getHandlers', 'getPlugin'])
      ->getMock();
    $display->method('getHandlers')
      ->with('filter')
      ->willReturn([
        'hidden_filter_plugin' => $hidden_filter,
        'visible_filter_plugin' => $visible_filter,
        'search_api_fulltext' => $this->createFilter('Search', [
          'value' => 'text',
          'operator' => 'search_api_fulltext_op',
          'label' => 'Search',
        ]),
        'temporal_extent_period_dr' => $this->createFilter('Temporal filter', [
          'value' => 'temporal_extent_period_dr',
          'operator' => 'temporal_extent_period_dr_op',
          'label' => 'Temporal filter',
        ]),
        'bbox' => $this->createFilter('Geographic filter', [
          'value' => 'bbox',
          'operator' => 'bbox_op',
          'label' => 'Geographic filter',
        ]),
        'related_dataset' => $this->createFilter('Related dataset', [
          'value' => 'related_dataset',
          'operator' => 'related_dataset_op',
          'label' => 'Related dataset',
        ]),
        'facets_collection' => $facet_filter,
      ]);
    $exposed_form = $this->createMock(ExposedFormPluginInterface::class);
    $exposed_form->expects(self::never())
      ->method('renderExposedForm');
    $display->method('getPlugin')
      ->with('exposed_form')
      ->willReturn($exposed_form);
    $view->display_handler = $display;
    $view->filter = ['facets_collection' => $facet_filter];
    $view->exposed_widgets = [
      'hidden_filter_wrapper' => [
        '#type' => 'container',
        'hidden_filter' => ['#type' => 'textfield'],
      ],
      'hidden_filter_op' => ['#type' => 'select'],
      'visible_filter' => ['#type' => 'textfield'],
      'visible_filter_op' => ['#type' => 'select'],
      'search-box-container' => [
        '#type' => 'container',
        'actions' => [
          'submit' => ['#type' => 'submit', '#value' => 'Update filters'],
        ],
      ],
      'temporal_extent_period_dr_wrapper' => ['#type' => 'container'],
      'bbox_wrapper' => ['#type' => 'fieldset'],
      'related_dataset' => ['#type' => 'textfield'],
      'collection' => ['#type' => 'select', '#options' => ['all' => 'All', 'one' => 'One']],
      'actions' => [
        '#type' => 'container',
        'submit_filters' => ['#type' => 'component'],
      ],
      'sort_by' => ['#type' => 'select'],
      'sort_order' => ['#type' => 'select'],
      'items_per_page' => ['#type' => 'select'],
      '#info' => [
        'filter-hidden_filter_plugin' => [],
        'filter-visible_filter_plugin' => [],
        'filter-facets_collection' => [],
      ],
    ];
    $view->expects(self::once())
      ->method('execute')
      ->with('results')
      ->willReturn(TRUE);

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
        'filter_weights' => [
          'search_api_fulltext' => -8,
          'temporal_extent_period_dr' => 4,
          'bbox' => 6,
          'related_dataset' => 3,
          'facets_collection' => 5,
        ],
        'filter_columns' => [
          'temporal_extent_period_dr' => '1',
          'bbox' => '3',
          'related_dataset' => 'invalid',
        ],
        'column_count' => 2,
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
        'search_api_fulltext' => 'Search',
        'temporal_extent_period_dr' => 'Temporal filter',
        'bbox' => 'Geographic filter',
        'related_dataset' => 'Related dataset',
        'facets_collection' => 'Collection facet',
      ],
      $configuration_form['disabled_filters']['#options'],
    );
    self::assertSame(
      ['hidden_filter_plugin'],
      $configuration_form['disabled_filters']['#default_value'],
    );
    self::assertSame(-8, $configuration_form['filter_weights']['search_api_fulltext']['#default_value']);
    self::assertSame(4, $configuration_form['filter_weights']['temporal_extent_period_dr']['#default_value']);
    self::assertSame(6, $configuration_form['filter_weights']['bbox']['#default_value']);
    self::assertSame('1', $configuration_form['filter_columns']['temporal_extent_period_dr']['#default_value']);
    self::assertSame('3', $configuration_form['filter_columns']['bbox']['#default_value']);
    self::assertSame('auto', $configuration_form['filter_columns']['related_dataset']['#default_value']);
    self::assertSame(2, $configuration_form['column_count']['#default_value']);

    $previous_container = \Drupal::hasContainer() ? \Drupal::getContainer() : NULL;
    $container = new ContainerBuilder();
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);
    try {
      self::assertTrue($block->access($account));
    }
    finally {
      if ($previous_container === NULL) {
        \Drupal::unsetContainer();
      }
      else {
        \Drupal::setContainer($previous_container);
      }
    }

    $form = $block->build();
    self::assertArrayNotHasKey('hidden_filter', $form['metsis-search-filter-grid']['column_1']);
    self::assertArrayNotHasKey('hidden_filter_wrapper', $form['metsis-search-filter-grid']['column_1']);
    self::assertArrayNotHasKey('hidden_filter_op', $form['metsis-search-filter-grid']['column_1']);
    self::assertArrayHasKey('visible_filter', $form['metsis-search-filter-grid']['column_1']);
    self::assertArrayHasKey('visible_filter_op', $form['metsis-search-filter-grid']['column_1']);
    self::assertArrayHasKey('search-box-container', $form['metsis-search-filter-grid']['column_1']);
    self::assertArrayHasKey('related_dataset', $form['metsis-search-filter-grid']['column_2']);
    self::assertArrayHasKey('collection', $form['metsis-search-filter-grid']['column_2']);
    self::assertArrayNotHasKey('column_3', $form['metsis-search-filter-grid']);
    self::assertArrayNotHasKey('sort_by', $form);
    self::assertArrayNotHasKey('sort_order', $form);
    self::assertArrayNotHasKey('items_per_page', $form);
    self::assertArrayNotHasKey('actions', $form);
    self::assertSame(
      4,
      $form['metsis-search-filter-grid']['column_1']['temporal_extent_period_dr_wrapper']['#weight'],
    );
    self::assertSame(6, $form['metsis-search-filter-grid']['column_2']['bbox_wrapper']['#weight']);
    self::assertSame(-8, $form['metsis-search-filter-grid']['column_1']['search-box-container']['#weight']);
    $search_button_value = $form['metsis-search-filter-grid']['column_1']['search-box-container']['actions']['submit']['#value'];
    self::assertInstanceOf(TranslatableMarkup::class, $search_button_value);
    self::assertSame('Search', $search_button_value->getUntranslatedString());
    self::assertSame(3, $form['metsis-search-filter-grid']['column_2']['related_dataset']['#weight']);
    self::assertSame(5, $form['metsis-search-filter-grid']['column_2']['collection']['#weight']);
    self::assertSame('/metsis/search', $form['#action']);
    self::assertTrue($form['#metsis_search_exposed_form_block']);
    self::assertContains('metsis-search-exposed-form-block', $form['#attributes']['class']);
    self::assertContains(
      'metsis_drupal/metsis_search_exposed_form_block',
      $form['#attached']['library'],
    );
    self::assertSame('2', $form['metsis-search-filter-grid']['#attributes']['data-metsis-column-count']);
    self::assertSame(
      '1',
      $form['metsis-search-filter-grid']['column_1']['search-box-container']['#attributes']['data-metsis-filter-column'],
    );
    self::assertSame(
      '3',
      $form['metsis-search-filter-grid']['column_2']['bbox_wrapper']['#attributes']['data-metsis-filter-column'],
    );
    self::assertSame(
      'auto',
      $form['metsis-search-filter-grid']['column_2']['related_dataset']['#attributes']['data-metsis-filter-column'],
    );

    $submit_state = new FormState();
    $submit_state->setValue('disabled_filters', ['visible_filter_plugin' => 'visible_filter_plugin']);
    $submit_state->setValue('filter_weights', ['bbox' => 9]);
    $submit_state->setValue('filter_columns', ['bbox' => '3', 'related_dataset' => 'invalid']);
    $submit_state->setValue('column_count', 1);
    $block->blockSubmit([], $submit_state);
    $saved_configuration_form = $block->buildConfigurationForm([], new FormState());
    self::assertSame(
      ['visible_filter_plugin'],
      $saved_configuration_form['disabled_filters']['#default_value'],
    );
    self::assertSame(9, $saved_configuration_form['filter_weights']['bbox']['#default_value']);
    self::assertSame('3', $saved_configuration_form['filter_columns']['bbox']['#default_value']);
    self::assertSame('auto', $saved_configuration_form['filter_columns']['related_dataset']['#default_value']);
    self::assertSame(1, $saved_configuration_form['column_count']['#default_value']);

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

    $container_suggestions = [];
    $theme_hooks->themeSuggestionsContainerAlter($container_suggestions, [
      'element' => ['#metsis_search_box' => TRUE],
    ]);
    self::assertSame(['container__metsis_search_box'], $container_suggestions);
  }

  /**
   * Creates an exposed Views filter handler mock.
   *
   * @param string $label
   *   The exposed filter label.
   * @param array $exposed_info
   *   Exposed form element metadata.
   * @param string $plugin_id
   *   The filter handler plugin ID.
   *
   * @return \Drupal\views\Plugin\views\filter\StringFilter&\PHPUnit\Framework\MockObject\MockObject
   *   The filter handler mock.
   */
  private function createFilter(string $label, array $exposed_info, string $plugin_id = 'string'): StringFilter&MockObject {
    $filter = $this->getMockBuilder(StringFilter::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['canExpose', 'isExposed', 'adminLabel', 'exposedInfo', 'getPluginId'])
      ->getMock();
    $filter->options = ['expose' => ['label' => $label]];
    $filter->method('canExpose')->willReturn(TRUE);
    $filter->method('isExposed')->willReturn(TRUE);
    $filter->method('adminLabel')->willReturn($label);
    $filter->method('exposedInfo')->willReturn($exposed_info);
    $filter->method('getPluginId')->willReturn($plugin_id);

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
