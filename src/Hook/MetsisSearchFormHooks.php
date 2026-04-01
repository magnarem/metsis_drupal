<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\metsis_drupal\MetsisConstants;

/**
 * Implements hooks related to the METSIS search form.
 */
class MetsisSearchFormHooks {

  use StringTranslationTrait;

  /**
   * Override the exposed form for the METSIS search view.
   *
   * Implements hook_form_alter().
   *
   * {@inheritdoc}
   */
  #[Hook('form_views_exposed_form_metsis_search_results_alter')]
  public function metsisExposedFormAlter(&$form, FormStateInterface $form_state, $form_id) {
    if ($form_id === 'views_exposed_form' && $form['#id'] === 'views-exposed-form-' . MetsisConstants::METSIS_SEARCH_VIEW_NAME . '-results') {
      // Convert the search input to a button.
      $form['actions']['submit']['#attributes']['data-twig-suggestion'] = 'search_results_submit';
      $form['actions']['submit']['#attributes']['class'][] = 'metsis-search-box__button';
      $form['actions']['submit']['#attributes']['aria-label'][] = $this->t("Search");

      // Set weights to reorder elements. submit button after text input.
      $form['actions']['#weight'] = -99;
      $form['text']['#weight'] = -98;

      $form['search-box-container'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'metsis-search-box-container',
          ],
        ],
      ];

      // Move the text search input and submit button into the container.
      if (isset($form['text'])) {
        $form['text']['#attributes']['class'][] = 'metsis-search-text__input';
        $form['text']['#title_display'] = 'invisible';
        $form['search-box-container']['text'] = $form['text'];
        unset($form['text']);
      }
      if (isset($form['actions']['submit'])) {
        $form['search-box-container']['actions'] = $form['actions'];
        unset($form['actions']);
      }
      $form['search-box-container']['#weight'] = -100;

      /* Add horizontal tabs for the bbox filter */
      // If (isset($form['bbox_wrapper'])) {
      //   // Add a horizontal tabs container to the bbox_wrapper.
      //   $form['bbox_wrapper']['horizontal_tabs'] = [
      //     '#type' => 'horizontal_tabs',
      //     '#title' => $this->t('Geographic Filter Options'),
      //     '#title_display' => 'invisible',
      //   ];.
      // // Move `bbox_map_filter` into another new tab.
      //   $form['bbox_wrapper']['bbox_map_filter_tab'] = [
      //     '#type' => 'details',
      //     '#title' => $this->t('Map'),
      //     '#group' => 'horizontal_tabs',
      //     '#open' => TRUE,
      //   ];
      // // Move the `bbox_map_filter` element into the `bbox_map_filter_tab`.
      //   $form['bbox_wrapper']['bbox_map_filter_tab']['bbox_map_filter'] = $form['bbox_wrapper']['bbox_map_filter'];
      //   unset($form['bbox_wrapper']['bbox_map_filter']);
      // // Move `bbox` into a new tab.
      //   $form['bbox_wrapper']['bbox_tab'] = [
      //     '#type' => 'details',
      //     '#title' => $this->t('Coordinates'),
      //     '#group' => 'horizontal_tabs',
      //     '#open' => FALSE,
      //   ];
      // // Move the original `bbox` element into the `bbox_tab`.
      //   $form['bbox_wrapper']['bbox_tab']['bbox'] = $form['bbox_wrapper']['bbox'];
      //   unset($form['bbox_wrapper']['bbox']);
      // }.
      dump($form, __FUNCTION__);

      unset($form['#disable_inline_form_errors']);
    }

  }

}
