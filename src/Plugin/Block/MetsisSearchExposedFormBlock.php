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
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
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
      'disabled_filters' => [],
      'filter_weights' => [],
      'filter_columns' => [],
      'column_count' => 3,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $filter_options = $this->getExposedFilterOptions();
    $disabled_filters = array_intersect(
      $this->configuration['disabled_filters'] ?? [],
      array_keys($filter_options),
    );

    $form['disabled_filters'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Exposed filters to disable'),
      '#description' => $this->t('Select exposed filters to omit from this block. Newly added exposed filters are enabled by default.'),
      '#options' => $filter_options,
      '#default_value' => $disabled_filters,
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
      ];
    }

    $configured_columns = $this->configuration['filter_columns'] ?? [];
    $form['filter_columns'] = [
      '#type' => 'details',
      '#title' => $this->t('Exposed filter columns'),
      '#description' => $this->t('Choose a desktop grid column for each filter. Automatic filters are balanced across the configured columns. Search and temporal filters default to Column 1, and geographic bounds default to Column 2.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    foreach ($filter_options as $filter_id => $label) {
      $column = $configured_columns[$filter_id] ?? 'auto';
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

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    parent::blockSubmit($form, $form_state);
    $disabled_filters = $form_state->getValue('disabled_filters', []);
    $this->configuration['disabled_filters'] = array_keys(array_filter($disabled_filters));
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
    unset($form['sort_by'], $form['sort_order'], $form['items_per_page']);

    $this->applyFilterWeights($form);
    if (isset($form['search-box-container']['actions']['submit'])) {
      $form['search-box-container']['actions']['submit']['#value'] = $this->t('Search');
    }
    unset($form['actions']);
    $this->applyFilterGrid($form);

    // Use the configured View page route, not the route where this block is
    // placed, as the form submission endpoint.
    $form['#action'] = $view->getUrlInfo('results')->toString();
    $form['#metsis_search_exposed_form_block'] = TRUE;
    $form['#metsis_search_view_id'] = $view->id();
    $form['#metsis_search_display_id'] = 'results';
    $form['#attributes']['class'][] = 'metsis-search-exposed-form-block';
    $form['#attached']['library'][] = 'metsis_drupal/bbox_map_filter';
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

      $configured_column = $columns[$id] ?? $this->getDefaultFilterColumn($id);
      if (in_array((string) $configured_column, ['1', '2', '3'], TRUE)) {
        $column = min((int) $configured_column, $column_count) - 1;
        $column_preference = (string) $configured_column;
      }
      else {
        $column = 0;
        foreach (array_slice($column_counts, 0, $column_count, TRUE) as $candidate => $count) {
          if ($count < $column_counts[$column]) {
            $column = $candidate;
          }
        }
        $column_preference = 'auto';
      }
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
      default => NULL,
    };
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
    $disabled_filters = array_flip($this->configuration['disabled_filters']);
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
