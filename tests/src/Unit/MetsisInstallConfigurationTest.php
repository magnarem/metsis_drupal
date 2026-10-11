<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\Core\Config\FileStorage;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks runtime dependencies and the shipped autocomplete configuration.
 */
final class MetsisInstallConfigurationTest extends UnitTestCase {

  /**
   * Tests enabled search UI integrations are required for new installations.
   */
  public function testDependencies(): void {
    $root = dirname(__DIR__, 3);
    $info = Yaml::parseFile($root . '/metsis_drupal.info.yml');
    $dependencies = $info['dependencies'];
    foreach ([
      'search_api_autocomplete:search_api_autocomplete',
      'search_api_solr:search_api_solr_autocomplete',
      'facets:facets_searchbox_widget',
      'views_filters_summary:views_filters_summary_a11y',
      'views_filters_summary:views_filters_summary_bef',
      'views_filters_summary:views_filters_summary_search_api',
      'tagify:tagify',
      'tagify:tagify_facets',
    ] as $dependency) {
      self::assertContains($dependency, $dependencies);
    }
    $storage = new FileStorage($root . '/config/install');
    $modules = array_map(static fn (string $dependency): string => explode(':', $dependency)[1], $dependencies);
    $modules[] = 'metsis_drupal';
    foreach ($storage->listAll() as $name) {
      foreach ($storage->read($name)['dependencies']['module'] ?? [] as $module) {
        self::assertContains($module, $modules, "Installed configuration dependency: $name");
      }
    }
  }

  /**
   * Tests autocomplete targets the installed View/index and existing fields.
   */
  public function testAutocomplete(): void {
    $storage = new FileStorage(dirname(__DIR__, 3) . '/config/install');
    $autocomplete = $storage->read('search_api_autocomplete.search.metsis_search');
    self::assertIsArray($autocomplete);
    self::assertTrue($autocomplete['status']);
    self::assertArrayNotHasKey('uuid', $autocomplete);
    $index = $storage->read('search_api.index.' . $autocomplete['index_id']);
    self::assertIsArray($index);
    foreach ($autocomplete['suggester_settings'] as $settings) {
      foreach ($settings['fields'] ?? [] as $field) {
        self::assertArrayHasKey($field, $index['field_settings'], "Autocomplete field: $field");
      }
    }
    self::assertIsArray($storage->read('views.view.metsis_search'));
    self::assertTrue($autocomplete['search_settings']['views:metsis_search']['displays']['default']);
  }

}
