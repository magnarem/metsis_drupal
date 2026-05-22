<?php

declare(strict_types=1);

namespace Drupal\metsis_components_tests\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;

/**
 * Returns a visual test page for the bbox_form_tabs component.
 */
final class BboxFormTabsComponentTestController extends ControllerBase {

  /**
   * Builds the component test page.
   */
  public function __invoke(): array {
    $tabs = [
      [
        'label' => (string) $this->t('Map Input Tab'),
        'content' => Markup::create('<div style="display:grid;gap:1rem;"><p>This panel contains intentionally large demo content for spacing checks.</p><div style="height:320px;border:1px solid #bbb;border-radius:6px;background:linear-gradient(135deg,#dcecff,#f8fbff);display:flex;align-items:center;justify-content:center;font-weight:600;color:#2c3e50;">Large map placeholder area (320px height)</div><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Nulla sed felis justo. Integer feugiat feugiat nisl, id tincidunt magna tristique vitae.</p></div>'),
      ],
      [
        'label' => (string) $this->t('Coordinates Input Tab'),
        'content' => Markup::create('<div style="display:grid;gap:1rem;"><p>This panel emulates a larger coordinate form layout with extra vertical content.</p><div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;"><label>North<input type="text" value="78.2000" style="display:block;width:100%;margin-top:0.25rem;" /></label><label>East<input type="text" value="15.6000" style="display:block;width:100%;margin-top:0.25rem;" /></label><label>South<input type="text" value="74.1000" style="display:block;width:100%;margin-top:0.25rem;" /></label><label>West<input type="text" value="10.3000" style="display:block;width:100%;margin-top:0.25rem;" /></label></div><div style="height:220px;border:1px dashed #9aa6b2;border-radius:6px;background:repeating-linear-gradient(45deg,#f7f9fc,#f7f9fc 10px,#eef3f8 10px,#eef3f8 20px);"></div></div>'),
      ],
    ];

    return [
      '#type' => 'container',
      'intro' => [
        '#markup' => '<p><strong>Bbox form tabs component test page.</strong> Use this to verify tab/button/panel spacing, overflow, and focus states.</p>',
      ],
      'tabs' => [
        '#type' => 'component',
        '#component' => 'metsis_drupal:bbox_form_tabs',
        '#props' => [
          'default_tab' => 'map',
          'tabs' => $tabs,
        ],
      ],
    ];
  }

}
