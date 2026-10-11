<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit\Hook;

use Drupal\Core\Form\FormState;
use Drupal\metsis_drupal\Hook\MetsisSearchFormHooks;
use Drupal\metsis_drupal\Hook\MetsisThemeHooks;
use Drupal\metsis_drupal\Hook\MetsisViewsHooks;
use Drupal\metsis_drupal\Service\MetVocabServiceInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\views\Entity\View;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\ViewExecutable;

/**
 * Tests generic View recognition and template-independent infrastructure.
 */
final class MetsisViewHooksTest extends UnitTestCase {

  /**
   * Creates a METSIS View without a query tag.
   */
  private function view(): ViewExecutable {
    $view = $this->createMock(ViewExecutable::class);
    $view->storage = new View(['id' => 'custom', 'base_table' => 'search_api_index_metsis'], 'view');
    $view->method('id')->willReturn('custom');
    $view->current_display = 'page_1';
    $view->method('getDisplay')->willReturn($this->createMock(DisplayPluginBase::class));
    return $view;
  }

  /**
   * Tests independent suggestions and shared root targets.
   */
  public function testViewSuggestions(): void {
    $view = $this->view();
    $hooks = new MetsisThemeHooks($this->createMock(MetVocabServiceInterface::class));
    $suggestions = [];
    $hooks->themeSuggestionsViewsViewAlter($suggestions, ['view' => $view]);
    self::assertSame(['views_view__metsis_custom', 'views_view__metsis_custom__page_1'], $suggestions);
    $views_hooks = new MetsisViewsHooks();
    $views_hooks->preRender($view);
    self::assertContains('metsis_drupal/metsis_view_targets', $view->element['#attached']['library']);
    $bottom = [];
    $views_hooks->pageBottom($bottom);
    $views_hooks->pageBottom($bottom);
    self::assertCount(2, $bottom);
    self::assertSame('metsis-metadata-modal-root', $bottom['metsis_dialog']['#attributes']['id']);
  }

  /**
   * Tests a custom no-fulltext exposed form retains its normal controls.
   */
  public function testFormWithoutText(): void {
    $view = $this->view();
    $view->filter = [];
    $hooks = new MetsisSearchFormHooks($this->createMock(MetVocabServiceInterface::class));
    $hooks->setStringTranslation($this->getStringTranslationStub());
    $form = ['#id' => 'views-exposed-form-custom-page-1', 'actions' => ['submit' => ['#type' => 'submit']]];
    $state = (new FormState())->set('view', $view);
    $hooks->metsisExposedFormAlter($form, $state, 'views_exposed_form');
    self::assertSame('custom', $form['#metsis_search_view_id']);
    self::assertArrayNotHasKey('search-box-container', $form);
    self::assertSame('submit', $form['actions']['submit']['#type']);
    $theme = new MetsisThemeHooks($this->createMock(MetVocabServiceInterface::class));
    $suggestions = [];
    $theme->themeHookSuggestion($suggestions, ['form' => $form]);
    self::assertSame(['views_exposed_form__metsis', 'views_exposed_form__metsis_custom__page_1'], $suggestions);
  }

}
