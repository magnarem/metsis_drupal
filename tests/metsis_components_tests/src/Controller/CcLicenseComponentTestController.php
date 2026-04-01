<?php

declare(strict_types=1);

namespace Drupal\metsis_components_tests\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Returns responses for Metsis Components Tests routes.
 */
final class CcLicenseComponentTestController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(): array {

    $license_config = $this->config('metsis_drupal.license_icons');
    $license_icons = $license_config->get('license_icons');
    $rows = [];
    foreach ($license_icons as $cc => $info) {
      $rows[] = [
        $cc,
       [
         'data' => [
           '#type' => 'component',
           '#component' => 'metsis_drupal:cc_license',
           '#props' => [
             'license_id' => $cc,
             'license_url' => $info['license_uri'],
             'icon_id' => $info['icon_id'],
             'icon_alt_text' => $info['icon_alt_text'],
           ],
         ],
       ],
      ];
    }
    $build = [
      '#type' => 'table',
      '#header' => [
        'License Code',
        'Icon component',
      ],
      '#rows' => $rows,
    ];

    return $build;
  }

}
