<?php

declare(strict_types=1);

namespace Drupal\dynamic_landing_pages\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Custom theme related hooks.
 */
class ThemeHooks {

  /**
    * Implement hook_theme.
    */
  #[Hook('theme')]
  public function themeHook(): array {

    return [
      'dynamic_landing_page' => [
        'variables' => [
          'title'            => '',
          'abstract'         => NULL,
          'summary'          => [],
          'sections'         => [],
          'metadata_updates' => [],
          'raw_solr_doc'      => [],
          'export_form'      => NULL,
        ],
        'template' => 'dynamic-landing-page',
      ],
    ];
  }

}
