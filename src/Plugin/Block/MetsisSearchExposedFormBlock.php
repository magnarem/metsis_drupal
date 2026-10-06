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
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $filter_options = $this->getExposedFilterOptions();
    $disabled_filters = array_intersect(
      $this->configuration['disabled_filters'],
      array_keys($filter_options),
    );

    $form['disabled_filters'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Exposed filters to disable'),
      '#description' => $this->t('Select exposed filters to omit from this block. Newly added exposed filters are enabled by default.'),
      '#options' => $filter_options,
      '#default_value' => $disabled_filters,
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
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf($this->displaySet && $this->getView()->access('results', $account))
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
    $form = $exposed_form->renderExposedForm(TRUE);
    if (!is_array($form) || $form === []) {
      return [];
    }

    $this->removeDisabledFilters($form);

    // Use the configured View page route, not the route where this block is
    // placed, as the form submission endpoint.
    $form['#action'] = $view->getUrlInfo('results')->toString();
    $form['#metsis_search_exposed_form_block'] = TRUE;
    $form['#metsis_search_view_id'] = $view->id();
    $form['#metsis_search_display_id'] = 'results';

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
