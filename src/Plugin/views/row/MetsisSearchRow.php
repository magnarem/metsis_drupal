<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\views\row;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\search_api\Plugin\views\row\SearchApiRow;
use Drupal\Core\Form\FormStateInterface;
use Drupal\metsis_drupal\Utility\MetsisHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\filter\FilterPluginManager;

/**
 * Provides a Views row plugin for METSIS search results.
 *
 * @ViewsRow(
 *   id = "metsis_search_row",
 *   title = @Translation("METSIS result row"),
 *   help = @Translation("Renders search results using selectable row styles and optional operation links.")
 * )
 */
class MetsisSearchRow extends SearchApiRow implements ContainerFactoryPluginInterface {

  /**
   * Does the row plugin support to add fields to its output.
   *
   * @var bool
   */
  protected $usesFields = FALSE;

  /**
   * Metsis search helper service.
   *
   * @var \Drupal\metsis_drupal\Utility\MetsisHelper
   */
  protected MetsisHelper $metsisHelper;

  /**
   * The filter plugin manager service.
   *
   * @var \Drupal\filter\FilterPluginManager
   */
  protected $filterPluginManager;

  /**
   * Construct the plugin with DI.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\metsis_drupal\Utility\MetsisHelper $metsis_helper
   *   The MetsismetsisHelper service.
   * @param \Drupal\filter\FilterPluginManager $filter_plugin_manager
   *   The filter plugin manager service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MetsisHelper $metsis_helper,
    FilterPluginManager $filter_plugin_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->metsisHelper = $metsis_helper;
    $this->filterPluginManager = $filter_plugin_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('metsis_drupal.metsis_helper'),
      $container->get('plugin.manager.filter')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['show_operations'] = ['default' => TRUE];
    // default, compact, detailed, custom.
    $options['style'] = ['default' => 'default'];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    $form['show_operations'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Render operation links'),
      '#default_value' => $this->options['show_operations'],
      '#description' => $this->t('If enabled, the row will receive operation links (view/edit) when available.'),
    ];

    $form['style'] = [
      '#type' => 'select',
      '#title' => $this->t('Row style'),
      '#options' => [
        'default' => $this->t('Default'),
        'compact' => $this->t('Compact'),
        'detailed' => $this->t('Detailed'),
        'custom' => $this->t('Custom'),
      ],
      '#default_value' => $this->options['style'],
      '#description' => $this->t('Choose which row template/style to use for rendering results.'),
    ];

    // Fallback options for all styles.
    $fallback_options = [
      'short' => $this->t('Short'),
      'long' => $this->t('Long'),
    ];

    $form['datacenter_name_fallback'] = [
      '#type' => 'select',
      '#title' => $this->t('Datacenter name fallback'),
      '#options' => $fallback_options,
      '#default_value' => $this->options['datacenter_name_fallback'] ?? 'long',
      '#description' => $this->t('Configure to use short or long name as default fallback if one does not exist.'),
    ];
    $form['project_name_fallback'] = [
      '#type' => 'select',
      '#title' => $this->t('Project name fallback'),
      '#options' => $fallback_options,
      '#default_value' => $this->options['project_name_fallback'] ?? 'long',
      '#description' => $this->t('Configure to use short or long name as default fallback if one does not exist.'),
    ];
    $form['platform_name_fallback'] = [
      '#type' => 'select',
      '#title' => $this->t('Platform name fallback'),
      '#options' => $fallback_options,
      '#default_value' => $this->options['platform_name_fallback'] ?? 'long',
      '#description' => $this->t('Configure to use short or long name as default fallback if one does not exist.'),
    ];
    $form['platform_instrument_name_fallback'] = [
      '#type' => 'select',
      '#title' => $this->t('Platform instrument name fallback'),
      '#options' => $fallback_options,
      '#default_value' => $this->options['platform_instrument_name_fallback'] ?? 'long',
      '#description' => $this->t('Configure to use short or long name as default fallback if one does not exist.'),
    ];

    // Example: Show extra config only for 'detailed' style.
    $form['extra_detailed_config'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Extra config for detailed style'),
      '#states' => [
        'visible' => [
          ':input[name="style"]' => ['value' => 'detailed'],
        ],
      ],
    ];

    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    $this->options['show_operations'] = $form_state->getValue('show_operations');
    $this->options['style'] = $form_state->getValue('style');
    parent::submitOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\search_api\Plugin\views\ResultRow $row
   *   The result row.
   *
   * @return array|string
   *   The rendered row.
   */
  public function render($row): array|string {

    // Determine style and theme name.
    $style = $this->options['style'] ?? 'default';
    $theme_hook = 'metsis_search_row_' . $style;

    // @todo Handle dataset operations rendering.
    $operations = [];
    if (!empty($this->options['show_operations'])) {
    }

    // Get the raw solr document results.
    $solr_doc = $row->_item->getExtraData('search_api_solr_document')->getFields();

    // Build leaflet map if geometry is available.
    if (!empty($solr_doc['geometry_geojson'])) {
      $solr_doc['leaflet_markup'] = $this->metsisHelper->buildLeafletMap($solr_doc['geometry_geojson']);
    }

    // Try to retrieve highlighted data.
    $highlighted_data = $row->_item->getExtraData('highlighted_fields') ?? [];
    // dpm($highlighted_data, 'highlighted_data');.
    // Build render array.
    $build = [
      '#theme' => $theme_hook,
      '#view' => $this->view,
      '#options' => $this->options,
      '#row' => $row,
      '#solr_doc' => $solr_doc,
      '#excerpt' => $highlighted_data,
      '#fields' => $this->processSolrDoc($solr_doc, $highlighted_data),
    ];

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function optionsSummary() {
    $summary = [];
    $summary[] = $this->t('Style: @style', ['@style' => $this->options['style']]);
    $summary[] = $this->t('Operations: @val', ['@val' => ($this->options['show_operations'] ? $this->t('Enabled') : $this->t('Disabled'))]);
    return $summary;
  }

  /**
   * Extract and process solr document fields for rendering.
   *
   * @param array $solr_doc
   *   The solr document fields.
   * @param array $highlight
   *   The highlighted fields.
   *
   * @return array
   *   The processed fields.
   */
  private function processSolrDoc(array $solr_doc, array $highlight): array {

    $fields = [];

    // Merge highlighted fields if available.
    // $solr_doc = array_merge($solr_doc, $highlight);.
    // Handle title. Use highlighted field if available.
    $fields['title'] = [
      '#markup' => $solr_doc['title'] ?? '',
    ];
    if (!empty($highlight['title_en'])) {
      $fields['title'] = [
        '#markup' => $highlight['title_en'][0],
      ];
    }
    // Handle abstract/description. Use highlighted field if available.
    $fields['id'] = trim($solr_doc['metadata_identifier']);
    $fields['abstract'] = [
      '#markup' => check_markup($solr_doc['abstract'], 'metsis_html') ?? '',
    ];
    if (!empty($highlight['abstract_en'])) {
      $abstract = $highlight['abstract_en'][0];
      $abstract_html = check_markup($abstract, 'metsis_html');
      $fields['abstract'] = $abstract_html;
      // $fields['abstract'] = $processed_html;
    }
    // Convert plain URLs in abstract to <a href> links.
    // $fields['abstract'] = $this->linkify($fields['abstract']);
    // Labding page URL.
    $fields['landing_page'] = $solr_doc['related_url_landing_page'][0] ?? '';
    // License icon data.
    if (!empty($solr_doc['use_constraint_identifier'])) {
      $fields['license_icon'] = $this->metsisHelper->getLicenseIconMarkup(
        $solr_doc['use_constraint_identifier']
      );
    }
    if (!empty($solr_doc['isParent']) && $solr_doc['isParent'] == TRUE) {
      $fields['parent'] = $this->metsisHelper->getCollectionIconMarkup(
        $solr_doc['metadata_identifier']
      );
      $fields['children_count'] = $this->metsisHelper->getChildDatasetCountMarkup($solr_doc);
    }

    if (!empty($solr_doc['dataset_citation_doi'])) {
      $var = $solr_doc['dataset_citation_doi'];
      $doi_uri = is_string($var) ? $var : (is_array($var) ? reset($var) : NULL);
      if (NULL !== $doi_uri) {
        $fields['doi_icon'] = $this->metsisHelper->getDoiIconMarkup($doi_uri);
      }
    }

    if (!empty($solr_doc['thumbnail_url'])) {
      $fields['thumbnail'] = [
        '#theme' => 'image',
        '#uri' => $solr_doc['thumbnail_url'],
        '#alt' => $solr_doc['title'] ?? 'Dataset thumbnail',
        '#attributes' => ['class' => ['metsis-search-thumbnail']],
      ];
    }

    return $fields;
  }

}
