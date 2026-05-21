<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\metsis_drupal\Service\LeafletMapRenderer;
use Drupal\metsis_drupal\Service\MarkdownDetectorInterface;
use Drupal\metsis_drupal\Service\MetadataDocumentNormalizer;
use Drupal\metsis_drupal\Service\SolrDocumentLoader;
use Drupal\metsis_drupal\Utility\MetsisSolrUtilities;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for rendering a single metadata document page from Solr.
 */
final class MetadataDocumentController extends ControllerBase {

  /**
   * Document loader service.
   *
   * @var \Drupal\metsis_drupal\Service\SolrDocumentLoader
   */
  protected SolrDocumentLoader $documentLoader;

  /**
   * Metadata document normalizer service.
   *
   * @var \Drupal\metsis_drupal\Service\MetadataDocumentNormalizer
   */
  protected MetadataDocumentNormalizer $metadataDocumentNormalizer;

  /**
   * Leaflet map renderer service.
   *
   * @var \Drupal\metsis_drupal\Service\LeafletMapRenderer
   */
  protected LeafletMapRenderer $leafletMapRenderer;

  /**
   * The markdown detector service.
   *
   * @var \Drupal\metsis_drupal\Service\MarkdownDetectorInterface
   */
  protected MarkdownDetectorInterface $markdownDetector;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandlerService;

  /**
   * Constructs the controller.
   */
  public function __construct(
    SolrDocumentLoader $documentLoader,
    MetadataDocumentNormalizer $metadataDocumentNormalizer,
    LeafletMapRenderer $leafletMapRenderer,
    MarkdownDetectorInterface $markdown_detector,
    ModuleHandlerInterface $module_handler,
  ) {
    $this->documentLoader = $documentLoader;
    $this->metadataDocumentNormalizer = $metadataDocumentNormalizer;
    $this->leafletMapRenderer = $leafletMapRenderer;
    $this->markdownDetector = $markdown_detector;
    $this->moduleHandlerService = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('metsis_drupal.solr_document_loader'),
      $container->get('metsis_drupal.metadata_document_normalizer'),
      $container->get('metsis_drupal.leaflet_map_renderer'),
      $container->get('metsis_drupal.markdown_detector'),
      $container->get('module_handler'),
    );
  }

  /**
   * Render a full metadata document page from the Solr id.
   *
   * @param string $id
   *   Solr id.
   *
   * @return array
   *   Render array.
   */
  public function view(string $id): array {
    if (!MetsisSolrUtilities::isValidIdentifier($id)) {
      throw new BadRequestHttpException('Invalid metadata identifier.');
    }

    $document = $this->loadDocument($id);
    if ($document === NULL) {
      throw new NotFoundHttpException('Metadata document not found.');
    }

    $title = (string) ($document['title'] ?? $document['title_en'] ?? $document['metadata_identifier'] ?? $id);
    $abstract = (string) ($document['abstract'] ?? $document['abstract_en'] ?? '');
    $abstract_html = $this->renderAbstract($abstract);

    return [
      '#theme' => 'metsis_metadata_document',
      '#id' => $id,
      '#abstract' => $abstract_html,
      '#title' => $title,
      '#metadata_updates' => $this->metadataDocumentNormalizer->buildMetadataUpdates($document),
      '#summary' => $this->metadataDocumentNormalizer->buildSummary($document),
      '#sections' => $this->metadataDocumentNormalizer->buildSections($document, $this->leafletMapRenderer),
      // '#raw' => $this->buildRaw($document),
      '#attached' => [
        'library' => [
          'metsis_drupal/metsis_metadata_document',
        ],
      ],
      '#cache' => [
        'contexts' => ['url.path'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Render metadata document wrapped for HTMX dialog usage.
   *
   * @param string $id
   *   Solr id.
   *
   * @return array
   *   Render array for dialog content.
   */
  public function viewHtmx(string $id): array {
    $metadata_document = $this->view($id);

    return [
      '#theme' => 'metsis_metadata_document_dialog',
      '#content' => $metadata_document,
      '#attached' => [
        'library' => [
          'metsis_drupal/metsis_metadata_dialog',
        ],
      ],
      '#cache' => [
        'contexts' => ['url.path'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Render abstract with markdown detection support.
   *
   * Automatically detects markdown content and uses metsis_markdown filter
   * when markdown module is installed. Falls back to metsis_html for
   * non-markdown content or if markdown rendering fails.
   *
   * @param string $abstract_text
   *   The raw abstract text from Solr.
   *
   * @return array
   *   The rendered abstract HTML.
   */
  private function renderAbstract(string $abstract_text): array {
    if (empty($abstract_text)) {
      return [
        '#markup' => '',
      ];
    }

    // Check if markdown module is installed.
    $markdown_available = $this->moduleHandlerService->moduleExists('markdown');

    // Detect markdown patterns in the abstract.
    $is_markdown = $markdown_available &&
      $this->markdownDetector->detectMarkdown($abstract_text);

    // Choose filter format based on detection.
    $format = $is_markdown ? 'metsis_markdown' : 'metsis_html';

    try {
      $rendered = check_markup($abstract_text, $format);
      if ($is_markdown && !empty($rendered)) {
        $this->getLogger('metsis_drupal')->debug('Abstract rendered as markdown');
      }
    }
    catch (\Exception $e) {
      // If markdown rendering fails, fall back to HTML format.
      if ($is_markdown) {
        $this->getLogger('metsis_drupal')->warning(
          'Markdown rendering failed for abstract, falling back to metsis_html: @error',
          ['@error' => $e->getMessage()]
        );
        $rendered = check_markup($abstract_text, 'metsis_html');
      }
      else {
        throw $e;
      }
    }

    return [
      '#markup' => $rendered ?? '',
    ];
  }

  /**
   * Load a single Solr document by id using transformer syntax fields.
   *
   * First query gets available fields, second query applies [json] and [geo]
   * transformers for all *_json and *_geojson fields present in that document.
   *
   * @param string $id
   *   Solr id.
   *
   * @return array<string, mixed>|null
   *   Solr document or null if no match.
   */
  private function loadDocument(string $id): ?array {
    return $this->documentLoader->loadDocumentById($id, [
      '*',
      'personnel_json:[json]',
      'data_access_json:[json]',
      'platform_json:[json]',
      'related_information_json:[json]',
      'last_metadata_update_json:[json]',
      'dataset_citation_json:[json]',
    ]);
  }

}
