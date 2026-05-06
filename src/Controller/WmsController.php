<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\metsis_drupal\Service\SolrDocumentLoader;
use Drupal\metsis_drupal\Utility\MetsisSolrUtilities;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns responses for METSIS Search routes.
 */
final class WmsController extends ControllerBase {

  /**
   * Capability requests parameters constant.
   *
   * @var string
   *  The string to append to the WMS URL to get the capabilities document.
   */
  public const CAPABILITY_REQUEST_PARAMETERS = '?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetCapabilities';

  /**
   * The controller constructor.
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
   * Builds the response.
   */
  public function __invoke(string $id): array {
    if (!MetsisSolrUtilities::isValidIdentifier($id)) {
      throw new BadRequestHttpException('Invalid metadata identifier.');
    }

    $document = $this->loadDocument($id);
    if ($document === NULL) {
      throw new NotFoundHttpException('Metadata document not found.');
    }

    unset($document['storage_information_file_location']);

    // Filter the data_access_json for items of type "OGC WMS".
    $filtered = array_filter($document['data_access_json'], function ($item) {
      return isset($item['type']) && strtoupper($item['type']) === 'OGC WMS';
    });

    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('It works!'),
    ];

    return $build;
  }

  /**
   * Load a single Solr document by id, returning the data_access_json.
   *
   * @param string $id
   *   Solr id.
   *
   * @return array<string, mixed>|null
   *   Solr document or null if no match.
   */
  private function loadDocument(string $id): ?array {
    return $this->documentLoader->loadDocumentById($id, [
      'id',
      'metadata_identifier',
      'title',
      'data_access_json:[json]',
    ]);
  }

}
