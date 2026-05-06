<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\views\filter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\search_api\Plugin\views\filter\SearchApiFilterTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\filter\Equality;

/**
 * Defines a filter for filtering on parent child relation.
 *
 * Exposed and hidden by default.
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter(id: 'metsis_parent_filter')]
class MetsisParentFilter extends Equality {
  use SearchApiFilterTrait;
  /**
   * The value.
   *
   * Contains the actual value of the field,either configured in the views ui
   * or entered in the exposed filters.
   *
   * @var mixed
   */
  public $value = NULL;

  /**
   * Contains the operator which is used on the query.
   *
   * @var string
   */
  public $operator = '=';


  /**
   * Contains the information of the selected item in a grouped filter.
   *
   * @var array
   */
  // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
  public $group_info = NULL;

  /**
   * Disable the possibility to force a single value.
   *
   * @var bool
   */
  protected $alwaysMultiple = FALSE;

  /**
   * Disable the possibility to use operators.
   *
   * @var bool
   */
  // phpcs:ignore Drupal.NamingConventions.ValidVariableName.LowerCamelName
  public $no_operator = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function defineOptions():array {
    $options = parent::defineOptions();

    $options['operator'] = ['default' => '='];
    $options['value'] = ['default' => ''];
    // Exposed by default and provide a sensible default expose config so the
    // Views options validation doesn't complain when no identifier was set yet
    // in the UI. The identifier will be editable in the Views UI.
    $options['exposed'] = ['default' => TRUE];
    // Provide a complete default expose configuration so Views does not try to
    // read missing keys such as 'operator_id' when the filter is exposed by
    // default but the user hasn't configured the expose settings yet.
    $options['expose'] = [
      'default' => [
        'identifier' => 'related_dataset',
        'label' => 'Parent/Collection',
        'description' => 'Parent/Collection filter',
        'required' => FALSE,
        'operator' => FALSE,
        'operator_id' => '',
      ],
    ];

    return $options;
  }

  /**
   * Provide simple equality operator.
   */
  public function operatorOptions(): array {
    return [
      '=' => $this->t('Is equal to'),
    ];
  }

  /**
   * Provide a simple textfield for equality.
   *
   * {@inheritdoc}
   */
  public function valueForm(&$form, FormStateInterface $form_state): void {
    $value = $this->value;
    if (is_string($value)) {
      $value = ['value' => $value];
    }
    elseif (!is_array($value)) {
      $value = ['value' => ''];
    }
    $form['value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Value'),
      '#size' => 30,
      '#default_value' => $value['value'] ?? '',
    ];

    if ($form_state->get('exposed')) {
      $identifier = $this->options['expose']['identifier'];
      $user_input = $form_state->getUserInput();
      if (!isset($user_input[$identifier])) {
        $user_input[$identifier] = $value['value'] ?? '';
        $form_state->setUserInput($user_input);
      }
    }
    $this->value = $value;
  }

  /**
   * Filter configuration admin UI summary.
   *
   * {@inheritdoc}.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The summary text.
   */
  public function adminSummary(): TranslatableMarkup {
    if ($this->isExposed()) {
      return $this->t('Exposed Parent/Child filter');
    }
    elseif (!empty($this->value)) {
      $val = is_array($this->value) ? ($this->value['value'] ?? '') : (string) $this->value;
      return $this->t('Parent/Child filter: @value', ['@value' => $val]);
    }
    return $this->t('Parent/Child filter (no value)');
  }

  /**
   * {@inheritdoc}
   */
  public function buildExposeForm(&$form, FormStateInterface $form_state): void {
    // Ensure the filter is exposed and hidden by default.
    parent::buildExposedForm($form, $form_state);

    unset($form['group_button']);
  }

  /**
   * {@inheritdoc}
   */
  public function buildExposedForm(&$form, FormStateInterface $form_state): void {
    // Ensure the filter is exposed and hidden by default.
    parent::buildExposedForm($form, $form_state);

    // Set the form element as hidden.
    if (isset($form[$this->options['expose']['identifier']])) {
      $form[$this->options['expose']['identifier']]['#type'] = 'hidden';
    }
  }

  /**
   * Validate the options form.
   *
   * {@inheritdoc}
   */
  public function validateExposeForm($form, FormStateInterface $form_state): void {
    if ($identifier = $form_state->getValue(['options', 'expose', 'identifier'])) {
      $this->validateIdentifier($identifier, $form_state, $form['expose']['identifier']);
    }
  }

  /**
   * Submit handler for the options form value element.
   *
   * {@inheritdoc}
   */
  public function valueSubmit($form, FormStateInterface $form_state) {
    $entered = $form_state->getValue(['value']);
    $this->value = ['value' => trim((string) $entered)];
  }

  /**
   * Create a query option for the parent filter.
   *
   * Added to the Solr query by the SearchApiSolr subscriber.
   *
   * {@inheritdoc}
   */
  public function query(): void {
    if (empty($this->value)) {
      return;
    }

    $val = is_array($this->value) ? ($this->value[0] ?? '') : (string) $this->value;
    if ($val !== '') {
      $field = "$this->realField";
      $parent_query = "$field:\"{$val}\"";
      $this->getQuery()->setOption('metsis_parent_filter', $parent_query);
      $this->getQuery()->setOption('metsis_parent_id', $val);
    }
  }

}
