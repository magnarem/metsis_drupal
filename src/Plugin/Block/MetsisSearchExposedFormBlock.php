<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\metsis_drupal\Plugin\views\filter\MetsisSolrBboxFilter;
use Drupal\views\ViewExecutable;
use Drupal\views\Plugin\views\exposed_form\ExposedFormPluginInterface;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\views\ViewEntityInterface;
use Drupal\views\ViewExecutableFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the METSIS search view's exposed form in a configurable block.
 */
#[Block(
  id: 'metsis_search_exposed_form',
  admin_label: new TranslatableMarkup('METSIS search exposed form'),
  category: new TranslatableMarkup('METSIS'),
)]
final class MetsisSearchExposedFormBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Configuration key for the optional secondary Search action.
   */
  private const SECONDARY_SEARCH_ID = 'secondary_search';

  /**
   * The search view executable.
   */
  protected ?ViewExecutable $view = NULL;

  /**
   * Whether the results display exists.
   */
  protected bool $displaySet = FALSE;

  /**
   * Constructs the block plugin.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ViewExecutableFactory $viewExecutableFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('views.executable'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      'shown_filters' => ['search_api_fulltext', 'temporal_extent_period_dr', 'bbox', self::SECONDARY_SEARCH_ID],
      'filter_weights' => [],
      'filter_columns' => [],
      'column_count' => 3,
      'hidden_predicates' => [],
      'bbox_map_heights' => [],
      'compact' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);
    // Numeric lists must replace defaults, not merge into them.
    $this->configuration['shown_filters'] = $configuration['shown_filters']
      ?? (array_key_exists('disabled_filters', $configuration)
        ? array_values(array_diff(array_keys($this->getExposedFilterOptions()), $configuration['disabled_filters']))
        : $this->defaultConfiguration()['shown_filters']);
    unset($this->configuration['disabled_filters']);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $filter_options = $this->getExposedFilterOptions();
    $parents = $form['#parents'] ?? ['settings'];
    $name_prefix = array_shift($parents) ?? '';
    foreach ($parents as $parent) {
      $name_prefix .= '[' . $parent . ']';
    }
    $form['shown_filters'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Elements to show'),
      '#description' => $this->t('Select the exposed filters and actions to show in this block. Newly added filters are not selected automatically.'),
      '#options' => $filter_options,
      '#default_value' => $this->getShownFilters(),
    ];

    $configured_weights = array_intersect_key(
      $this->configuration['filter_weights'] ?? [],
      $filter_options,
    );
    $form['filter_weights'] = [
      '#type' => 'details',
      '#title' => $this->t('Exposed filter order'),
      '#description' => $this->t('Lower weights appear earlier in the form. Filters with the same weight keep their default order.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    foreach (array_keys($filter_options) as $index => $filter_id) {
      $default_weight = $configured_weights[$filter_id] ?? $this->getDefaultFilterWeight($filter_id, $index);
      $form['filter_weights'][$filter_id] = [
        '#type' => 'weight',
        '#title' => $filter_options[$filter_id],
        '#default_value' => $default_weight,
        '#delta' => max(10, count($filter_options)),
        '#states' => [
          'visible' => [':input[name="' . $name_prefix . '[shown_filters][' . $filter_id . ']"]' => ['checked' => TRUE]],
        ],
      ];
    }

    $configured_columns = $this->configuration['filter_columns'] ?? [];
    $form['filter_columns'] = [
      '#type' => 'details',
      '#title' => $this->t('Exposed filter columns'),
      '#description' => $this->t('Choose a desktop grid column for each filter or action. Automatic items are balanced across the configured columns. Search and temporal filters default to Column 1, and geographic bounds and the secondary Search button default to Column 2.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    foreach ($filter_options as $filter_id => $label) {
      $column = $configured_columns[$filter_id]
        ?? $this->getDefaultFilterColumn($filter_id)
        ?? 'auto';
      $form['filter_columns'][$filter_id] = [
        '#type' => 'select',
        '#title' => $label,
        '#options' => [
          'auto' => $this->t('Automatic'),
          '1' => $this->t('Column 1'),
          '2' => $this->t('Column 2'),
          '3' => $this->t('Column 3'),
        ],
        '#default_value' => in_array((string) $column, ['1', '2', '3'], TRUE) ? (string) $column : 'auto',
        '#states' => $form['filter_weights'][$filter_id]['#states'],
      ];
    }

    $form['column_count'] = [
      '#type' => 'select',
      '#title' => $this->t('Number of grid columns'),
      '#description' => $this->t('Sets the maximum number of columns. The form stacks into one column on narrow screens.'),
      '#options' => [
        1 => $this->t('1 column'),
        2 => $this->t('2 columns'),
        3 => $this->t('3 columns'),
      ],
      '#default_value' => $this->getValidColumnCount($this->configuration['column_count'] ?? 3),
    ];

    $form['compact'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Compact presentation'),
      '#description' => $this->t('Reduce spacing and padding for quick-access search forms without removing labels or controls.'),
      '#default_value' => $this->configuration['compact'],
    ];
    $form['hidden_predicates'] = [
      '#type' => 'details',
      '#title' => $this->t('Predicate visibility'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['bbox_map_overrides'] = [
      '#type' => 'details',
      '#title' => $this->t('Bounding-box map size'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    foreach ($this->getView()->display_handler->getHandlers('filter') as $id => $handler) {
      if (!$handler instanceof FilterPluginBase || !isset($filter_options[$id]) || !$this->supportsPredicate($handler)) {
        continue;
      }
      $reason = $this->predicateUnavailableReason($handler);
      $form['hidden_predicates'][$id] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Hide @filter predicate', ['@filter' => $filter_options[$id]]),
        '#description' => $reason ?? $this->t('Use Intersects when searching from this block.'),
        '#disabled' => $reason !== NULL,
        '#default_value' => $reason === NULL && in_array($id, $this->configuration['hidden_predicates'], TRUE),
        '#states' => $form['filter_weights'][$id]['#states'],
      ];
      if (!$handler instanceof MetsisSolrBboxFilter || empty($handler->options['expose']['map_input'])) {
        continue;
      }
      $form['bbox_map_overrides'][$id] = [
        '#type' => 'container',
        '#states' => $form['filter_weights'][$id]['#states'],
        'enabled' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Override @filter map height', ['@filter' => $filter_options[$id]]),
          '#description' => $this->t('Otherwise inherit the View map height (@height px).', ['@height' => $handler->getExposedMapHeight()]),
          '#default_value' => isset($this->configuration['bbox_map_heights'][$id]),
        ],
        'height' => [
          '#type' => 'number',
          '#title' => $this->t('Map height (pixels)'),
          '#min' => MetsisSolrBboxFilter::MIN_MAP_HEIGHT,
          '#max' => MetsisSolrBboxFilter::MAX_MAP_HEIGHT,
          '#step' => 1,
          // Block validation checks bounds only when this override applies.
          '#element_validate' => [],
          '#default_value' => $this->configuration['bbox_map_heights'][$id] ?? $handler->getExposedMapHeight(),
          '#states' => [
            'visible' => [':input[name="' . $name_prefix . '[bbox_map_overrides][' . $id . '][enabled]"]' => ['checked' => TRUE]],
            'disabled' => [
              [':input[name="' . $name_prefix . '[shown_filters][' . $id . ']"]' => ['checked' => FALSE]],
              'or',
              [':input[name="' . $name_prefix . '[bbox_map_overrides][' . $id . '][enabled]"]' => ['checked' => FALSE]],
            ],
          ],
        ],
      ];
    }
    $form['hidden_predicates']['#access'] = Element::children($form['hidden_predicates']) !== [];
    $form['bbox_map_overrides']['#access'] = Element::children($form['bbox_map_overrides']) !== [];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockValidate($form, FormStateInterface $form_state): void {
    parent::blockValidate($form, $form_state);
    $shown = array_keys(array_filter($form_state->getValue('shown_filters', [])));
    foreach ($this->getView()->display_handler->getHandlers('filter') as $id => $handler) {
      if (!$handler instanceof FilterPluginBase || !in_array($id, $shown, TRUE) || !$handler->isExposed()) {
        continue;
      }
      if ($form_state->getValue(['hidden_predicates', $id]) && $this->supportsPredicate($handler)) {
        $reason = $this->predicateUnavailableReason($handler);
        if ($reason !== NULL) {
          $form_state->setError($form['hidden_predicates'][$id], $reason);
        }
      }
      if ($handler instanceof MetsisSolrBboxFilter
        && !empty($handler->options['expose']['map_input'])
        && $form_state->getValue(['bbox_map_overrides', $id, 'enabled'])) {
        $height = $form_state->getValue(['bbox_map_overrides', $id, 'height']);
        if (filter_var($height, FILTER_VALIDATE_INT) === FALSE
          || (int) $height < MetsisSolrBboxFilter::MIN_MAP_HEIGHT
          || (int) $height > MetsisSolrBboxFilter::MAX_MAP_HEIGHT) {
          $form_state->setError($form['bbox_map_overrides'][$id]['height'], $this->t('Map height must be a whole number between 150 and 1000 pixels.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    parent::blockSubmit($form, $form_state);
    $this->configuration['shown_filters'] = array_values(array_intersect(
      array_keys(array_filter($form_state->getValue('shown_filters', []))),
      array_keys($this->getExposedFilterOptions()),
    ));
    $filter_weights = $form_state->getValue('filter_weights', []);
    $this->configuration['filter_weights'] = array_map('intval', $filter_weights);
    $filter_columns = $form_state->getValue('filter_columns', []);
    $this->configuration['filter_columns'] = array_filter(
      $filter_columns,
      static fn ($column): bool => in_array((string) $column, ['1', '2', '3'], TRUE),
    );
    $this->configuration['column_count'] = $this->getValidColumnCount(
      $form_state->getValue('column_count', 3),
    );
    $this->configuration['compact'] = (bool) $form_state->getValue('compact', FALSE);
    $this->configuration['hidden_predicates'] = [];
    $this->configuration['bbox_map_heights'] = [];
    foreach ($this->getView()->display_handler->getHandlers('filter') as $id => $handler) {
      if (!$handler instanceof FilterPluginBase || !in_array($id, $this->configuration['shown_filters'], TRUE) || !$handler->isExposed()) {
        continue;
      }
      if ($this->supportsPredicate($handler)
        && $this->predicateUnavailableReason($handler) === NULL
        && $form_state->getValue(['hidden_predicates', $id])) {
        $this->configuration['hidden_predicates'][] = $id;
      }
      if ($handler instanceof MetsisSolrBboxFilter
        && !empty($handler->options['expose']['map_input'])
        && $form_state->getValue(['bbox_map_overrides', $id, 'enabled'])) {
        $height = $form_state->getValue(['bbox_map_overrides', $id, 'height']);
        $this->configuration['bbox_map_heights'][$id] = (int) $height;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    $view = $this->getView();

    return AccessResult::allowedIf($this->displaySet && $view->access('results', $account))
      ->addCacheContexts(['user.permissions']);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $view = $this->getView();
    if (!$this->displaySet) {
      return [];
    }

    $view->initHandlers();
    $exposed_form = $view->display_handler->getPlugin('exposed_form');
    if (!$exposed_form instanceof ExposedFormPluginInterface) {
      return [];
    }
    $has_exposed_facets = FALSE;
    foreach ($view->filter as $handler) {
      if ($handler instanceof FilterPluginBase
        && $handler->isExposed()
        && $handler->getPluginId() === 'facets_filter') {
        $has_exposed_facets = TRUE;
        break;
      }
    }

    if ($has_exposed_facets) {
      // Facets Exposed Filters populates its options after the View query runs.
      $view->execute('results');
      $form = $view->exposed_widgets;
    }
    else {
      $form = $exposed_form->renderExposedForm(TRUE);
    }

    if (!is_array($form) || $form === []) {
      return [];
    }

    $this->removeDisabledFilters($form);
    $this->applyFilterPresentation($form);
    unset($form['sort_by'], $form['sort_order'], $form['items_per_page']);

    unset($form['actions']);
    if (isset($form['search-box-container']['actions']['submit'])) {
      $form['search-box-container']['actions']['submit']['#value'] = $this->t('Search');
    }

    // Keep BEF autosubmit enabled on the View page, but not in this block.
    unset(
      $form['#attributes']['data-bef-auto-submit'],
      $form['#attributes']['data-bef-auto-submit-delay'],
      $form['#attributes']['data-bef-auto-submit-minimum-length'],
      $form['#attributes']['data-bef-auto-submit-full-form'],
    );
    if (in_array(self::SECONDARY_SEARCH_ID, $this->getShownFilters(), TRUE)) {
      $form['actions'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['form-actions', 'metsis-search-exposed-form-block__actions'],
        ],
        '#weight' => 100,
        'submit_filters' => [
          '#type' => 'component',
          '#component' => 'metsis_drupal:icon_button',
          '#props' => [
            'icon_size' => 18,
            'icon_pack' => 'metsis_drupal',
            'icon_id' => 'magnifier',
          ],
          '#slots' => [
            'button' => [
              '#type' => 'submit',
              '#value' => $this->t('Search'),
              '#attributes' => [
                'class' => ['metsis-search-secondary-submit'],
              ],
            ],
          ],
        ],
      ];
    }

    $this->applyFilterWeights($form);
    $this->applyFilterGrid($form);

    // Use the configured View page route, not the route where this block is
    // placed, as the form submission endpoint.
    $form['#action'] = $view->getUrlInfo('results')->toString();
    $form['#metsis_search_exposed_form_block'] = TRUE;
    $form['#metsis_search_view_id'] = $view->id();
    $form['#metsis_search_display_id'] = 'results';
    $form['#attributes']['class'][] = 'metsis-search-exposed-form-block';
    if ($this->configuration['compact']) {
      $form['#attributes']['class'][] = 'metsis-search-exposed-form-block--compact';
    }
    $form['#attached']['library'][] = 'metsis_drupal/metsis_icon_sync';
    $form['#attached']['library'][] = 'metsis_drupal/metsis_search_exposed_form_block';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(
      parent::getCacheContexts(),
      $this->getView()->display_handler->getCacheMetadata()->getCacheContexts(),
      ['url.query_args'],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(
      parent::getCacheTags(),
      $this->getView()->storage->getCacheTags(),
      $this->getView()->display_handler->getCacheMetadata()->getCacheTags(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return Cache::mergeMaxAges(
      parent::getCacheMaxAge(),
      $this->getView()->display_handler->getCacheMetadata()->getCacheMaxAge(),
    );
  }

  /**
   * Returns the configured exposed filters on the results display.
   *
   * @return array<string, string|\Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Filter plugin IDs keyed by their administrative labels.
   */
  private function getExposedFilterOptions(): array {
    $view = $this->getView();
    if (!$this->displaySet) {
      return [];
    }

    $view->initHandlers();
    $options = [];
    foreach ($view->display_handler->getHandlers('filter') as $id => $handler) {
      if (!$handler instanceof FilterPluginBase) {
        continue;
      }
      if (!$handler->canExpose() || !$handler->isExposed()) {
        continue;
      }

      $label = $handler->options['expose']['label'] ?? '';
      $options[$id] = $label ?: $handler->adminLabel() ?: $id;
    }

    $options[self::SECONDARY_SEARCH_ID] = new TranslatableMarkup('Secondary Search button');

    return $options;
  }

  /**
   * Returns the default ordering weight for an exposed filter.
   */
  private function getDefaultFilterWeight(string $filter_id, int $index): int {
    return match ($filter_id) {
      'search_api_fulltext' => -10,
      'temporal_extent_period_dr' => -1,
      'bbox' => 0,
      self::SECONDARY_SEARCH_ID => 100,
      default => $index,
    };
  }

  /**
   * Applies configured weights to the exposed widgets in the form.
   */
  private function applyFilterWeights(array &$form): void {
    $weights = $this->configuration['filter_weights'] ?? [];
    $index = 0;
    foreach ($this->getView()->display_handler->getHandlers('filter') as $id => $handler) {
      if (!$handler instanceof FilterPluginBase || !$handler->canExpose() || !$handler->isExposed()) {
        continue;
      }

      $weight = $weights[$id] ?? $this->getDefaultFilterWeight($id, $index);
      $index++;
      $element_name = $this->getFilterElementName($form, $handler);
      if ($element_name !== NULL) {
        $form[$element_name]['#weight'] = (int) $weight;
      }
    }

    if (isset($form['actions']) && is_array($form['actions'])) {
      $form['actions']['#weight'] = (int) ($weights[self::SECONDARY_SEARCH_ID]
        ?? $this->getDefaultFilterWeight(self::SECONDARY_SEARCH_ID, $index));
    }
  }

  /**
   * Groups exposed filter widgets into independent, vertically stacked columns.
   */
  private function applyFilterGrid(array &$form): void {
    $columns = $this->configuration['filter_columns'] ?? [];
    $column_count = $this->getValidColumnCount($this->configuration['column_count'] ?? 3);
    $column_elements = [[], [], []];
    $column_counts = [0, 0, 0];

    foreach ($this->getView()->display_handler->getHandlers('filter') as $id => $handler) {
      if (!$handler instanceof FilterPluginBase || !$handler->canExpose() || !$handler->isExposed()) {
        continue;
      }

      $element_name = $this->getFilterElementName($form, $handler);
      if ($element_name === NULL) {
        continue;
      }

      [$column, $column_preference] = $this->resolveFilterColumn(
        $columns[$id] ?? $this->getDefaultFilterColumn($id),
        $column_count,
        $column_counts,
      );
      $element_names = [$element_name];
      $operator_name = $handler->exposedInfo()['operator'] ?? NULL;
      if (is_string($operator_name)
        && $operator_name !== ''
        && $operator_name !== $element_name
        && isset($form[$operator_name])
        && is_array($form[$operator_name])) {
        $form[$operator_name]['#weight'] = $form[$element_name]['#weight'] ?? 0;
        $element_names[] = $operator_name;
      }
      foreach ($element_names as $name) {
        $form[$name]['#attributes']['class'][] = 'metsis-search-filter-grid__item';
        $form[$name]['#attributes']['data-metsis-filter-id'] = $id;
        $form[$name]['#attributes']['data-metsis-filter-column'] = $column_preference;
        $form[$name]['#attributes']['data-metsis-filter-weight'] = (string) ($form[$element_name]['#weight'] ?? 0);
        $column_elements[$column][$name] = $form[$name];
        unset($form[$name]);
      }
      $column_counts[$column]++;
    }

    if (isset($form['actions']) && is_array($form['actions'])) {
      [$column, $column_preference] = $this->resolveFilterColumn(
        $columns[self::SECONDARY_SEARCH_ID] ?? $this->getDefaultFilterColumn(self::SECONDARY_SEARCH_ID),
        $column_count,
        $column_counts,
      );
      $form['actions']['#attributes']['class'][] = 'metsis-search-filter-grid__item';
      $form['actions']['#attributes']['data-metsis-filter-id'] = self::SECONDARY_SEARCH_ID;
      $form['actions']['#attributes']['data-metsis-filter-column'] = $column_preference;
      $form['actions']['#attributes']['data-metsis-filter-weight'] = (string) ($form['actions']['#weight'] ?? 0);
      $column_elements[$column]['actions'] = $form['actions'];
      unset($form['actions']);
      $column_counts[$column]++;
    }

    $grid = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['metsis-search-filter-grid'],
        'data-metsis-column-count' => (string) $column_count,
      ],
      '#weight' => -50,
    ];
    foreach ($column_elements as $index => $elements) {
      if ($elements === []) {
        continue;
      }
      $column = $index + 1;
      $grid['column_' . $column] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'metsis-search-filter-grid__column',
            'metsis-search-filter-grid__column--' . $column,
          ],
        ],
      ] + $elements;
    }
    $form['metsis-search-filter-grid'] = $grid;
  }

  /**
   * Returns the preferred column for the primary search filters.
   */
  private function getDefaultFilterColumn(string $filter_id): ?int {
    return match ($filter_id) {
      'search_api_fulltext', 'temporal_extent_period_dr' => 1,
      'bbox' => 2,
      self::SECONDARY_SEARCH_ID => 2,
      default => NULL,
    };
  }

  /**
   * Resolves an exposed element's configured or automatic grid column.
   *
   * @param mixed $configured_column
   *   The configured column or automatic preference.
   * @param int $column_count
   *   The number of available columns.
   * @param array<int, int> $column_counts
   *   The number of assigned items in each column.
   *
   * @return array{int, string}
   *   The zero-based column index and configured preference.
   */
  private function resolveFilterColumn(mixed $configured_column, int $column_count, array $column_counts): array {
    if (in_array((string) $configured_column, ['1', '2', '3'], TRUE)) {
      return [
        min((int) $configured_column, $column_count) - 1,
        (string) $configured_column,
      ];
    }

    $column = 0;
    foreach (array_slice($column_counts, 0, $column_count, TRUE) as $candidate => $count) {
      if ($count < $column_counts[$column]) {
        $column = $candidate;
      }
    }

    return [$column, 'auto'];
  }

  /**
   * Returns a supported grid column count.
   */
  private function getValidColumnCount(mixed $column_count): int {
    return in_array((string) $column_count, ['1', '2', '3'], TRUE)
      ? (int) $column_count
      : 3;
  }

  /**
   * Finds the top-level form element associated with an exposed filter.
   */
  private function getFilterElementName(array $form, FilterPluginBase $handler): ?string {
    $info = $handler->exposedInfo();
    $value_name = $info['value'] ?? NULL;
    if (!is_string($value_name) || $value_name === '') {
      return NULL;
    }

    $candidates = [];
    if ($value_name === 'text') {
      $candidates[] = 'search-box-container';
    }
    $candidates[] = $value_name . '_wrapper';
    $candidates[] = $value_name;

    foreach ($candidates as $candidate) {
      if (isset($form[$candidate]) && is_array($form[$candidate])) {
        return $candidate;
      }
    }

    return NULL;
  }

  /**
   * Removes widgets belonging to block-disabled filters from the form.
   */
  private function removeDisabledFilters(array &$form): void {
    $disabled_filters = array_flip(array_diff(array_keys($this->getExposedFilterOptions()), $this->getShownFilters()));
    if ($disabled_filters === []) {
      return;
    }

    foreach ($this->getView()->display_handler->getHandlers('filter') as $id => $handler) {
      if (!$handler instanceof FilterPluginBase) {
        continue;
      }
      if (!isset($disabled_filters[$id]) || !$handler->isExposed()) {
        continue;
      }

      $element_name = $this->getFilterElementName($form, $handler);
      if ($element_name !== NULL) {
        unset($form[$element_name]);
      }

      $info = $handler->exposedInfo();
      if (is_array($info)) {
        foreach (['value', 'operator'] as $key) {
          if (isset($info[$key])) {
            unset($form[$info[$key]]);
          }
        }
      }
      $info_id = $handler->isAGroup()
        ? $handler->options['group_info']['identifier']
        : $id;
      unset($form['#info']['filter-' . $info_id]);
    }
  }

  /**
   * Returns selected IDs that still exist in the results display.
   */
  private function getShownFilters(): array {
    return array_values(array_intersect($this->configuration['shown_filters'], array_keys($this->getExposedFilterOptions())));
  }

  /**
   * Whether the filter has a METSIS spatial or temporal predicate.
   */
  private function supportsPredicate(FilterPluginBase $handler): bool {
    return in_array($handler->getPluginId(), ['metsis_filter_bbox', 'metsis_filter_date_range'], TRUE);
  }

  /**
   * Explains why a predicate cannot be hidden.
   */
  private function predicateUnavailableReason(FilterPluginBase $handler): ?TranslatableMarkup {
    if ($handler->isAGroup()) {
      return $this->t('Grouped filters do not expose an independent predicate.');
    }
    $expose = $handler->options['expose'];
    if (empty($expose['use_operator']) || empty($expose['operator_id'])) {
      return $this->t('The View does not expose this predicate. Its configured fixed operator is used.');
    }
    if (!empty($expose['operator_limit_selection'])
      && !empty($expose['operator_list'])
      && empty($expose['operator_list']['intersects'])) {
      return $this->t('The View excludes Intersects from the allowed predicates. Enable Intersects in the View before hiding this predicate.');
    }
    return NULL;
  }

  /**
   * Applies block-only predicate and map presentation to retained wrappers.
   */
  private function applyFilterPresentation(array &$form): void {
    foreach ($this->getView()->display_handler->getHandlers('filter') as $id => $handler) {
      if (!$handler instanceof FilterPluginBase || !$this->supportsPredicate($handler)) {
        continue;
      }
      $name = $this->getFilterElementName($form, $handler);
      if ($name === NULL) {
        continue;
      }
      $operator = $handler->options['expose']['operator_id'] ?? '';
      if (in_array($id, $this->configuration['hidden_predicates'], TRUE)
        && $this->predicateUnavailableReason($handler) === NULL) {
        if (!$this->hidePredicate($form[$name], $operator) && isset($form[$operator])) {
          $this->hidePredicate($form, $operator);
        }
      }
      if ($handler instanceof MetsisSolrBboxFilter && isset($this->configuration['bbox_map_heights'][$id])) {
        $handler->setExposedMapHeight($form[$name], $this->configuration['bbox_map_heights'][$id]);
      }
    }
  }

  /**
   * Replaces a predicate even when BEF adds another nested wrapper.
   */
  private function hidePredicate(array &$element, string $operator): bool {
    foreach ($element as $key => &$child) {
      if (!is_array($child) || str_starts_with((string) $key, '#')) {
        continue;
      }
      if ($key === $operator) {
        $child = [
          '#type' => 'hidden',
          '#name' => $operator,
          '#value' => 'intersects',
        ];
        return TRUE;
      }
      if ($this->hidePredicate($child, $operator)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns the configured search view executable.
   */
  private function getView(): ViewExecutable {
    if ($this->view === NULL) {
      $view_entity = $this->entityTypeManager->getStorage('view')->load('metsis_search');
      if (!$view_entity instanceof ViewEntityInterface) {
        throw new \LogicException('The metsis_search view is required by the search exposed form block.');
      }
      $this->view = $this->viewExecutableFactory->get($view_entity);
      $this->displaySet = $this->view->setDisplay('results');
    }

    return $this->view;
  }

}
