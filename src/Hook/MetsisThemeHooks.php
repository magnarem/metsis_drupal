<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\metsis_drupal\Service\MetVocabServiceInterface;

/**
 * Custom theme related hooks.
 */
class MetsisThemeHooks {

  /**
   * Constructor.
   */
  public function __construct(
    private readonly MetVocabServiceInterface $metVocabService,
  ) {}

  /**
   * Implement hook_theme.
   */
  #[Hook('theme')]
  public function themeHook(): array {
    return [
    // Register the main row template for your custom Views row plugin.
      'metsis_metadata_document' => [
        'variables' => [
          'id' => NULL,
          'title' => '',
          'abstract' => '',
          'metadata_updates' => [],
          'summary' => [],
          'sections' => [],
          'raw' => [],
        ],
        'template' => 'metsis-metadata-document',
      ],
      'metsis_metadata_document_dialog' => [
        'variables' => [
          'content' => [],
        ],
        'template' => 'metsis-metadata-document-dialog',
      ],
      'metsis_wms_document' => [
        'variables' => [
          'id' => NULL,
          'title' => '',
          'metadata_identifier' => '',
          'wms_endpoints' => [],
        ],
        'template' => 'metsis-wms-document',
      ],
      'metsis_search_row_compact' => [
        'variables' => [
          'row' => NULL,
          'view' => NULL,
          'options' => NULL,
          'project' => NULL,
          'solr_doc' => NULL,
          'fields' => NULL,
          'excerpt' => NULL,
          'highlighted' => NULL,
          'operations' => [],
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
          'highlighted' => NULL,
          'operations' => [],
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
          'highlighted' => NULL,
          'operations' => [],
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
          'highlighted' => NULL,
          'operations' => [],
        ],
        'template' => 'metsis-search-row-custom',
      ],
      'views_view__metsis_search__results' => [
        'template' => 'views-view--metsis-search--results',
        'base hook' => 'views view',
      ],
      'input__submit__search_results_submit' => [
        'render element' => 'element',
        'template' => 'components/input--submit--search-results-submit',
      ],
      'metsis_collection_icon_component' => [
        'variables' => [
          'image_path' => NULL,
          'parent_id' => NULL,
        ],
        'template' => 'components/metsis-collection-icon-component',
      ],
      'metsis_facet_vocab_popover_button' => [
        'variables' => [
          'popover_id' => '',
          'label' => '',
          'definition' => '',
          'uri' => '',
          'title' => '',
        ],
        'template' => 'metsis-facet-vocab-popover-button',
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

  /**
   * Add vocabulary popover metadata to mapped facet headers.
   *
   * Implements hook_preprocess_facets_item_list().
   *
   * @param array $variables
   *   Template variables.
   */
  #[Hook('preprocess_facets_item_list')]
  public function preprocessFacetsItemList(array &$variables): void {
    $facet = $variables['facet'] ?? NULL;
    if (!is_object($facet) || !method_exists($facet, 'id')) {
      return;
    }

    $facet_id = (string) $facet->id();
    $group_key = $this->mapFacetIdToVocabularyGroup($facet_id);
    if ($group_key === NULL) {
      return;
    }

    $group = $this->metVocabService->getGroup($group_key);
    if (!is_array($group)) {
      return;
    }

    $label = trim((string) ($group['label'] ?? ''));
    $definition = trim((string) ($group['definition'] ?? ''));
    $uri = trim((string) ($group['uri'] ?? ''));

    if ($label === '' && $definition === '' && $uri === '') {
      return;
    }

    $safe_facet_id = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $facet_id));
    $safe_facet_id = trim($safe_facet_id, '-_');
    if ($safe_facet_id === '') {
      $safe_facet_id = 'facet';
    }

    $variables['facet_vocab_group'] = [
      'label' => $label,
      'definition' => $definition,
      'uri' => $uri,
      'popover_id' => 'facet-vocab-' . $safe_facet_id,
    ];
  }

  /**
   * Map known facet IDs to MMD vocabulary group keys.
   *
   * @param string $facet_id
   *   Facet machine id.
   *
   * @return string|null
   *   Vocabulary group key or NULL when unmapped.
   */
  private function mapFacetIdToVocabularyGroup(string $facet_id): ?string {
    $mapping = [
      'facets_activity_type' => 'Activity_Type',
      'activity_type' => 'Activity_Type',
      'facets_collection' => 'Collection_Keywords',
      'collection' => 'Collection_Keywords',
    ];

    return $mapping[$facet_id] ?? NULL;
  }

}
