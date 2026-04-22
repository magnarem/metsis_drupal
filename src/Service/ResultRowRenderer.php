<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\metsis_drupal\Utility\MetsisHelper;
use Drupal\metsis_drupal\LoggerTrait;

/**
 * This class handles the rendering of a dataset result row.
 *
 * Used by MetsisSearchRow views row plugin.
 */
final class ResultRowRenderer {


  use LoggerTrait;
  use StringTranslationTrait;

  /**
   * Metsis search helper service.
   *
   * @var \Drupal\metsis_drupal\Utility\MetsisHelper
   */
  protected MetsisHelper $metsisHelper;


  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;


  /**
   * The metsis_drupal.license_icons config object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected ImmutableConfig $licenseIconsConfig;


  /**
   * The metsis_drupal module extension.
   *
   * @var \Drupal\Core\Extension\Extension
   */

  protected $moduleExtension;

  /**
   * The Met vocabulary service.
   *
   * @var \Drupal\metsis_drupal\Service\MetVocabServiceInterface
   */
  protected MetVocabServiceInterface $metVocabService;

  /**
   * Constructs a ResultRowRenderer object.
   */
  public function __construct(
    MetsisHelper $metsis_helper,
    ModuleHandlerInterface $module_handler,
    ConfigFactoryInterface $config_factory,
    MetVocabServiceInterface $met_vocab_service,
  ) {
    $this->metsisHelper = $metsis_helper;
    $this->moduleExtension = $module_handler->getModule('metsis_drupal');
    $this->configFactory = $config_factory;
    $this->licenseIconsConfig = $config_factory->get('metsis_drupal.license_icons');
    $this->metVocabService = $met_vocab_service;

  }

  /**
   * Render a metsis result row, based on the row plugin configuration.
   *
   * @param array $solr_doc
   *   The raw solr document result.
   * @param array $options
   *   The row plugin configuration options.
   * @param array $highlighted
   *   The highlighted snippets returned.
   *
   * @return array
   *   The constructed solr feilds render array.
   */
  public function renderRow(array $solr_doc, array $options, array $highlighted): array {
    $fields = [];

    // Handle title. Use highlighted field if available.
    $fields['title'] = [
      '#markup' => $solr_doc['title'] ?? '',
    ];
    if (!empty($highlighted['title_en'])) {
      $fields['title'] = [
        '#markup' => $highlighted['title_en'][0],
      ];
    }
    // Handle abstract/description. Use highlighted field if available.
    $fields['metadata_identifier'] = $solr_doc['metadata_identifier'] ?? '';
    $fields['id'] = $this->metsisHelper->toSolrId($solr_doc['metadata_identifier']);
    $fields['abstract'] = [
      '#markup' => check_markup($solr_doc['abstract'], 'metsis_html') ?? '',
    ];
    if (!empty($highlighted['abstract_en'])) {
      $abstract = $highlighted['abstract_en'][0];
      $abstract_html = check_markup($abstract, 'metsis_html');
      $fields['abstract'] = $abstract_html;
    }
    // Convert plain URLs in abstract to <a href> links.
    // $fields['abstract'] = $this->linkify($fields['abstract']);
    // Labding page URL.
    $fields['landing_page'] = $solr_doc['related_url_landing_page'][0] ?? '';

    // Build leaflet map if geometry is available.
    if (!empty($solr_doc['geometry_geojson'])) {
      $fields['leaflet_map'] = $this->metsisHelper->buildLeafletMap($solr_doc['geometry_geojson'], '250px');
    }

    if (!empty($solr_doc['use_constraint_identifier'])) {
      $fields['license_icon'] = $this->getLicenseIconMarkup(
        $solr_doc['use_constraint_identifier']
      );
    }
    if (!empty($solr_doc['isParent']) && $solr_doc['isParent'] == TRUE) {
      $fields['parent'] = $this->getCollectionIconMarkup(
        $solr_doc['metadata_identifier']
      );
      $fields['children_count'] = $this->getChildDatasetCountMarkup($solr_doc);
    }

    if (!empty($solr_doc['dataset_citation_doi'])) {
      $var = $solr_doc['dataset_citation_doi'];
      $doi_uri = is_string($var) ? $var : (is_array($var) ? reset($var) : NULL);
      if (NULL !== $doi_uri) {
        $fields['doi_icon'] = $this->getDoiIconMarkup($doi_uri);
      }
    }

    if (!empty($solr_doc['thumbnail_url'])) {
      $fields['thumbnail'] = [
        '#theme' => 'image',
        '#uri' => $solr_doc['thumbnail_url'],
        '#alt' => $solr_doc['title'] ?? 'Dataset thumbnail',
        '#attributes' => [
          'class' => ['metsis-search-thumbnail'],
          'loading' => 'lazy',
        ],
      ];
    }

    $fields['last_metadata_update'] = $this->getLatestMetadataUpdate($solr_doc);
    $fields['temporal_extent'] = $this->getTemporalExtentMarkup($solr_doc);

    return $fields;
  }

