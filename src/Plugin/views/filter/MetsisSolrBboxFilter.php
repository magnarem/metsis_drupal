<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\views\filter;

use Drupal\views\Attribute\ViewsFilter;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\search_api\Plugin\views\filter\SearchApiFilterTrait;

/**
 * Defines a filter for filtering on dates.
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter(id: 'metsis_filter_bbox')]
class MetsisSolrBboxFilter extends FilterPluginBase implements ContainerFactoryPluginInterface {
  use SearchApiFilterTrait;

  /**
   * Disable the possibility to force a single value.
   *
   * @var bool
   */
  protected $alwaysMultiple = TRUE;

  /**
   * Filter configuration admin UI summary.
   *
   * {@inheritdoc},
   */
  public function adminSummary() {
    if ($this->isExposed() && $this->options['expose']['map_input'] && $this->options['expose']['user_input']) {
      return $this->t('Exposed BBox filter Map & User input');
    }
    if ($this->options['expose']['map_input'] && $this->isExposed()) {
      return $this->t('Map input BBox filter exposed');
    }
    elseif ($this->isExposed()) {
      return $this->t('Exposed BBox filter');
    }
    elseif (!empty($this->value)) {
      return $this->t('@operator: @coordinates',
      [
        '@coordinates' => implode(', ', $this->value),
        '@operator' => $this->operators()[$this->operator]['short'],
      ]);
    }
    else {
      return $this->t('No coordinates');
    }
  }

  /**
   * Define bbox filter options.
   *
   * {@inheritDoc}
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();

    // Set default operator.
    $options['operator'] = ['default' => 'intersects'];
    // Allow map input instead of manual coordinate input.
    $options['expose']['contains']['map_input'] = ['default' => FALSE];
    $options['expose']['contains']['user_input'] = ['default' => FALSE];
    $options['expose']['contains']['user_input_collapsed'] = ['default' => FALSE];

    return $options;
  }

  /**
   * Define available operators.
   *
   * {@inheritdoc}
   */
  public function operators(): array {
    $operators = [
      "contains" => [
        'title' => $this->t('Contains'),
        'method' => 'opBbox',
        'short' => 'Contains',
        'values' => 4,
      ],
      "within" => [
        'title' => $this->t('Within'),
        'method' => 'opBbox',
        'short' => 'Within',
        'values' => 4,
      ],
      "intersects" => [
        'title' => $this->t('Intersects'),
        'method' => 'opBbox',
        'short' => 'Intersects',
        'values' => 4,
      ],
      "equals" => [
        'title' => $this->t('Equals'),
        'method' => 'opBbox',
        'short' => 'Equals',
        'values' => 4,
      ],
      "disjoint" => [
        'title' => $this->t('Disjoint'),
        'method' => 'opBbox',
        'short' => 'Disjoint',
        'values' => 4,
      ],
    ];

    return $operators;
  }

  /**
   * {@inheritdoc}
   */
  public function buildExposedForm(&$form, FormStateInterface $form_state) {
    parent::buildExposedForm($form, $form_state);

    $identifier = $this->options['expose']['identifier'];
    $wrapper = $identifier . '_wrapper';
    if (empty($form[$wrapper][$identifier])) {
      return;
    }
    $form[$wrapper]['#tree'] = FALSE;
    $form[$wrapper][$identifier]['#tree'] = TRUE;
    $form[$wrapper]['#attributes']['class'][] = "bbox-exposed-filter-wrapper";

    if (
    !$this->options['expose']['map_input']
    || empty($this->options['expose']['map_input'])
    ) {
      return;
    }

    // Check if the map_input option is enabled.
    if (!empty($this->options['expose']['map_input'])) {
      $form[$wrapper]['bbox_map_filter'] = [
        '#type' => 'container',
        '#title' => $this->t('Select bounding box on map'),
        '#tree' => FALSE,
        '#attributes' => [
          'id' => 'bbox-map-filter-container',
          'class' => ['bbox-map-filter-container'],
        ],
      ];
      $form[$wrapper]['bbox_map_filter']['coords'] = [
        '#type' => 'markup',
        '#markup' => '<div id="coords">
          <span class="coords">Create filter...</span>
          <i class="bbox-remove fa fa-remove"></i>
          </div>',
      ];

      if (empty($this->options['expose']['user_input'])) {
        // Hide the exposed form inputs by rendering them as hidden fields.
        $form[$wrapper][$identifier]['minX']['#type'] = 'hidden';
        $form[$wrapper][$identifier]['maxX']['#type'] = 'hidden';
        $form[$wrapper][$identifier]['maxY']['#type'] = 'hidden';
        $form[$wrapper][$identifier]['minY']['#type'] = 'hidden';
      }

      // If both map and user input are enabled,
      // group them into horizontal tabs.
      // if (!empty($this->options['expose']['user_input'])) {
      //   $form[$wrapper]['horizontal_tabs'] = [
      //     '#type' => 'horizontal_tabs',
      //     '#title' => $this->t('Geographic filter options'),
      //     '#title_display' => 'invisible',
      //     '#weight' => -10,
      //   ];.
      // $form[$wrapper]['bbox_map_filter_tab'] = [
      //     '#type' => 'details',
      //     '#title' => $this->t('Map'),
      //     '#group' => 'horizontal_tabs',
      //     '#open' => TRUE,
      //   ];
      // if (isset($form[$wrapper]['bbox_map_filter'])) {
      //     $form[$wrapper]['bbox_map_filter_tab']['bbox_map_filter'] = $form[$wrapper]['bbox_map_filter'];
      //     unset($form[$wrapper]['bbox_map_filter']);
      //   }.
      // $form[$wrapper]['bbox_coordinates_tab'] = [
      //     '#type' => 'details',
      //     '#title' => $this->t('Coordinates'),
      //     '#group' => 'horizontal_tabs',
      //     '#open' => empty($this->options['expose']['user_input_collapsed']),
      //   ];
      // if (isset($form[$wrapper][$identifier])) {
      //     $form[$wrapper]['bbox_coordinates_tab'][$identifier] = $form[$wrapper][$identifier];
      //     unset($form[$wrapper][$identifier]);
      //   }
      // }.
      $form = BubbleableMetadata::mergeAttachments($form, [
        '#attached' => [
          'library' => [
            'metsis_drupal/bbox_map_filter',
          ],
        ],
      ]);
    }
  }

  /**
   * Add map input option to exposed UI form.
   *
   * {@inheritdoc}
   */
  public function buildExposeForm(&$form, FormStateInterface $form_state) {
    parent::buildExposeForm($form, $form_state);
    $form['expose']['#type'] = 'container';
    $form['expose']['map_input'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use map for input'),
      '#description' => $this->t('Enable this option to use a map for bounding box input. This will hide the exposed form inputs.'),
      '#default_value' => $this->options['expose']['map_input'],
    ];

    // Add option to also show user input fields. If the above is checked.
    $form['expose']['user_input'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also show user input fields'),
      '#description' => $this->t('Enable this option to show the exposed form inputs along with the map.'),
      '#default_value' => $this->options['expose']['user_input'],
      "#states" => [
        'visible' => [
          ':input[name="options[expose][map_input]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $form['expose']['user_input_collapsed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Collapse user input fields by default'),
      '#description' => $this->t('Enable this option to have the user input fields collapsed by default when both map and user input fields are shown.'),
      '#default_value' => $this->options['expose']['user_input_collapsed'],
      "#states" => [
        'visible' => [
          ':input[name="options[expose][user_input]"]' => ['checked' => TRUE],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state): void {
    parent::valueForm($form, $form_state);

    $form['value']['#type'] = 'container';
    $form['value']['#tree'] = TRUE;

    $value_element = &$form['value'];

    // Add the Latitude and Longitude elements.
    $value_element += [
      'minX' => [
        '#type' => 'textfield',
        '#title' => $this->t('Minimum Longitude (X)'),
        '#default_value' => !empty($this->value['minX']) ? $this->value['minX'] : '',
        '#size' => 10,

      ],
      'maxX' => [
        '#type' => 'textfield',
        '#title' => $this->t('Maximum Longitude (X)'),
        '#default_value' => !empty($this->value['maxX']) ? $this->value['maxX'] : '',
        '#size' => 10,

      ],
      'minY' => [
        '#type' => 'textfield',
        '#title' => $this->t('Minimum Latitude (Y)'),
        '#default_value' => !empty($this->value['minY']) ? $this->value['minY'] : '',
        '#size' => 10,
      ],
      'maxY' => [
        '#type' => 'textfield',
        '#title' => $this->t('Maximum Latitude (Y)'),
        '#default_value' => !empty($this->value['maxY']) ? $this->value['maxY'] : '',
        '#size' => 10,
      ],

    ];
  }

  /**
   * Provide a list of all the numeric operators.
   *
   * {@inheritdoc}
   */
  public function operatorOptions($which = 'title') {
    $options = [];
    foreach ($this->operators() as $id => $info) {
      $options[$id] = $info[$which];
    }

    return $options;
  }

  /**
   * Create a solr BBox query string using the given operator.
   *
   * Store as an option in the query object.
   * Added to solr query in event subscriber.
   *
   * {@inheritdoc}
   */
  public function query() {
    if (empty($this->value)) {
      return;
    }

    // Get the field values.
    $maxX = $this->value['maxX'];
    $minX = $this->value['minX'];
    $maxY = $this->value['maxY'];
    $minY = $this->value['minY'];
    // Validate values.
    if (
    !is_numeric($maxX)
    || !is_numeric($minX)
    || !is_numeric($maxY)
    || !is_numeric($minY)
    ) {
      return;
    }
    // Build the Solr BBox query.
    $field = "$this->realField";
    $operator = ucfirst($this->operator);
    $bbox = "ENVELOPE({$minX}, {$maxX}, {$maxY}, {$minY})";
    $bbox_solr_query = "{!field f={$field} v='{$operator}({$bbox})'}";

    // The SearchApiSolr subscriber will add the filter to the solr query.
    $this->getQuery()->setOption('metsis_bbox_filter', $bbox_solr_query);
  }

  /**
   * {@inheritdoc}
   */
  public function acceptExposedInput($input) {
    $identifier = $this->options['expose']['identifier'];
    if (empty($this->options['exposed'])) {
      return TRUE;
    }

    $rc = parent::acceptExposedInput($input);
    if (!$rc) {
      return FALSE;
    }

    // No exposed bbox input has been submitted yet.
    if (!is_array($input) || !array_key_exists($identifier, $input) || !is_array($input[$identifier])) {
      return TRUE;
    }

    $coordinates = ['minX', 'maxX', 'minY', 'maxY'];
    $has_any_value = FALSE;

    // Accept empty input on initial load; only validate once any value is set.
    foreach ($coordinates as $coordinate) {
      $value = trim((string) ($input[$identifier][$coordinate] ?? ''));
      if ($value !== '') {
        $has_any_value = TRUE;
        break;
      }
    }

    if (!$has_any_value) {
      return TRUE;
    }

    foreach ($coordinates as $coordinate) {
      $value = trim((string) ($input[$identifier][$coordinate] ?? ''));
      if ($value === '' || !is_numeric($value)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function validateExposed(&$form, FormStateInterface $form_state) {
    $identifier = $this->options['expose']['identifier'];
    if (empty($this->options['exposed'])) {
      return;
    }

    $coordinates = ['minX', 'maxX', 'minY', 'maxY'];
    $values = $form_state->getValue($identifier) ?? [];

    if (!is_array($values)) {
      return;
    }

    $has_any_value = FALSE;
    foreach ($coordinates as $coordinate) {
      $value = trim((string) ($values[$coordinate] ?? ''));
      if ($value !== '') {
        $has_any_value = TRUE;
        break;
      }
    }

    // Do not validate on initial page load when bbox fields are empty.
    if (!$has_any_value) {
      return;
    }

    $has_missing_values = FALSE;

    // Check that all coordinates are filled.
    foreach ($coordinates as $coordinate) {
      $value = trim((string) ($values[$coordinate] ?? ''));
      if (empty($value)) {
        $has_missing_values = TRUE;
        $form_state->setErrorByName("{$identifier}][{$coordinate}",
          $this->t('The @coordinate coordinate is required.',
            ['@coordinate' => $coordinate]));
      }
    }

    // If there are missing values, don't validate further.
    if ($has_missing_values) {
      return;
    }

    // Validate numeric values and ranges.
    // Validate minY: must be numeric and between -90 and 90.
    $minY = $values['minY'] ?? NULL;
    if ($minY !== NULL && $minY !== '') {
      if (!is_numeric($minY)) {
        $form_state->setErrorByName("{$identifier}][minY",
          $this->t('The minY coordinate must be a number.'));
      }
      elseif ((float) $minY < -90 || (float) $minY > 90) {
        $form_state->setErrorByName("{$identifier}][minY",
          $this->t('The minY coordinate must be between -90 and 90.'));
      }
    }

    // Validate maxY: must be numeric and between -90 and 90.
    $maxY = $values['maxY'] ?? NULL;
    if ($maxY !== NULL && $maxY !== '') {
      if (!is_numeric($maxY)) {
        $form_state->setErrorByName("{$identifier}][maxY",
          $this->t('The maxY coordinate must be a number.'));
      }
      elseif ((float) $maxY < -90 || (float) $maxY > 90) {
        $form_state->setErrorByName("{$identifier}][maxY",
          $this->t('The maxY coordinate must be between -90 and 90.'));
      }
    }

    // Validate minX: must be numeric and between -180 and 180.
    $minX = $values['minX'] ?? NULL;
    if ($minX !== NULL && $minX !== '') {
      if (!is_numeric($minX)) {
        $form_state->setErrorByName("{$identifier}][minX",
          $this->t('The minX coordinate must be a number.'));
      }
      elseif ((float) $minX < -180 || (float) $minX > 180) {
        $form_state->setErrorByName("{$identifier}][minX",
          $this->t('The minX coordinate must be between -180 and 180.'));
      }
    }

    // Validate maxX: must be numeric and between -180 and 180.
    $maxX = $values['maxX'] ?? NULL;
    if ($maxX !== NULL && $maxX !== '') {
      if (!is_numeric($maxX)) {
        $form_state->setErrorByName("{$identifier}][maxX",
          $this->t('The maxX coordinate must be a number.'));
      }
      elseif ((float) $maxX < -180 || (float) $maxX > 180) {
        $form_state->setErrorByName("{$identifier}][maxX",
          $this->t('The maxX coordinate must be between -180 and 180.'));
      }
    }

    // Validate minY <= maxY constraint.
    if (is_numeric($minY) && is_numeric($maxY) && (float) $minY > (float) $maxY) {
      $form_state->setErrorByName("{$identifier}][minY",
        $this->t('The minimum Latitude (minY) cannot be greater than the maximum Latitude (maxY).'));
    }
  }

}
