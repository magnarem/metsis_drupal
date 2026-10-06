<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Htmx\Htmx;
use Drupal\Core\Url;
use Drupal\metsis_drupal\Service\SolrDocumentLoader;
use Drupal\metsis_drupal\Utility\MetsisSolrUtilities;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for HTMX-driven "Open this collection in catalog" navigation.
 */
final class CatalogController extends ControllerBase {

  /**
   * Constructs the catalog controller.
   */
  public function __construct(
    private readonly SolrDocumentLoader $documentLoader,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('metsis_drupal.solr_document_loader'),
    );
  }

  /**
   * Returns an HTMX redirect response to the catalog search results.
   *
   * Resolves the metadata identifier from the Solr ID and filters the catalog
   * search view to child datasets of the collection.
   *
   * @param string $id
   *   Solr ID of the parent/collection dataset.
   *
   * @return array
   *   Response with HX-Redirect header pointing to the catalog search view.
   */
  public function htmxRedirect(string $id): array {
    if (!MetsisSolrUtilities::isValidIdentifier($id)) {
      throw new BadRequestHttpException('Invalid dataset identifier.');
    }

    $document = $this->documentLoader->loadDocumentById($id, ['metadata_identifier']);
    if ($document === NULL) {
      throw new NotFoundHttpException('Metadata document not found.');
    }

    $metadata_identifier = MetsisSolrUtilities::firstValue($document['metadata_identifier'] ?? '');
    if ($metadata_identifier === '') {
      throw new NotFoundHttpException('Dataset metadata identifier not found.');
    }

    $catalog_url = Url::fromRoute('view.metsis_search.results', [], [
      'query' => ['related_dataset' => $metadata_identifier],
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
