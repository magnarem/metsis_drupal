<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\views\row;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Htmx\Htmx;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
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

    // Get the raw solr document results.
    $solr_doc = $row->_item->getExtraData('search_api_solr_document')->getFields();

    // Get the highlighted solr fields if available.
    $highlighted_fields = $row->_item->getExtraData('highlighted_fields') ?? [];

    // Build the generated excerpt as a safe render array if available.
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

    // Generate the rendered fields array.
    $fields = $this->rowRenderer->renderRow($solr_doc, $this->options, $highlighted_fields);

    // Build operations block. Collection filter is prepared here so operation
    // rendering logic stays centralized in the row plugin.
    $operations = [];
    if (!empty($this->options['show_operations'])) {

      $metadata_identifier = (string) ($solr_doc['id'] ?? '');
      $dataset_identifier = (string) ($fields['metadata_identifier'] ?? '');
      $row_id = (string) ($fields['id'] ?? '');
      $popover_id = 'metsis-export-popover-' . $row_id;

      $is_parent = !empty($solr_doc['isParent']) && $solr_doc['isParent'] == TRUE;
      $operations = [
        '#type' => 'container',
        '#attributes' => ['class' => ['metsis-row-operations']],
        'controls' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['metsis-row-operations-controls']],
        ],
      ];
      if ($is_parent && $dataset_identifier !== '') {

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

        $operations['controls']['collection_filter'] = [
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

      if ($metadata_identifier !== '' && $row_id !== '') {
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

        if ($this->exportOptionsCache !== []) {
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
              // Content-Disposition: attachment from the controller will
              // trigger the download; the download attribute provides the
              // suggested filename as a fallback hint.
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
      }
      // Optional plot operation via HTMX when both values are present.
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

      if ($opendap_url !== '' && $feature_type !== '') {
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
          ->on('htmx:BeforeRequest', 'Drupal.metsis.rowPlot.beforeRequest(this);')
          ->on('htmx:AfterSettle', 'Drupal.metsis.rowPlot.afterSettle(this);')
          ->on('htmx:ResponseError', 'Drupal.metsis.rowPlot.onError(this);')
          ->applyTo($operations['controls']['plot_trigger']);

        $operations['plot_container'] = [
          '#type' => 'container',
          '#attributes' => [
            'id' => $plot_container_id,
            'class' => ['metsis-plot-container'],
          ],
        ];

        $operations['plot_container']['spinner'] = [
          '#type' => 'container',
          '#attributes' => [
            'id' => $plot_spinner_id,
            'class' => ['metsis-plot-spinner', 'hidden'],
            'aria-hidden' => 'true',
          ],
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
          '#allowed_tags' => ['div', 'script', 'svg'],
        ];

        $operations['plot_container']['plot_close'] = [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => '×',
          '#attributes' => [
            'type' => 'button',
            'class' => ['metsis-plot-close', 'hidden'],
            'aria-label' => (string) $this->t('Close plot'),
            'data-plot-trigger' => $plot_trigger_id,
          ],
        ];
        // Keep OOB behavior aligned with BokehPlotForm flow.
        (new Htmx())
          ->swapOob('outerHTML')
          ->applyTo($operations['plot_container']['plot_target']);
      }
    }

    // Build render array for row style.
    $build = [
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
    return $build;

  }

  /**
   * {@inheritdoc}
   */
  public function optionsSummary() {
    $summary = [];
    $summary[] = $this->t('Style: @style', ['@style' => $this->options['style']]);
    $summary[] = $this->t('Operations: @val', ['@val' => $this->options['show_operations'] ? $this->t('Enabled') : $this->t('Disabled')]);
    return $summary;
  }

}
