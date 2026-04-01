<?php

namespace Drupal\metsis_drupal\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Class to handle the page attachments alter hook for the search page.
 */
class SearchPageMetaTagHook {
  /**
   * The route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected ModuleExtensionList $moduleExtensionList;

  /**
   * Constructs a new SearchPageMetaTagHook object.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_extension_list
   *   The module extension list.
   */
  public function __construct(RouteMatchInterface $route_match, ConfigFactoryInterface $config_factory, ModuleExtensionList $module_extension_list) {
    $this->routeMatch = $route_match;
    $this->configFactory = $config_factory;
    $this->moduleExtensionList = $module_extension_list;
  }

  /**
   * Implements hook_page_attachments_alter().
   *
   * @Hook
   */
  #[Hook('page_attachments_alter')]
  public function pageAttachmentsAlter(array &$attachments): void {
    // Get the current route name.
    $current_route = $this->routeMatch->getRouteName();
    // Check if the current route matches the search page route.
    if ($current_route === 'view.metsis_search.results') {
      $this->attachViteModulePreloads($attachments);

      $meta_description = (string) $this->configFactory
        ->get('metsis_drupal.settings')
        ->get('search_meta_description');

      // Dynamically get the current site's base URL.
      $base_url = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();

      // Add a canonical URL.
      $attachments['#attached']['html_head'][] = [
      [
        '#tag' => 'link',
        '#attributes' => [
          'rel' => 'canonical',
          'href' => $base_url . '/metsis/search',
        ],
      ],
        'canonical',
      ];

      // Add the meta robots tag to prevent indexing.
      $attachments['#attached']['html_head'][] = [
      [
        '#tag' => 'meta',
        '#attributes' => [
          'name' => 'robots',
          'content' => 'index, nofollow',
        ],
      ],
        'meta_robots',
      ];

      // Optionally, add a meta description.
      $attachments['#attached']['html_head'][] = [
      [
        '#tag' => 'meta',
        '#attributes' => [
          'name' => 'description',
          'content' => $meta_description,
        ],
      ],
        'meta_description',
      ];
      $attachments['#attached']['html_head'][] = [
      [
        '#tag' => 'meta',
        '#attributes' => [
          'name' => 'referrer',
          'content' => 'strict-origin-when-cross-origin',
        ],
      ],
        'referrer',
      ];

    }
  }

  /**
   * Injects modulepreload hints for shared Vite chunks (ol, Draw, etc.).
   *
   * Reads the Vite manifest so filenames stay correct after each build.
   * Preloading shared chunks in parallel with entry scripts removes the
   * sequential download chain Lighthouse reports as critical path latency.
   *
   * @param array $attachments
   *   The page attachments array, passed by reference.
   */
  private function attachViteModulePreloads(array &$attachments): void {
    $module_path = $this->moduleExtensionList->getPath('metsis_drupal');
    $manifest_path = DRUPAL_ROOT . '/' . $module_path . '/js/metsis/dist/.vite/manifest.json';

    if (!file_exists($manifest_path)) {
      return;
    }

    $raw = file_get_contents($manifest_path);
    if ($raw === FALSE) {
      return;
    }

    $manifest = json_decode($raw, TRUE);
    if (!is_array($manifest)) {
      return;
    }

    $base = base_path() . $module_path . '/js/metsis/dist/';

    // Shared chunks have keys starting with '_' (e.g. _ol-*.js, _Draw-*.js).
    // Preloading them early lets the browser fetch them in parallel with entry
    // scripts instead of waiting for entry parse before discovering the import.
    foreach ($manifest as $key => $chunk) {
      if (!str_starts_with($key, '_') || empty($chunk['file'])) {
        continue;
      }

      $attachments['#attached']['html_head'][] = [
        [
          '#tag' => 'link',
          '#attributes' => [
            'rel' => 'modulepreload',
            'href' => $base . $chunk['file'],
          ],
        ],
        'vite_modulepreload_' . ($chunk['name'] ?? $key),
      ];
    }
  }

}
