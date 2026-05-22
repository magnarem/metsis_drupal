<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\metsis_drupal\MetsisConstants;
use Drupal\metsis_drupal\Service\MetVocabServiceInterface;

/**
 * Implements hooks related to the METSIS search form.
 */
class MetsisSearchFormHooks {

  use StringTranslationTrait;

  /**
   * Constructor.
   */
  public function __construct(
    private readonly MetVocabServiceInterface $metVocabService,
  ) {}

  /**
   * Override the exposed form for the METSIS search view.
   *
   * Implements hook_form_alter().
   *
   * {@inheritdoc}
   */
  #[Hook('form_views_exposed_form_alter')]
  public function metsisExposedFormAlter(&$form, FormStateInterface $form_state, $form_id) {
    if ($form_id === 'views_exposed_form' && $form['#id'] === 'views-exposed-form-' . MetsisConstants::METSIS_SEARCH_VIEW_NAME . '-results') {
      if (!isset($form['#after_build']) || !is_array($form['#after_build'])) {
        $form['#after_build'] = [];
      }
      $form['#after_build'][] = [self::class, 'markMetsisFieldsetsAfterBuild'];

      // Attach vocab popover library for facet filters.
      if (!isset($form['#attached']['library'])) {
        $form['#attached']['library'] = [];
      }
      $form['#attached']['library'][] = 'metsis_drupal/metsis_vocab_popover';
      $form = self::markMetsisFieldsetsAfterBuild($form, $form_state);
      // Convert the search input to a button.
      $form['actions']['submit']['#attributes']['data-twig-suggestion'] = 'search_results_submit';
      $form['actions']['submit']['#attributes']['class'][] = 'metsis-search-box__button';
      $form['actions']['submit']['#attributes']['aria-label'][] = $this->t("Search");

      // Set weights to reorder elements. submit button after text input.
      $form['actions']['#weight'] = -99;
      $form['text']['#weight'] = -98;

      $form['search-box-container'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'metsis-search-box-container',
          ],
        ],
      ];

      // Move the text search input and submit button into the container.
      if (isset($form['text'])) {
        $form['text']['#attributes']['class'][] = 'metsis-search-text__input';
        $form['text']['#title_display'] = 'invisible';
        $form['search-box-container']['text'] = $form['text'];
        unset($form['text']);
      }
      if (isset($form['actions']['submit'])) {
        $form['search-box-container']['actions'] = $form['actions'];
        unset($form['actions']);
      }
      $form['search-box-container']['#weight'] = -100;

      // Add a secondary submit button with text
      // and filter icon as the last element.
      $form['actions'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['form-actions'],
        ],
        '#weight' => 100,
      ];
      $form['actions']['submit_filters'] = [
        '#type' => 'component',
        '#component' => 'metsis_drupal:icon_button',
        '#props' => [
          'icon_size' => 18,
          'icon_pack' => 'metsis_drupal',
          'icon_id' => 'filter',
        ],
        '#slots' => [
          'button' => [
            '#type' => 'submit',
            '#value' => $this->t('Update filters'),
            '#attributes' => [
              'class' => ['metsis-update-filters-button'],
            ],
          ],
        ],
      ];

      unset($form['#disable_inline_form_errors']);
      // Inject vocabulary info popovers into mapped filter fieldsets.
      // Key: exposed form field name, value: [vocab_group_key, safe_id_suffix].
      $fieldset_vocab_map = [
        'activity_type' => ['Activity_Type', 'activity-type'],
        'collection' => ['Collection_Keywords', 'collection'],
      ];

      foreach ($fieldset_vocab_map as $field_name => [$group_key, $safe_id]) {
        if (!isset($form[$field_name]) || !is_array($form[$field_name])) {
          continue;
        }
        if (!$this->hasFacetChoices($form[$field_name])) {
          continue;
        }
        if ($this->elementHasPopover($form[$field_name]['#metsis_vocab_popover'] ?? NULL)) {
          continue;
        }

        $group = $this->metVocabService->getGroup($group_key);
        if (!is_array($group)) {
          continue;
        }

        $label      = trim((string) ($group['label'] ?? ''));
        $definition = trim((string) ($group['definition'] ?? ''));
        $uri        = trim((string) ($group['uri'] ?? ''));

        if ($label === '' && $definition === '' && $uri === '') {
          continue;
        }

        $original_title = (string) ($form[$field_name]['#title'] ?? $field_name);
        $popover_element = [
          '#theme' => 'metsis_facet_vocab_popover_button',
          '#popover_id' => 'facet-vocab-' . $safe_id,
          '#label' => $label,
          '#definition' => $definition,
          '#uri' => $uri,
          '#title' => $original_title,
        ];

        $form[$field_name]['#metsis_vocab_popover'] = $popover_element;
      }
    }
  }

  /**
   * After-build callback to mark fieldset-like elements in the exposed form.
   */
  public static function markMetsisFieldsetsAfterBuild(array $form, FormStateInterface $form_state): array {
    self::markMetsisSearchFieldsets($form);
    return $form;
  }

  /**
   * Mark fieldsets and wrappers in the exposed form for scoped suggestions.
   */
  private static function markMetsisSearchFieldsets(array &$element): void {
    foreach ($element as $key => &$child) {
      if (!is_string($key) || str_starts_with($key, '#') || !is_array($child)) {
        continue;
      }

      // Keep a form-scoped marker even for empty arrays; facet modules may
      // populate these later in the build pipeline.
      $child['#metsis_search_form_element'] = TRUE;

      if ((($child['#type'] ?? NULL) === 'fieldset' || self::hasFieldsetWrapper($child))
        && !isset($child['#metsis_fieldset_variant'])) {
        $child['#metsis_fieldset_variant'] = 'metsis_search';
      }

      self::markMetsisSearchFieldsets($child);
    }
  }

  /**
   * Check whether the render element is themed through a fieldset wrapper.
   */
  private static function hasFieldsetWrapper(array $element): bool {
    $wrappers = $element['#theme_wrappers'] ?? NULL;
    if (!is_array($wrappers)) {
      return FALSE;
    }

    foreach ($wrappers as $key => $value) {
      $wrapper = is_string($key) ? $key : (is_string($value) ? $value : NULL);
      if ($wrapper === NULL) {
        continue;
      }

      if ($wrapper === 'fieldset' || str_starts_with($wrapper, 'fieldset__')) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Determine whether a facet element has options rendered by BEF.
   */
  private function hasFacetChoices(array $facet_element): bool {
    if (!empty($facet_element['#options']) && is_array($facet_element['#options'])) {
      return TRUE;
    }

    foreach (array_keys($facet_element) as $key) {
      if (is_string($key) && !str_starts_with($key, '#')) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Avoid duplicate popovers when the exposed form is altered multiple times.
   */
  private function elementHasPopover(mixed $value): bool {
    return is_array($value)
      && isset($value['#theme'])
      && $value['#theme'] === 'metsis_facet_vocab_popover_button';
  }

}
