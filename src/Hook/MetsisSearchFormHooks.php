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


  #[Hook('form_views_exposed_form_alter')]
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

      unset($form['#disable_inline_form_errors']);
    }
  }

}
