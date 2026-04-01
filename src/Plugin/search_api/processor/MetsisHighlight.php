<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\search_api\processor;

use Drupal\search_api\Plugin\search_api\processor\Highlight;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * This processor plugin extends the searc_api highlight processor plugin.
 *
 * This customized version modifies how excerpts are created for METSIS search
 * results, tailoring the highlighting to better fit METSIS's requirements.
 */
#[SearchApiProcessor(
  id: 'highlight',
  label: new TranslatableMarkup('METSIS Highlight'),
  description: new TranslatableMarkup('Customized highlighting processor for METSIS search results.'),
  stages: [
    'postprocess_query' => 0,
  ],
)]
class MetsisHighlight extends Highlight {

  /**
   * {@inheritdoc}
   */
  public function postprocessSearchResults(ResultSetInterface $results): void {
    $query = $results->getQuery();
    if (!$results->getResultCount()
    || $query->getProcessingLevel() != QueryInterface::PROCESSING_FULL
    || $query->hasTag('search_api_skip_processor_highlight')) {
      return;
    }

    // Only return an excerpt on an empty keyword if requested by configuration.
    $keys = $this->getKeywords($query);
    $excerpt_always = $this->configuration['excerpt_always'];
    if (!$excerpt_always && !$keys) {
      return;
    }

    $excerpt_fulltext_fields = [
      'title_en',
      'abstract_en',
      'personnel_name',
      'personnel_organisation',
      'data_center_long_name',
      'data_center_short_name',
      'keywords_keyword',
      'project_long_name',
      'project_short_name',
      'platform_instrument_long_name',
      'platform_instrument_short_name',
      'dataset_citation_title',
      'dataset_citation_author',
      'dataset_citation_publisher',
    ];

    $result_items = $results->getResultItems();
    if ($this->configuration['excerpt']) {
      $this->addExcerpts($result_items, $excerpt_fulltext_fields, $keys);
    }

    // Preserve backend (Solr) highlighted fields if they already exist.
    // Search API Solr can provide richer snippets than regex fallback.
    if ($this->configuration['highlight'] !== 'never' && !empty($keys)) {
      $highlighted_fields = $this->highlightFields($result_items, $keys);
      foreach ($highlighted_fields as $item_id => $item_fields) {
        $item = $result_items[$item_id];
        $existing = $item->getExtraData('highlighted_fields');
        if (is_array($existing) && !empty($existing)) {
          // Keep backend snippets and only fill missing fields.
          $item->setExtraData('highlighted_fields', $existing + $item_fields);
          continue;
        }
        $item->setExtraData('highlighted_fields', $item_fields);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function addExcerpts(array $results, array $fulltext_fields, array $keys) {
    $items = $this->getHighlightedFields($results, $fulltext_fields);
    foreach ($items as $item_id => $item) {
      if (!$item) {
        continue;
      }

      // Prepare structured field data for createExcerptForFields().
      $field_data = [];
      foreach ($item as $field_id => $values) {
        if (!is_array($values)) {
          $values = [$values];
        }

        // Solr can return empty strings for non-matching multivalue entries.
        $values = array_values(array_filter(
          $values,
          static fn($value): bool => is_string($value) && trim($value) !== ''
        ));

        if (!empty($values)) {
          $field_data[] = [
            'field_id' => $field_id,
            'values' => $values,
          ];
        }
      }

      if (empty($field_data)) {
        continue;
      }

      $item_keys = $keys;

      // If the backend already did highlighting and told us the exact keys it
      // found in the item's text values, we can use those for our own
      // highlighting. This will help us take stemming, transliteration, etc.
      // into account properly.
      $highlighted_keys = $results[$item_id]->getExtraData('highlighted_keys');
      if (is_array($highlighted_keys) && !empty($highlighted_keys)) {
        $item_keys = array_merge($highlighted_keys, $item_keys);
      }

      $results[$item_id]->setExcerpt($this->createExcerptForFields($field_data, $item_keys));
    }
  }

  /**
   * Get the solr returned highlighted_fields extradata for all results.
   *
   * @param array $results
   *   The list of results.
   * @param array $highlight_fields
   *   The list of fields to highlight.
   *
   * @return array
   *   The array with highlighted fields from solr.
   */
  protected function getHighlightedFields(array $results, array $highlight_fields): array {
    $highlighted_fields = [];
    foreach ($results as $item_id => $result) {
      $highlighted_fields[$item_id] = [];
      $item_highlighted_fields = $result->getExtraData('highlighted_fields');
      if (!is_array($item_highlighted_fields)) {
        continue;
      }

      foreach ($highlight_fields as $field) {
        $values = $item_highlighted_fields[$field] ?? [];
        if (!is_array($values)) {
          $values = [$values];
        }

        $values = array_values(array_filter(
          $values,
          static fn($value): bool => is_string($value) && trim($value) !== ''
        ));

        if (!empty($values)) {
          $highlighted_fields[$item_id][$field] = $values;
        }
      }

    }
    return $highlighted_fields;
  }

}
