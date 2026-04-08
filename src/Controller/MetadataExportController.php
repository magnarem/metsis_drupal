<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\metsis_drupal\Service\MetadataExportService;
use Drupal\metsis_drupal\Utility\MetsisSolrUtilities;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Drupal\Core\Htmx\Htmx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for direct metadata export file downloads.
 */
final class MetadataExportController extends ControllerBase {

  /**
   * Metadata export service.
   *
   * @var \Drupal\metsis_drupal\Service\MetadataExportService
   */
  protected MetadataExportService $metadataExportService;

  /**
   * Constructs the controller.
   */
  public function __construct(MetadataExportService $metadata_export_service) {
    $this->metadataExportService = $metadata_export_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('metsis_drupal.metadata_export_service')
    );
  }

  /**
   * Returns a metadata export file download for the given Solr id and type.
   *
   * @param string $id
   *   Solr document identifier.
   * @param string $type
   *   Export type key (e.g. "mmd", "iso19115", "dif10").
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   File download response.
   */
  public function download(string $id, string $type): Response {
    if (!MetsisSolrUtilities::isValidIdentifier($id)) {
      throw new BadRequestHttpException('Invalid dataset identifier.');
    }

    $enabled = $this->metadataExportService->getEnabledExportTypes();
    if (!in_array($type, $enabled, TRUE)) {
      throw new BadRequestHttpException('Requested export type is not enabled.');
    }

    $content = $this->metadataExportService->exportById($id, $type);
    if ($content === NULL || $content === '') {
      throw new NotFoundHttpException('No exportable metadata found for this dataset.');
    }

    $filename = $id . '_' . $type . '.xml';

    $response = new Response($content);
    $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
    $response->headers->set(
      'Content-Disposition',
      'attachment; filename="' . $filename . '"'
    );
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    // Prevent caching of generated file downloads.
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
    $response->headers->set('Pragma', 'no-cache');

    return $response;
  }

  /**
   * Returns an HTMX redirect response to the metadata export download route.
   *
   * @param string $id
   *   Solr document identifier.
   * @param string $type
   *   Export type key.
   *
   * @return array
   *   Response with HX-Redirect header pointing to the download URL.
   */
  public function htmxRedirect(string $id, string $type): array {
    $download_url = Url::fromRoute(
      'metsis_drupal.metadata_export_download',
      ['id' => $id, 'type' => $type],
      ['absolute' => TRUE]
    );

    $build['link'] = [
      '#type' => 'link',
      '#title' => $this->t('Download @type metadata', ['@type' => strtoupper($type)]),
      '#url' => $download_url,
      '#attributes' => [
        'rel' => 'nofollow noarchive noopener noreferrer',
        'referrerpolicy' => 'no-referrer',
        'data-nosnippet' => 'true',
      ],
    ];
    (new Htmx())
      ->redirectHeader($download_url)
      ->applyTo($build);
    return $build;
  }

}
