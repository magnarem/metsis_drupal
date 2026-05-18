<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Render\Markup;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
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
      // Attach vocab popover library for facet filters.
      if (!isset($form['#attached']['library'])) {
        $form['#attached']['library'] = [];
      }
      $form['#attached']['library'][] = 'metsis_drupal/metsis_vocab_popover';
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
      // Inject vocabulary info popovers into fieldset descriptions for mapped filters.
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
        if ($this->elementHasPopover($form[$field_name]['#description'] ?? NULL)) {
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

        if (!isset($form[$field_name]['#description'])) {
          $form[$field_name]['#description'] = '';
        }

        if (is_string($form[$field_name]['#description'])) {
          $existing = $form[$field_name]['#description'];
        } elseif ($form[$field_name]['#description'] instanceof MarkupInterface) {
          $existing = (string) $form[$field_name]['#description'];
        } else {
          $existing = '';
        }

        $description_array = [];
        if ($existing !== '') {
          $description_array['existing'] = [
            '#markup' => '<span class="description">' . Html::escape($existing) . '</span>',
          ];
        }
        $description_array['popover'] = $popover_element;

        $form[$field_name]['#description'] = $description_array;
        $form[$field_name]['#description_display'] = 'before';
      }
    }
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
    // Check render array structure for popover theme hook.
    if (is_array($value)) {
      return isset($value['popover']['#theme']) && $value['popover']['#theme'] === 'metsis_facet_vocab_popover_button';
    }

    if (!is_string($value) && !$value instanceof MarkupInterface) {
      return FALSE;
    }

    return str_contains((string) $value, 'metsis-facet-vocab-popover-trigger');
  }

}
