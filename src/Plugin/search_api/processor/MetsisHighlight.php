<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\search_api\processor;

use Drupal\search_api\Plugin\search_api\processor\Highlight;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * This processor plugin extends the searc_api highlight processor plugin.
 *
 * This customized version modifies how excerpts are created for METSIS search
 * results, tailoring the highlighting to better fit METSIS's requirements.
 */
#[SearchApiProcessor(
  id: 'metsis_highlight',
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
  protected function addExcerpts(array $results, array $fulltext_fields, array $keys) {
    $items = $this->getFulltextFields($results, $fulltext_fields);
    // $items = $this->getIndex()->getFields();
    foreach ($items as $item_id => $item) {
      if (!$item) {
        continue;
      }

      // Prepare structured field data instead of concatenating everything.
      $field_data = [];
      foreach ($item as $field_id => $values) {
        $hl_fields = $results[$item_id]->getExtraData('search_api_solr_document')->getFields() ?? [];
        $field_data[] = [
          'field_id' => $field_id,
          'values' => $fields[$field_id] ?? [],
        ];
      }

      $item_keys = $keys;

      // If the backend already did highlighting and told us the exact keys it
      // found in the item's text values, we can use those for our own
      // highlighting. This will help us take stemming, transliteration, etc.
      // into account properly.
      $item_keys = $results[$item_id]->getExtraData('highlighted_keys') ?: $item_keys;

      $results[$item_id]->setExcerpt($this->createExcerptForFields($field_data, $item_keys));
    }
  }

}
