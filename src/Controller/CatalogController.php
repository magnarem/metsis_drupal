<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Htmx\Htmx;
use Drupal\Core\Url;
use Drupal\metsis_drupal\Utility\MetsisSolrUtilities;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Controller for HTMX-driven "Open this collection in catalog" navigation.
 */
final class CatalogController extends ControllerBase {

  /**
   * Returns an HTMX redirect response to the catalog search results.
   *
   * Filters the catalog search view to child datasets of the given
   * collection (parent dataset) identifier.
   *
   * @param string $id
   *   Metadata identifier of the parent/collection dataset.
   *
   * @return array
   *   Response with HX-Redirect header pointing to the catalog search view.
   */
  public function htmxRedirect(string $id): array {
    if (!MetsisSolrUtilities::isValidIdentifier($id)) {
      throw new BadRequestHttpException('Invalid dataset identifier.');
    }

    $catalog_url = Url::fromRoute('view.metsis_search.results', [], [
      'query' => ['related_dataset' => $id],
    ]);

    $build['link'] = [
      '#type' => 'link',
      '#title' => $this->t('Open this collection in catalog'),
      '#url' => $catalog_url,
      '#attributes' => [
        'rel' => 'nofollow noarchive',
      ],
    ];
    (new Htmx())
      ->redirectHeader($catalog_url)
      ->applyTo($build);
    return $build;
  }

}
