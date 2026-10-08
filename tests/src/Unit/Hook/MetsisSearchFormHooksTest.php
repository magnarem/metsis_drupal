<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit\Hook;

use Drupal\Core\Form\FormState;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\metsis_drupal\Hook\MetsisSearchFormHooks;
use Drupal\metsis_drupal\Service\MetVocabServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests METSIS search form alterations.
 */
#[CoversClass(MetsisSearchFormHooks::class)]
#[Group('metsis_drupal')]
final class MetsisSearchFormHooksTest extends TestCase {

  /**
   * Tests the SDC wraps the normal Form API children.
   */
  #[Test]
  public function testSearchControlsUseSearchBoxComponent(): void {
    $hook = new MetsisSearchFormHooks($this->createMock(MetVocabServiceInterface::class));
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')
      ->willReturnCallback(static fn (string $string, array $args = []): string => strtr($string, $args));
    $hook->setStringTranslation($translation);

    $form = [
      '#id' => 'views-exposed-form-metsis-search-results',
      'text' => [
        '#type' => 'textfield',
        '#title' => 'Search',
      ],
      'actions' => [
        '#type' => 'actions',
        'submit' => [
          '#type' => 'submit',
          '#value' => 'Search',
        ],
      ],
    ];

    $hook->metsisExposedFormAlter($form, new FormState(), 'views_exposed_form');

    self::assertSame('container', $form['search-box-container']['#type']);
    self::assertTrue($form['search-box-container']['#metsis_search_box']);
    self::assertContains(
      'metsis-search-box-container',
      $form['search-box-container']['#attributes']['class'],
    );
    self::assertSame(
      ['metsis-search-text__input'],
      $form['search-box-container']['text']['#attributes']['class'],
    );
    self::assertSame(
      'search_results_submit',
      $form['search-box-container']['actions']['submit']['#attributes']['data-twig-suggestion'],
    );
    self::assertContains(
      'metsis-search-box__actions',
      $form['search-box-container']['actions']['#attributes']['class'],
    );
    self::assertArrayNotHasKey('text', $form);
    self::assertArrayNotHasKey('submit', $form['actions']);
  }

}
