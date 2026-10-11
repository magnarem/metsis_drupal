<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\metsis_drupal\Utility\MetsisViewContext;
use Drupal\metsis_drupal\Plugin\views\display_extender\MetsisLayout;
use Drupal\views\ViewExecutable;

/**
 * Hook implementations for views.
 */
class MetsisViewsHooks {

  use StringTranslationTrait;

  /**
   * Describes the display option added by the layout extender.
   */
  #[Hook('config_schema_info_alter')]
  public function configSchemaInfoAlter(array &$definitions): void {
    if (!isset($definitions['views_display'])) {
      return;
    }
    $definitions['views_display']['mapping']['metsis_sidebar_width'] = [
      'type' => 'string',
      'label' => 'METSIS filter sidebar width',
      'constraints' => ['Regex' => ['pattern' => MetsisLayout::WIDTH_PATTERN]],
    ];
  }

  /**
   * Keeps shared targets outside overridable and AJAX-replaced View markup.
   */
  #[Hook('page_bottom')]
  public function pageBottom(array &$page_bottom): void {
    $page_bottom['metsis_messages'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['metsis-messages-container']],
    ];
    $page_bottom['metsis_dialog'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'metsis-metadata-modal-root',
        'class' => ['metsis-metadata-modal-target'],
        'aria-live' => 'polite',
      ],
    ];
  }

  /**
   * Attaches template-independent shared dialog infrastructure.
   */
  #[Hook('views_pre_render')]
  public function preRender(ViewExecutable $view): void {
    if (MetsisViewContext::isView($view)) {
      $view->element['#attached']['library'][] = 'metsis_drupal/metsis_view_targets';
    }
  }

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
