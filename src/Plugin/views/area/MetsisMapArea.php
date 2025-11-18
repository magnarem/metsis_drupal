<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\views\area;

use Drupal\views\Plugin\views\area\AreaPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Attribute\ViewsArea;

/**
 * Defines a Views area plugin to render the Map App.
 *
 * @ingroup views_area_handlers
 */
#[ViewsArea('metsis_map')]
class MetsisMapArea extends AreaPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defineOptions() {
    $options = parent::defineOptions();
    $options['map_app_settings'] = ['default' => []];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['map_app_settings'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Map App Settings'),
      '#description' => $this->t('Provide JSON configuration for the Map App.'),
      '#default_value' => json_encode($this->options['map_app_settings'], JSON_PRETTY_PRINT),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE) {
    // Render the Map App container and attach the necessary libraries.
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'metsis-map-app',
        'class' => ['metsis-search-map'],
      ],
      '#attached' => [
        'library' => [
          'metsis_drupal/metsis_map',
        ],
        'drupalSettings' => [
          'mapApp' => $this->options['map_app_settings'],
        ],
      ],
    ];

    return $build;
  }

}
