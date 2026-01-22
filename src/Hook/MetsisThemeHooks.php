<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Custom theme related hooks.
 */
class MetsisThemeHooks {

  /**
   * Implement hook_theme.
   */
  #[Hook('theme')]
  public function themeHook(): array {
    return [
    // Register the main row template for your custom Views row plugin.
      'metsis_search_row_compact' => [
        'variables' => [
          'row' => NULL,
          'view' => NULL,
          'options' => NULL,
          'project' => NULL,
          'solr_doc' => NULL,
          'fields' => NULL,
          'excerpt' => NULL,
        ],
        'template' => 'metsis-search-row-compact',
      ],
      'metsis_search_row_default' => [
        'variables' => [
          'row' => NULL,
          'view' => NULL,
          'options' => NULL,
          'project' => NULL,
          'solr_doc' => NULL,
          'fields' => NULL,
          'excerpt' => NULL,
        ],
        'template' => 'metsis-search-row-default',
      ],
      'metsis_search_row_detailed' => [
        'variables' => [
          'row' => NULL,
          'view' => NULL,
          'options' => NULL,
          'project' => NULL,
          'solr_doc' => NULL,
          'fields' => NULL,
          'excerpt' => NULL,
        ],
        'template' => 'metsis-search-row-detailed',
      ],
      'metsis_search_row_custom' => [
        'variables' => [
          'row' => NULL,
          'view' => NULL,
          'options' => NULL,
          'project' => NULL,
          'solr_doc' => NULL,
          'fields' => NULL,
          'excerpt' => NULL,
        ],
        'template' => 'metsis-search-row-custom',
      ],
      'views_view__metsis_search__results' => [
        'template' => 'views-view--metsis-search--results',
        'base hook' => 'views view',
      ],
      'input__submit__search_results_submit' => [
        'render element' => 'element',
        'template' => 'input--submit--search-results-submit',
      ],
      'metsis_license_icons_component' => [
        'variables' => [
          'license_code' => NULL,
          'license_uri' => NULL,
          'icon_path' => NULL,
          'icon_alt_text' => NULL,
          'module_path' => NULL,
        ],
        'template' => 'components/metsis-license-icons-component',
      ],
      'metsis_collection_icon_component' => [
        'variables' => [
          'image_path' => NULL,
          'parent_id' => NULL,
        ],
        'template' => 'components/metsis-collection-icon-component',
      ],
      'metsis_doi_icon_component' => [
        'variables' => [
          'doi_uri' => NULL,
          'icon_path' => NULL,
        ],
        'template' => 'components/metsis-doi-icon-component',
      ],

      'metsis_filter_icon_component' => [
        'template' => 'components/metsis-filter-icon-component',
      ],

      // Add more theme hooks here if needed.
    ];
  }

  /**
   * Special theme hook suggestion for metsis search box.
   *
   * Implements hook_theme_suggestions_HOOK_alter().
   *
   * {@inheritdoc}
   */
  #[Hook('theme_suggestions_input_alter')]
  public function metsisSearchInputSuggestion(&$suggestions, array $variables) {
    $element = $variables['element'];
    // Mainly used to swap the search input to a button.
    if (isset($element['#attributes']['data-twig-suggestion'])) {
      $suggestions[] = 'input__' . $element['#type'] . '__' . $element['#attributes']['data-twig-suggestion'];
    }
  }

  /**
   * Special theme hook suggestion for metsis exposed form.
   *
   * Implements hook_theme_suggestions_HOOK_alter().
   *
   * {@inheritdoc}
   */
  #[Hook('theme_suggestions_views_exposed_form_alter')]
  public function themeHookSuggestion(&$suggestions, array $variables) {
  }

}
