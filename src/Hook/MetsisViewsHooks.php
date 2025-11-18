<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for views.
 */
class MetsisViewsHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_views_data().
   */
  #[Hook('views_data')]
  public function viewsData(): array {
    $data = [];
    $data['views']['metsis_map'] = [
      'title' => $this->t('METSIS Map'),
      'help' => $this->t('Render the METSIS search map in the area.'),
      'area' => [
        'id' => 'metsis_map',
      ],
    ];
    return $data;

  }

}
