<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\views\row;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\search_api\Plugin\views\row\SearchApiRow;
use Drupal\Core\Form\FormStateInterface;
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
   * Metsis search helper service.
   *
   * @var \Drupal\metsis_drupal\Service\ResultRowRenderer
   */
  protected ResultRowRenderer $rowRenderer;

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
   *   The MetsismetsisHelper service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ResultRowRenderer $metsis_row_renderer,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->rowRenderer = $metsis_row_renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('metsis_drupal.result_row_renderer')
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

}