  /**
   * Returns the latest value from last_metadata_update_datetime.
   *
   * Keeps source multivalue data in $solr_doc untouched and only provides a
   * derived single value for template rendering.
   *
   * @param array $solr_doc
   *   The raw Solr document.
   *
   * @return string
   *   The latest datetime string, or empty string when unavailable.
   */
  private function getLatestMetadataUpdate(array $solr_doc): string {
    if (empty($solr_doc['last_metadata_update_datetime'])) {
      return '';
    }

    $values = $solr_doc['last_metadata_update_datetime'];
    if (!\is_array($values)) {
      return \is_string($values) ? $values : '';
    }

    $latest_value = '';
    $latest_timestamp = NULL;
    $string_values = [];

    foreach ($values as $value) {
      if (!\is_string($value) || $value === '') {
        continue;
      }
      $string_values[] = $value;
      $timestamp = strtotime($value);
      if ($timestamp === FALSE) {
        continue;
      }
      if ($latest_timestamp === NULL || $timestamp > $latest_timestamp) {
        $latest_timestamp = $timestamp;
        $latest_value = $value;
      }
    }

    if ($latest_value !== '') {
      return $latest_value;
    }

    sort($string_values);
    return $string_values !== [] ? (string) end($string_values) : '';
  }

  /**
   * Generate markup for child dataset count.
   *
   * @param array $solr_doc
   *   The solr document array.
   *
   * @return array
   *   The render array for this component.
   */
  public function getChildDatasetCountMarkup(array $solr_doc): array {
    $found_children = $solr_doc['found_children']['numFound'] ?? 0;
    $total_children = $solr_doc['total_children']['numFound'] ?? 0;

    $renderArray = [
      '#markup' => $this->t('@found of @total', [
        '@found' => $found_children,
        '@total' => $total_children,
      ]),
    ];
    return $renderArray;
  }

  /**
   * Generate markup for the temporal extent.
   *
   * @param array $solr_doc
   *   The solr document raw values.
   * @param bool $add_icon
   *   Add an icon. (optional)
   *   Default: True.
   * @param string $default_icon
   *   The icon_id to use. (optional)
   *   Default: date-time.
   * @param bool $short_notation
   *   Render as compact inline string instead of fieldset-like block.
   *   Default: False.
   * @param bool $compact_labels
   *   When short_notation is false, put label and value on the same line.
   *   Default: True.
   *
   * @return array
   *   The render array for this field.
   */
  public function getTemporalExtentMarkup(
    array $solr_doc,
    bool $add_icon = TRUE,
    string $default_icon = 'date-time',
    bool $short_notation = FALSE,
    bool $compact_labels = TRUE,
  ): array {
    $start_date = '';
    $end_date = '';

    if (!empty($solr_doc['temporal_extent_start_date'])) {
      $start_date = (string) $solr_doc['temporal_extent_start_date'][0];
    }
    if (!empty($solr_doc['temporal_extent_end_date'])) {
      $end_date = (string) $solr_doc['temporal_extent_end_date'][0];
    }

    if ($start_date === '') {
      return [];
    }

    return [
      '#type' => 'component',
      '#component' => 'metsis_drupal:temporal_extent',
      '#props' => [
        'start_date' => $start_date,
        'end_date' => $end_date,
        'add_icon' => $add_icon,
        'icon_id' => $default_icon,
        'short_notation' => $short_notation,
        'compact_labels' => $compact_labels,
      ],
    ];
  }

  /**
   * Generate icon and link for DOI.
   *
   * @param string $doi_url
   *   The id of this collection.
   * @param bool $show_identifier
   *   (Optional) Show the idenifier text with the link and icon.
   *   Default: False.
   * @param bool $color
   *   (Optional) Use colored icon (origianl DOI logo).
   *   Default: True.
   *
   * @return array
   *   The render array for this component.
   */
  public function getDoiIconMarkup(string $doi_url, bool $show_identifier = FALSE, bool $color = TRUE): array {
    // Return the doi Icon with url.
    return [
      '#type' => 'component',
      '#component' => 'metsis_drupal:doi',
      '#props' => [
        'doi_url' => $doi_url,
        'show_doi_identifier' => $show_identifier,
        'color' => $color,
      ],
    ];
  }

  /**
   * Create license icon markup for a given license code.
   *
   * @param string $license_code
   *   The license code.
   *
   * @return array
   *   The render array for the license icon component.
   */
  public function getLicenseIconMarkup(string $license_code): array {
    // Get the config for license icons mapping.
    $license_icons_config = $this->metsisHelper->getLicenseIconsConfig();
    $licenses = $license_icons_config->get('license_icons');

    if ($license_code === 'Not provided') {
      return [];
    }

    // Replace _ with . to match config keys.
    $license_type = str_replace('.', '_', $license_code);
    if (empty($licenses)) {
      return [];
    }
    if (!isset($licenses[$license_type])) {
      $this->getLogger()->warning('No license icon mapping found for license code: @code', ['@code' => $license_code]);
      return [];
    }
    $license = $licenses[$license_type];

    return [
      '#type' => 'component',
      '#component' => 'metsis_drupal:cc_license',
      '#props' => [
        'license_id' => $license_code,
        'license_url' => $license['license_uri'],
        'icon_id' => $license['icon_id'],
        'icon_alt_text' => $license['icon_alt_text'],
        'width' => 88,
      ],
    ];
  }

  /**
   * Generate Icon markup for collection image.
   *
   * @param string $parent_id
   *   The id of this collection.
   *
   * @return array
   *   The render array for this component.
   */
  public function getCollectionIconMarkup(string $parent_id): array {
    $image_path = '/' . $this->metsisHelper->getModulePath() . '/assets/images/collection';

    return [
      '#theme' => 'metsis_collection_icon_component',
      '#image_path' => $image_path,
      '#parent_id' => $parent_id,
    ];
  }

}
