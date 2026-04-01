<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\views\Plugin\views\filter\FilterPluginBase;

/**
 * Hook implementations for Views filters summary display.
 */
class ViewsFiltersSummaryHooks {

  /**
   * Implements hook_views_filters_summary_info_alter().
   *
   * Customizes the filter summary display for METSIS custom filters.
   */
  #[Hook('views_filters_summary_info_alter')]
  public function viewsFiltersSummaryInfoAlter(array &$info, FilterPluginBase $filter): void {
    // Handle custom summary for bounding box filter.
    if ($filter->getPluginId() === 'metsis_filter_bbox' && !empty($filter->value)) {
      if (!$filter->value['minX'] == '') {
        // Format each value: 3 decimals for floats, as-is for ints.
        $formatted_values = array_map(function ($v) {
          return is_float($v + 0) ? number_format((float) $v, 3, '.', '') : (string) $v;
        }, $filter->value);

        $info['value'][0] = [
          'id' => 0,
          'raw' => 'bbox',
          'value' => ucfirst($filter->operator) . '(' . implode(', ', $formatted_values) . ')',
        ];
      }
    }

    // Handle custom summary for date range filter.
    if ($filter->getPluginId() === 'metsis_filter_date_range' && !empty($filter->value)) {
      $min = $filter->value['min'] ?? '';
      $max = $filter->value['max'] ?? '';

      if ($min !== '' || $max !== '') {
        $date_range = '';
        if ($min !== '' && $max !== '') {
          $date_range = "$min to $max";
        }
        elseif ($min !== '') {
          $date_range = "from $min";
        }
        elseif ($max !== '') {
          $date_range = "to $max";
        }

        $info['value'][0] = [
          'id' => 0,
          'raw' => 'date_range',
          'value' => ucfirst($filter->operator) . '(' . $date_range . ')',
        ];
      }
    }
  }

  /**
   * Implements hook_views_filters_summary_filter_value_alter().
   *
   * Fixes the filter value population for METSIS custom filters.
   */
  #[Hook('views_filters_summary_filter_value_alter')]
  public function viewsFiltersSummaryFilterValueAlter(mixed &$value, FilterPluginBase $filter): void {
    // For bbox filter: User Permissions plugin does not properly
    // populate the filter value.
    if ($filter->getPluginId() === 'metsis_filter_bbox') {
      $inputs = $filter->view->getExposedInput();
      $value = $inputs[$filter->options['id']];
    }

    // For date range filter: Similarly ensure value is properly populated.
    if ($filter->getPluginId() === 'metsis_filter_date_range') {
      $inputs = $filter->view->getExposedInput();
      $value = $inputs[$filter->options['id']] ?? NULL;
    }
  }

}
