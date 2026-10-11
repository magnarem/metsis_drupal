<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Plugin\views\display_extender;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\metsis_drupal\Utility\MetsisViewContext;
use Drupal\views\Attribute\ViewsDisplayExtender;
use Drupal\views\Plugin\views\display_extender\DisplayExtenderPluginBase;

/**
 * Adds inheritable layout settings to METSIS-index displays.
 */
#[ViewsDisplayExtender(
  id: 'metsis_layout',
  title: new TranslatableMarkup('METSIS layout'),
  help: new TranslatableMarkup('Configure the METSIS filter sidebar.'),
)]
final class MetsisLayout extends DisplayExtenderPluginBase {

  public const DEFAULT_WIDTH = '24rem';
  public const WIDTH_PATTERN = '/^(?:[1-9]\d*(?:\.\d+)?|0?\.\d*[1-9]\d*)(?:rem|em|px)$/D';

  /**
   * {@inheritdoc}
   */
  public function defineOptionsAlter(&$options) {
    $options['metsis_sidebar_width'] = ['default' => self::DEFAULT_WIDTH];
    if (isset($options['defaults'])) {
      $options['defaults']['default']['metsis_sidebar_width'] = TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultableSections(&$sections, $section = NULL) {
    $sections['metsis_layout'] = ['metsis_sidebar_width'];
  }

  /**
   * {@inheritdoc}
   */
  public function optionsSummary(&$categories, &$options) {
    if (!MetsisViewContext::isView($this->view)) {
      return;
    }
    $options['metsis_layout'] = [
      'category' => 'other',
      'title' => $this->t('METSIS filter sidebar'),
      'value' => $this->displayHandler->getOption('metsis_sidebar_width'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    if ($form_state->get('section') !== 'metsis_layout' || !MetsisViewContext::isView($this->view)) {
      return;
    }
    $form['metsis_sidebar_width'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Filter sidebar width'),
      '#default_value' => $this->displayHandler->getOption('metsis_sidebar_width'),
      '#required' => TRUE,
      '#description' => $this->t('A positive CSS length in rem, em, or px, for example 24rem or 320px. Applies to the METSIS results layout; narrow screens stack the filters above results.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state) {
    if ($form_state->get('section') === 'metsis_layout' && isset($form['metsis_sidebar_width'])) {
      $width = trim((string) $form_state->getValue('metsis_sidebar_width'));
      if (!self::isValidWidth($width)) {
        $form_state->setError($form['metsis_sidebar_width'], $this->t('Enter a positive width in rem, em, or px.'));
      }
      $form_state->setValue('metsis_sidebar_width', $width);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    if ($form_state->get('section') === 'metsis_layout' && isset($form['metsis_sidebar_width'])) {
      $this->displayHandler->setOption('metsis_sidebar_width', $form_state->getValue('metsis_sidebar_width'));
    }
  }

  /**
   * Restricts CSS values to positive lengths, not arbitrary style declarations.
   */
  public static function isValidWidth(string $width): bool {
    return preg_match(self::WIDTH_PATTERN, $width) === 1;
  }

}
