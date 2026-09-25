<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

use Drupal\Core\Htmx\Htmx;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Builds the "Open this collection in catalog" HTMX icon button.
 *
 * The button never links directly to the catalog search URL. Instead it
 * issues an HTMX GET request to a small redirect endpoint
 * (@see \Drupal\metsis_drupal\Controller\CatalogController::htmxRedirect)
 * which responds with an HX-Redirect header, so the browser navigates to
 * the catalog only after the server has resolved the destination URL.
 */
final class CatalogButtonBuilder {

  use StringTranslationTrait;

  /**
   * Build the icon_button render array for a parent/collection dataset.
   *
   * @param string $metadata_identifier
   *   Metadata identifier of the parent (collection) dataset.
   *
   * @return array<string, mixed>
   *   Render array for the metsis_drupal:icon_button SDC component.
   */
  public function build(string $metadata_identifier): array {
    $htmx_url = Url::fromRoute('metsis_drupal.catalog_htmx_redirect', [
      'id' => $metadata_identifier,
    ]);

    $button = [
      '#type' => 'button',
      '#value' => $this->t('Open this collection in catalog'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['button', 'metsis-metadata__collection-catalog-button'],
      ],
    ];

    (new Htmx())
      ->get($htmx_url)
      ->swap('none')
      ->applyTo($button);

    return [
      '#type' => 'component',
      '#component' => 'metsis_drupal:icon_button',
      '#props' => [
        'icon_size' => 18,
        'icon_pack' => 'metsis_drupal',
        'icon_id' => 'magnifier-catalog',
      ],
      '#slots' => [
        'button' => $button,
      ],
    ];
  }

}
