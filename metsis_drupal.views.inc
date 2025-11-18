<?php

/**
 * @file
 * Provide Views integration for Metsis search custom fields and filters.
 */

/**
 * Implements hook_views_data_alter().
 */
function metsis_drupal_views_data_alter(&$data) {
  // $dt_helper = \Drupal::service('search_api.data_type_helper');
  // $mappings = $dt_helper->getFieldTypeMapping();
  // $manager = \Drupal::service('plugin.manager.views.field');
  // $defs = $manager->getDefinitions();
  // Load over metsis index entity.
  // $index = Index::load(MetsisConstants::METSIS_SOLR_INDEX_ID);
  // Load our metsis index data table.
  // $table =
  // &$data['search_api_index_' . MetsisConstants::METSIS_SOLR_INDEX_ID];.
}

/**
 * Finds the field alias for a field in a Views table definition.
 *
 * @param string $field_id
 *   The original ID of the Search API field.
 * @param array $table
 *   The Views table definition.
 *
 * @return string|false
 *   The field alias of the field or FALSE.
 */
function _metsis_drupal_views_get_field_alias($field_id, array $table) {
  // We need to determine the Views field alias based on the Search API
  // field_id.
  // We can't use _search_api_views_find_field_alias, as that would generate
  // a new name.
  $field_alias = FALSE;

  if (isset($table[$field_id])) {
    $field_alias = $field_id;
  }
  else {
    foreach ($table as $field_name => $field_info) {
      if (!empty($field_info['real field']) && $field_info['real field'] == $field_id) {
        $field_alias = $field_name;
        break;
      }
    }
  }

  return $field_alias;

}
