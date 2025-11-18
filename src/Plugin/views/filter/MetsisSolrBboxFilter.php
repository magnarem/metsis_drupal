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
    if (empty($form[$identifier . '_wrapper'][$identifier])) {
      return;
    }
    $form[$identifier . '_wrapper']['#tree'] = FALSE;
    $form[$identifier . '_wrapper'][$identifier]['#tree'] = TRUE;
    $form[$identifier . '_wrapper']['#attributes']['class'][] = "bbox-exposed-filter-wrapper";

    if (
    !$this->options['expose']['map_input']
    || empty($this->options['expose']['map_input'])
    ) {
      return;
    }

    // Check if the map_input option is enabled.
    if (!empty($this->options['expose']['map_input'])) {
      $form[$identifier . '_wrapper']['bbox_map_filter'] = [
        '#type' => 'container',
        '#title' => $this->t('Select bounding box on map'),
        '#tree' => FALSE,
        '#attributes' => [
          'id' => 'bbox-map-filter-container',
          'class' => ['bbox-map-filter-container'],
        ],
      ];
      $form[$identifier . '_wrapper']['bbox_map_filter']['coords'] = [
        '#type' => 'markup',
        '#markup' => '<div id="coords">
          <span class="coords">Create filter...</span>
          <i class="bbox-remove fa fa-remove"></i>
          </div>',
      ];

      if (empty($this->options['expose']['user_input'])) {
        // Hide the exposed form inputs by rendering them as hidden fields.
        $form[$identifier . '_wrapper'][$identifier]['minX']['#type'] = 'hidden';
        $form[$identifier . '_wrapper'][$identifier]['maxX']['#type'] = 'hidden';
        $form[$identifier . '_wrapper'][$identifier]['maxY']['#type'] = 'hidden';
        $form[$identifier . '_wrapper'][$identifier]['minY']['#type'] = 'hidden';
      }
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
    // dpm($input, __FUNCTION__);.
    $identifier = $this->options['expose']['identifier'];
    if (empty($this->options['exposed'])) {
      return TRUE;
    }
    $rc = parent::acceptExposedInput($input);
    /*
     * Make sure all coordinates are set and numeric.
     */
    if (NULL == $input[$identifier]) {
      return FALSE;
    }
    foreach ($input[$identifier] as $key => $value) {
      if (empty($value || empty($key))) {
        $rc = FALSE;
      }
      else {
        if (!is_numeric($value)) {
          $rc = FALSE;
        }
        else {
          $rc = TRUE;
        }
      }
    }
    return $rc;
  }

  /**
   * {@inheritdoc}
   *
   * {@todo} Fix when webprofiler is not making problems anymore.
   */
  public function validateExposed(&$form, FormStateInterface $form_state) {
    // parent::validateExposed($form, $form_state);.
    $identifier = $this->options['expose']['identifier'];
    if (empty($this->options['exposed'])) {
      return;
    }

    // dpm($form_state->getValues(), __FUNCTION__);
    // Validate BBox values for exposed form.
    $coordinates = ['minX', 'maxX', 'maxY', 'minY'];
    foreach ($coordinates as $coordinate) {
      $value = &$form_state->getValue([$identifier, $coordinate]);
      // dpm($value, __FUNCTION__ . " $coordinate");
      // dpm($form, __FILE__ . ':' . __LINE__);
      // $elem = $form['bbox_wrapper']['bbox_wrapper']['bbox_wrapper'];
      // dpm($elem);
      if ($value == NULL || $value == '') {
        // $form_state->setError($elem[$coordinate],
        // $this->t('The @coordinate coordinate is required.',
        // ['@coordinate' => $coordinate]));
      }
      else {
        $value = trim($value);
        if (!is_numeric($value)) {
          // $form_state->setErrorByName('bbox][' . $coordinate . ']',
          // $this->t('The @coordinate coordinate must be a number.',
          // ['@coordinate' => $coordinate]));
          // $form_state->setErrorByName('bbox][maxX]',
          // $this->t('The maxX coordinate must be a number.'));
        }
      }
    }
  }

}
