<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Controller;

use Drupal\Component\Utility\UrlHelper;
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

    return $this->buildDocumentRenderArray($id, 'metsis-map-app', TRUE);
  }

  /**
   * Render the inline WMS fragment for HTMX row usage.
   *
   * @param string $id
   *   Solr id.
   * @param string $mount_id
   *   Unique mount container id.
   *
   * @return array
   *   Render array for HTMX replacement.
   */
  public function viewHtmx(string $id, string $mount_id): array {
    if (!MetsisSolrUtilities::isValidIdentifier($id)) {
      throw new BadRequestHttpException('Invalid metadata identifier.');
    }

    if (!preg_match('/^[A-Za-z0-9_-]+$/', $mount_id)) {
      throw new BadRequestHttpException('Invalid WMS mount identifier.');
    }

    return $this->buildDocumentRenderArray($id, $mount_id, FALSE);
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

  /**
   * Build the WMS render array for the full page or HTMX fragment.
   *
   * @param string $id
   *   Solr id.
   * @param string $mount_id
   *   DOM id for the map mount point.
   * @param bool $include_global_map_app
   *   Whether to attach the legacy global mapApp config.
   *
   * @return array
   *   Render array.
   */
  private function buildDocumentRenderArray(string $id, string $mount_id, bool $include_global_map_app): array {
    $document = $this->loadDocument($id);
    if ($document === NULL) {
      throw new NotFoundHttpException('Metadata document not found.');
    }

    $config = $this->config('metsis_drupal.settings');
    $preferred_layers = $this->normalizeLayerList($config->get('preferred_wms_layers'));
    $blacklisted_layers = $this->normalizeLayerList($config->get('blacklisted_wms_layers'));
    $wms_endpoints = $this->buildWmsEndpoints($document['data_access_json'] ?? []);
    $title = (string) ($document['title'] ?? $document['metadata_identifier'] ?? $id);
    $mount_selector = '#' . $mount_id;

    $drupal_settings = [
      'metsis_drupal' => [
        'map_app' => [
          'mount_selectors' => [$mount_selector],
          'instances' => [
            $mount_selector => [
              'features' => [
                'geojson' => FALSE,
                'boundingBox' => FALSE,
                'wms' => !empty($wms_endpoints),
                'wmsEndpoints' => $wms_endpoints,
                'wmsPreferredLayers' => $preferred_layers,
                'wmsBlacklistedLayers' => $blacklisted_layers,
                'wmsUrl' => $wms_endpoints[0]['serviceUrl'] ?? NULL,
                'defaultWmsLayers' => [],
                'geocoder' => FALSE,
                'layerSwitcher' => TRUE,
                'projectionSwitcher' => TRUE,
                'supportedProjections' => [
                  'EPSG:4326' => 'WGS 84',
                  'EPSG:3857' => 'Pseudo-Mercator',
                  'EPSG:32661' => 'UPS North (WGS 84)',
                  'EPSG:32761' => 'UPS South (WGS 84)',
                  'EPSG:5041' => 'WGS 84 / UPS North (E,N)',
                  'EPSG:5042' => 'WGS 84 / UPS South (E,N)',
                ],
                'defaultProjection' => (string) ($config->get('map_default_projection') ?? 'EPSG:3857'),
              ],
            ],
          ],
          'default_projection' => $config->get('map_default_projection') ?? 'EPSG:3857',
        ],
      ],
    ];

    if ($include_global_map_app) {
      $drupal_settings['mapApp'] = [
        'features' => [
          'wms' => !empty($wms_endpoints),
          'wmsEndpoints' => $wms_endpoints,
          'wmsPreferredLayers' => $preferred_layers,
          'wmsBlacklistedLayers' => $blacklisted_layers,
          'wmsUrl' => $wms_endpoints[0]['serviceUrl'] ?? NULL,
          'defaultWmsLayers' => [],
          'layerSwitcher' => TRUE,

        ],
      ];
    }

    $build = [
      '#theme' => 'metsis_wms_document',
      '#id' => $id,
      '#title' => $title,
      '#metadata_identifier' => (string) ($document['metadata_identifier'] ?? $id),
      '#wms_endpoints' => $wms_endpoints,
      '#mount_id' => $mount_id,
      '#inline_mode' => !$include_global_map_app,
      '#app_config' => $drupal_settings['metsis_drupal']['map_app']['instances'][$mount_selector],
      '#attached' => [
        'library' => [
          'metsis_drupal/metsis_metadata_document',
          'metsis_drupal/metsis_map',
        ],
        'drupalSettings' => $drupal_settings,
      ],
      '#cache' => [
        'contexts' => ['url.path'],
        'max-age' => 300,
      ],
    ];

    return $build;
  }

  /**
   * Build a normalized list of WMS endpoints from data_access_json.
   *
   * @param mixed $data_access
   *   Solr document data_access_json field value.
   *
   * @return array<int, array<string, string>>
   *   Endpoint list with id, label, serviceUrl and capabilitiesUrl.
   */
  private function buildWmsEndpoints(mixed $data_access): array {
    if (!is_array($data_access)) {
      return [];
    }

    $endpoints = [];
    foreach ($data_access as $index => $item) {
      if (!is_array($item)) {
        continue;
      }

      $type = strtoupper((string) ($item['type'] ?? ''));
      if ($type !== 'OGC WMS') {
        continue;
      }

      $resource = trim((string) ($item['resource'] ?? ''));
      if ($resource === '' || !UrlHelper::isValid($resource, TRUE)) {
        continue;
      }

      $service_url = $this->stripCapabilitiesParams($resource);
      $endpoint_id = 'wms_' . $index;
      $endpoints[$service_url] = [
        'id' => $endpoint_id,
        'label' => (string) ($item['description'] ?? $item['name'] ?? $service_url),
        'serviceUrl' => $service_url,
        'capabilitiesUrl' => $this->appendCapabilitiesParams($service_url),
      ];
    }

    return array_values($endpoints);
  }

  /**
   * Normalize configured layer names to a clean unique list.
   *
   * @param mixed $layers
   *   Layer list from configuration.
   *
   * @return array<int, string>
   *   Normalized layers.
   */
  private function normalizeLayerList(mixed $layers): array {
    if (!is_array($layers)) {
      return [];
    }

    $normalized = [];
    foreach ($layers as $layer) {
      $layer_name = trim((string) $layer);
      if ($layer_name === '') {
        continue;
      }
      $normalized[$layer_name] = $layer_name;
    }

    return array_values($normalized);
  }

  /**
   * Append GetCapabilities parameters to a WMS base URL.
   */
  private function appendCapabilitiesParams(string $wms_url): string {
    if (preg_match('/request\s*=\s*getcapabilities/i', $wms_url) === 1) {
      return $wms_url;
    }

    $separator = str_contains($wms_url, '?') ? '&' : '?';
    return $wms_url . $separator . ltrim(self::CAPABILITY_REQUEST_PARAMETERS, '?');
  }

  /**
   * Remove GetCapabilities related query parameters from a URL.
   */
  private function stripCapabilitiesParams(string $wms_url): string {
    $parts = parse_url($wms_url);
    if ($parts === FALSE) {
      return $wms_url;
    }

    $query = [];
    if (!empty($parts['query'])) {
      parse_str($parts['query'], $query);
      unset(
        $query['SERVICE'],
        $query['service'],
        $query['REQUEST'],
        $query['request'],
        $query['VERSION'],
        $query['version']
      );
    }

    $rebuilt = '';
    if (!empty($parts['scheme'])) {
      $rebuilt .= $parts['scheme'] . '://';
    }
    if (!empty($parts['user'])) {
      $rebuilt .= $parts['user'];
      if (!empty($parts['pass'])) {
        $rebuilt .= ':' . $parts['pass'];
      }
      $rebuilt .= '@';
    }
    if (!empty($parts['host'])) {
      $rebuilt .= $parts['host'];
    }
    if (!empty($parts['port'])) {
      $rebuilt .= ':' . $parts['port'];
    }
    $rebuilt .= $parts['path'] ?? '';
    if (!empty($query)) {
      $rebuilt .= '?' . http_build_query($query);
    }
    if (!empty($parts['fragment'])) {
      $rebuilt .= '#' . $parts['fragment'];
    }

    return $rebuilt !== '' ? $rebuilt : $wms_url;
  }

}
