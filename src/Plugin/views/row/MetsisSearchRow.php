<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\views\row;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Htmx\Htmx;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\search_api\Plugin\views\ResultRow;
use Drupal\search_api\Plugin\views\row\SearchApiRow;
use Drupal\Core\Form\FormStateInterface;
use Drupal\metsis_drupal\Service\MetadataExportService;
use Drupal\metsis_drupal\Service\ResultRowRenderer;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * Result row renderer service.
   *
   * @var \Drupal\metsis_drupal\Service\ResultRowRenderer
   */
  protected ResultRowRenderer $rowRenderer;

  /**
   * Metadata export service.
   *
   * @var \Drupal\metsis_drupal\Service\MetadataExportService
   */
  protected MetadataExportService $metadataExportService;

  /**
   * Cached enabled export options with descriptions, keyed by type.
   *
   * @var array<string, array{label: string, description: string}>|null
   */
  private ?array $exportOptionsCache = NULL;

  /**
   * Construct the plugin with DI.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\metsis_drupal\Service\ResultRowRenderer $metsis_row_renderer
   *   The result row renderer service.
   * @param \Drupal\metsis_drupal\Service\MetadataExportService $metadata_export_service
   *   The metadata export service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ResultRowRenderer $metsis_row_renderer,
    MetadataExportService $metadata_export_service,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->rowRenderer = $metsis_row_renderer;
    $this->metadataExportService = $metadata_export_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('metsis_drupal.result_row_renderer'),
      $container->get('metsis_drupal.metadata_export_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['show_operations'] = ['default' => TRUE];
    $options['component_icon_width'] = ['default' => 88];
    $options['doi_show_identifier'] = ['default' => FALSE];
    $options['doi_icon_size'] = ['default' => 24];
    $options['doi_logo_color'] = ['default' => TRUE];
    $options['temporal_extent_short_notation'] = ['default' => FALSE];
    $options['temporal_extent_compact_labels'] = ['default' => TRUE];
    $options['temporal_extent_add_icon'] = ['default' => TRUE];
    $options['temporal_extent_icon_id'] = ['default' => 'date-time'];
    // default, compact, detailed, custom.
    $options['style'] = ['default' => 'default'];
    $options['view_modes'] = ['default' => []];
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

    $form['component_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Component settings'),
    ];

    $form['component_settings']['shared_icons'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Shared icon settings'),
    ];

    $form['component_settings']['shared_icons']['component_icon_width'] = [
      '#type' => 'number',
      '#title' => $this->t('Collection and license icon width'),
      '#default_value' => (int) ($this->options['component_icon_width'] ?? 88),
      '#description' => $this->t('Shared width (px) for the collection and CC license components.'),
      '#min' => 16,
      '#max' => 256,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['component_settings']['doi_component'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('DOI component'),
    ];

    $form['component_settings']['doi_component']['doi_show_identifier'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show DOI identifier text'),
      '#default_value' => !empty($this->options['doi_show_identifier']),
      '#description' => $this->t('Show DOI identifier text next to the DOI icon.'),
    ];

    $form['component_settings']['doi_component']['doi_icon_size'] = [
      '#type' => 'number',
      '#title' => $this->t('DOI icon size'),
      '#default_value' => (int) ($this->options['doi_icon_size'] ?? 24),
      '#description' => $this->t('Icon size (px) for the DOI component.'),
      '#min' => 12,
      '#max' => 128,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['component_settings']['doi_component']['doi_logo_color'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use original DOI logo colors'),
      '#default_value' => !empty($this->options['doi_logo_color']),
      '#description' => $this->t('When unchecked, DOI icon is rendered in black/white style.'),
    ];

    $form['component_settings']['temporal_extent_component'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Temporal extent component'),
    ];

    $form['component_settings']['temporal_extent_component']['temporal_extent_short_notation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Temporal extent short notation'),
      '#default_value' => !empty($this->options['temporal_extent_short_notation']),
      '#description' => $this->t('Render temporal extent as "start to end" (or just start when end is empty).'),
    ];

    $form['component_settings']['temporal_extent_component']['temporal_extent_compact_labels'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Temporal extent compact labels'),
      '#default_value' => !empty($this->options['temporal_extent_compact_labels']),
      '#description' => $this->t('When short notation is disabled: checked = label and value on one line, unchecked = stacked lines.'),
      '#states' => [
        'visible' => [
          ':input[name$="[temporal_extent_short_notation]"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['component_settings']['temporal_extent_component']['temporal_extent_add_icon'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show temporal extent icon'),
      '#default_value' => !empty($this->options['temporal_extent_add_icon']),
      '#description' => $this->t('Display icon in the temporal extent header.'),
    ];

    $form['component_settings']['temporal_extent_component']['temporal_extent_icon_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Temporal extent icon ID'),
      '#default_value' => (string) ($this->options['temporal_extent_icon_id'] ?? 'date-time'),
      '#description' => $this->t('Icon ID in the metsis_drupal icon pack used for temporal extent (for example: date-time).'),
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name$="[temporal_extent_add_icon]"]' => ['checked' => TRUE],
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
    $component_settings = (array) $form_state->getValue('component_settings', []);
    $shared_icons = (array) ($component_settings['shared_icons'] ?? []);
    $doi_component = (array) ($component_settings['doi_component'] ?? []);
    $temporal_component = (array) ($component_settings['temporal_extent_component'] ?? []);

    $this->options['component_icon_width'] = max(16, (int) ($shared_icons['component_icon_width'] ?? $form_state->getValue('component_icon_width') ?? 88));
    $this->options['doi_show_identifier'] = !empty($doi_component['doi_show_identifier'] ?? $form_state->getValue('doi_show_identifier'));
    $this->options['doi_icon_size'] = max(12, (int) ($doi_component['doi_icon_size'] ?? $form_state->getValue('doi_icon_size') ?? 24));
    $this->options['doi_logo_color'] = !empty($doi_component['doi_logo_color'] ?? $form_state->getValue('doi_logo_color'));
    $this->options['temporal_extent_short_notation'] = !empty($temporal_component['temporal_extent_short_notation'] ?? $form_state->getValue('temporal_extent_short_notation'));
    $this->options['temporal_extent_compact_labels'] = !empty($temporal_component['temporal_extent_compact_labels'] ?? $form_state->getValue('temporal_extent_compact_labels'));
    $this->options['temporal_extent_add_icon'] = !empty($temporal_component['temporal_extent_add_icon'] ?? $form_state->getValue('temporal_extent_add_icon'));
    $icon_id = trim((string) ($temporal_component['temporal_extent_icon_id'] ?? $form_state->getValue('temporal_extent_icon_id') ?? 'date-time'));
    $this->options['temporal_extent_icon_id'] = $icon_id !== '' ? $icon_id : 'date-time';
    parent::submitOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function render($row): array|string {
    // Determine style and theme name.
    $style = $this->options['style'] ?? 'default';
    $theme_hook = 'metsis_search_row_' . $style;

    // Get the raw solr document results.
    $solr_doc = $row->_item->getExtraData('search_api_solr_document')->getFields();

    // Get the highlighted solr fields if available.
    $highlighted_fields = $row->_item->getExtraData('highlighted_fields') ?? [];

    // Build the generated excerpt as a safe render array if available.
    $excerpt = $this->buildExcerpt($row);

    // Generate the rendered fields array.
    $fields = $this->rowRenderer->renderRow($solr_doc, $this->options, $highlighted_fields);

    // Build operations block.
    $operations = $this->buildOperations($solr_doc, $fields, $row);

    // Build render array for row style.
    return [
      '#theme' => $theme_hook,
      '#view' => $this->view,
      '#options' => $this->options,
      '#row' => $row,
      '#solr_doc' => $solr_doc,
      '#highlighted' => $highlighted_fields,
      '#excerpt' => $excerpt,
      '#fields' => $fields,
      '#operations' => $operations,
    ];
  }

  /**
   * Build excerpt render array from the row item.
   *
   * @param \Drupal\search_api\Plugin\views\ResultRow $row
   *   The result row.
   *
   * @return array
   *   Render array for excerpt or empty array.
   */
  private function buildExcerpt(ResultRow $row): array {
    $excerpt = [];
    $excerpt_markup = $row->_item->getExcerpt();
    if (!empty($excerpt_markup)) {
      $excerpt = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['metsis-search-excerpt'],
        ],
        'content' => [
          '#markup' => $excerpt_markup,
          '#allowed_tags' => ['em', 'strong', 'span'],
        ],
      ];
    }
    return $excerpt;
  }

  /**
   * Build operations render array.
   *
   * @param array $solr_doc
   *   The Solr document.
   * @param array $fields
   *   The rendered fields.
   * @param \Drupal\search_api\Plugin\views\ResultRow $row
   *   The result row.
   *
   * @return array
   *   Render array for operations or empty array.
   */
  private function buildOperations(array $solr_doc, array $fields, ResultRow $row): array {
    $operations = [];
    if (empty($this->options['show_operations'])) {
      return $operations;
    }

    $metadata_identifier = (string) ($solr_doc['id'] ?? '');
    $dataset_identifier = (string) ($fields['metadata_identifier'] ?? '');
    $row_id = (string) ($fields['id'] ?? '');
    $popover_id = 'metsis-export-popover-' . $row_id;

    $is_parent = !empty($solr_doc['isParent']) && $solr_doc['isParent'] == TRUE;
    $operations = [
      '#type' => 'container',
      '#attributes' => ['class' => ['metsis-row-operations']],
      '#attached' => [
        'library' => ['core/drupal.dialog.ajax'],
      ],
      'controls' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['metsis-row-operations-controls']],
      ],
    ];

    // Add collection filter if parent.
    if ($is_parent && $dataset_identifier !== '') {
      $operations['controls']['collection_filter'] = $this->buildCollectionFilter($solr_doc, $dataset_identifier);
    }

    // Add metadata preview link opened in a Drupal modal.
    if ($metadata_identifier !== '') {
      $this->buildMetadataModalTrigger($operations, $metadata_identifier);
    }

    // Add export options if metadata identifier present.
    if ($metadata_identifier !== '' && $row_id !== '') {
      $this->buildExportOptions($operations, $metadata_identifier, $row_id, $popover_id);
    }

    // Add plot trigger if applicable.
    $this->buildPlotTrigger($operations, $solr_doc, $row_id);

    return $operations;
  }

  /**
   * Build metadata modal trigger link.
   *
   * @param array $operations
   *   The operations array to mutate.
   * @param string $metadata_identifier
   *   The Solr document id.
   */
  private function buildMetadataModalTrigger(array &$operations, string $metadata_identifier): void {
    $metadata_url = Url::fromRoute('metsis_drupal.metadata_document', [
      'id' => $metadata_identifier,
    ]);

    $operations['controls']['metadata_modal'] = [
      '#type' => 'link',
      '#title' => $this->t('View metadata'),
      '#url' => $metadata_url,
      '#attributes' => [
        'class' => ['use-ajax', 'button', 'button--small', 'metsis-metadata-modal-trigger'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => Json::encode([
          'width' => 960,
          'title' => (string) $this->t('Metadata document'),
        ]),
      ],
    ];
  }

  /**
   * Build collection filter render array.
   *
   * @param array $solr_doc
   *   The Solr document.
   * @param string $dataset_identifier
   *   The dataset identifier.
   *
   * @return array
   *   Render array for collection filter.
   */
  private function buildCollectionFilter(array $solr_doc, string $dataset_identifier): array {
    $found_children = (int) ($solr_doc['found_children']['numFound'] ?? 0);
    $total_children = (int) ($solr_doc['total_children']['numFound'] ?? 0);
    $collection_button = [
      '#type' => 'button',
      '#value' => (string) $this->t('@found of @total children found', [
        '@found' => $found_children,
        '@total' => $total_children,
      ]),
      '#attributes' => [
        'class' => ['metsis-collection-button', 'button'],
        'data-collection-id' => $dataset_identifier,
        'title' => (string) $this->t('Filter on this collection'),
      ],
    ];

    return [
      '#type' => 'component',
      '#component' => 'metsis_drupal:icon_button',
      '#props' => [
        'icon_size' => 20,
      ],
      '#slots' => [
        'button' => $collection_button,
      ],
    ];
  }

  /**
   * Build export options render array.
   *
   * @param array $operations
   *   The operations array to mutate.
   * @param string $metadata_identifier
   *   The metadata identifier.
   * @param string $row_id
   *   The row ID.
   * @param string $popover_id
   *   The popover ID.
   */
  private function buildExportOptions(array &$operations, string $metadata_identifier, string $row_id, string $popover_id): void {
    $anchor_suffix = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $row_id) ?? '';
    $anchor_suffix = trim($anchor_suffix, '-_');
    if ($anchor_suffix === '') {
      $anchor_suffix = md5($metadata_identifier);
    }
    $anchor_name = '--metsis-export-trigger-' . $anchor_suffix;

    // Load export options once per render pass and cache on the instance.
    if ($this->exportOptionsCache === NULL) {
      $labels = $this->metadataExportService->getEnabledExportOptions();
      $descriptions = $this->metadataExportService->getDescriptions();
      $this->exportOptionsCache = [];
      foreach ($labels as $type_key => $label) {
        $this->exportOptionsCache[$type_key] = [
          'label' => (string) $label,
          'description' => (string) ($descriptions[$type_key] ?? ''),
        ];
      }
    }

    if ($this->exportOptionsCache === []) {
      return;
    }

    // Trigger button — uses native Popover API, no JS required.
    $operations['controls']['export_trigger'] = [
      '#type' => 'button',
      '#value' => $this->t('Export metadata &#9662;'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['metsis-export-trigger'],
        'style' => 'anchor-name: ' . $anchor_name . ';',
        'popovertarget' => $popover_id,
        'popovertargetaction' => 'toggle',
        'aria-haspopup' => 'true',
      ],
    ];

    // Popover panel with one download link per export type.
    $operations['popover'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $popover_id,
        'popover' => '',
        'class' => ['metsis-export-popover'],
        'style' => 'position-anchor: ' . $anchor_name . ';',
        'data-nosnippet' => 'true',
      ],
    ];

    foreach ($this->exportOptionsCache as $type_key => $info) {
      $download_url = Url::fromRoute(
        'metsis_drupal.metadata_export_download',
        ['id' => $metadata_identifier, 'type' => $type_key],
        ['absolute' => TRUE]
      );

      $htmx_url = Url::fromRoute(
        'metsis_drupal.metadata_export_htmx_redirect',
        ['id' => $metadata_identifier, 'type' => $type_key],
      );
      $description = trim(PlainTextOutput::renderFromHtml($info['description']));
      $link_label = $description !== ''
        ? $description
        : $info['label'];

      $export_button = [
        '#type' => 'button',
        '#value' => $link_label,
        '#attributes' => [
          'class' => ['metsis-export-option'],
          'rel' => 'nofollow noarchive noopener noreferrer',
          'referrerpolicy' => 'no-referrer',
          'data-nosnippet' => 'true',
          'title' => $this->t('Click to download @format metadata', ['@format' => $info['label']]),
          'download' => $metadata_identifier . '_' . $type_key . '.xml',
        ],
      ];
      (new Htmx())
        ->get($htmx_url)
        ->swap('none')
        ->indicator('#metsis-export-spinner')
        ->onlyMainContent()
        ->redirectHeader($download_url)
        ->applyTo($export_button);

      $operations['popover']['item_' . $type_key] = [
        '#type' => 'component',
        '#component' => 'metsis_drupal:icon_button',
        '#props' => [
          'icon_size' => 16,
          'icon_pack' => 'metsis_drupal',
          'icon_id' => 'download',
        ],
        '#slots' => [
          'button' => $export_button,
        ],
      ];
    }
  }

  /**
   * Build plot trigger render array if conditions met.
   *
   * @param array $operations
   *   The operations array to mutate.
   * @param array $solr_doc
   *   The Solr document.
   * @param string $row_id
   *   The row ID.
   */
  private function buildPlotTrigger(array &$operations, array $solr_doc, string $row_id): void {
    $opendap_url = '';
    if (!empty($solr_doc['data_access_url_opendap'])) {
      $opendap = \is_array($solr_doc['data_access_url_opendap'])
        ? reset($solr_doc['data_access_url_opendap'])
        : $solr_doc['data_access_url_opendap'];
      $opendap_url = \is_string($opendap) ? $opendap : '';
    }
    $feature_type = '';
    if (!empty($solr_doc['feature_type'])) {
      $feature = \is_array($solr_doc['feature_type'])
        ? reset($solr_doc['feature_type'])
        : $solr_doc['feature_type'];
      $feature_type = \is_string($feature) ? $feature : '';
    }

    if ($opendap_url === '' || $feature_type === '') {
      return;
    }

    $plot_trigger_id = 'metsis-plot-trigger-' . $row_id;
    $plot_container_id = 'metsis-plot-container-' . $row_id;
    $plot_spinner_id = 'metsis-plot-spinner-' . $row_id;
    $plot_target_id = 'metsis-plot-target-' . $row_id;
    $plot_url = Url::fromRoute('metsis_drupal.bokeh_plot', [], [
      'query' => [
        'url' => $opendap_url,
        'feature_type' => $feature_type,
      ],
    ]);

    $operations['controls']['plot_trigger'] = [
      '#type' => 'button',
      '#value' => $this->t('Plot @feature', ['@feature' => $feature_type]),
      '#attributes' => [
        'id' => $plot_trigger_id,
        'type' => 'button',
        'class' => ['metsis-plot-trigger', 'button--secondary'],
        'aria-controls' => $plot_container_id,
        'aria-expanded' => 'false',
        'data-label-closed' => (string) $this->t('Plot'),
        'data-label-open' => (string) $this->t('Close plot ×'),
        'data-plot-spinner' => $plot_spinner_id,
        'data-plot-target' => $plot_target_id,
      ],
    ];
    (new Htmx())
      ->get($plot_url)
      ->onlyMainContent()
      ->trigger('metsis:loadPlot')
      ->target('#' . $plot_target_id)
      ->swap('innerHTML')
      ->on('htmx:beforeRequest', 'Drupal.metsis.rowPlot.beforeRequest(this);')
      ->on('htmx:afterRequest', 'Drupal.metsis.rowPlot.afterRequest(this);')
      ->on('htmx:responseError', 'Drupal.metsis.rowPlot.onError(this);')
      ->applyTo($operations['controls']['plot_trigger']);

    $operations['plot_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $plot_container_id,
        'class' => ['metsis-plot-container'],
      ],
    ];
    $operations['plot_container']['plot_close'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => '×',
      '#attributes' => [
        'type' => 'button',
        'class' => ['metsis-plot-close'],
        'aria-label' => (string) $this->t('Close plot'),
        'data-plot-trigger' => $plot_trigger_id,
      ],
    ];

    $operations['plot_container']['spinner'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $plot_spinner_id,
        'class' => ['metsis-plot-spinner', 'hidden'],
        'aria-hidden' => 'true',
      ],
      '#allowed_tags' => ['div', 'svg'],
    ];
    $operations['plot_container']['spinner']['icon'] = [
      '#type' => 'icon',
      '#pack_id' => 'metsis_drupal_spinners',
      '#icon_id' => 'puff',
      '#settings' => [
        'stroke' => '#0074D9',
        'height' => '72',
        'width' => '72',
      ],
    ];

    $operations['plot_container']['plot_target'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $plot_target_id,
        'class' => ['metsis-plot-target'],
      ],
      '#allowed_tags' => ['div', 'script'],
    ];

    // Keep OOB behavior aligned with BokehPlotForm flow.
    (new Htmx())
      ->swapOob('innerHTML')
      ->applyTo($operations['plot_container']['plot_target']);
  }

  /**
   * {@inheritdoc}
   */
  public function optionsSummary() {
    $summary = [];
    $summary[] = $this->t('Style: @style', ['@style' => $this->options['style']]);
    $summary[] = $this->t('Operations: @val', ['@val' => $this->options['show_operations'] ? $this->t('Enabled') : $this->t('Disabled')]);
    $summary[] = $this->t('Collection/CC width: @width px', ['@width' => (int) ($this->options['component_icon_width'] ?? 88)]);
    $summary[] = $this->t('DOI identifier: @val', ['@val' => !empty($this->options['doi_show_identifier']) ? $this->t('Shown') : $this->t('Hidden')]);
    $summary[] = $this->t('DOI icon size: @size px', ['@size' => (int) ($this->options['doi_icon_size'] ?? 24)]);
    $summary[] = $this->t('DOI logo color: @val', ['@val' => !empty($this->options['doi_logo_color']) ? $this->t('Original') : $this->t('Black/white')]);
    $summary[] = $this->t('Temporal notation: @val', ['@val' => !empty($this->options['temporal_extent_short_notation']) ? $this->t('Short') : $this->t('Detailed')]);
    $summary[] = $this->t('Temporal labels: @val', ['@val' => !empty($this->options['temporal_extent_compact_labels']) ? $this->t('Inline') : $this->t('Stacked')]);
    $summary[] = $this->t('Temporal icon: @val (@id)', [
      '@val' => !empty($this->options['temporal_extent_add_icon']) ? $this->t('Shown') : $this->t('Hidden'),
      '@id' => (string) ($this->options['temporal_extent_icon_id'] ?? 'date-time'),
    ]);
    return $summary;
  }

}
