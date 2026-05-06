<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;

/**
 * Centralized access to metsis_drupal configuration objects.
 *
 * Consolidates scattered config.get() calls for better maintainability and
 * consistent config object caching.
 */
final class ConfigProvider {

  /**
   * Cache of loaded config objects.
   *
   * @var array<string, \Drupal\Core\Config\ImmutableConfig>
   */
  private array $configCache = [];

  /**
   * Constructor.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Get the metsis_drupal.settings config object.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The settings config.
   */
  public function getSettingsConfig(): ImmutableConfig {
    return $this->getConfigCached('metsis_drupal.settings');
  }

  /**
   * Get the metsis_drupal.metadata_export config object.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The metadata export config.
   */
  public function getMetadataExportConfig(): ImmutableConfig {
    return $this->getConfigCached('metsis_drupal.metadata_export');
  }

  /**
   * Get the metsis_drupal.license_icons config object.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The license icons config.
   */
  public function getLicenseIconsConfig(): ImmutableConfig {
    return $this->getConfigCached('metsis_drupal.license_icons');
  }

  /**
   * Get a config object with caching.
   *
   * @param string $name
   *   Config name.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The config object.
   */
  private function getConfigCached(string $name): ImmutableConfig {
    if (!isset($this->configCache[$name])) {
      $this->configCache[$name] = $this->configFactory->get($name);
    }
    return $this->configCache[$name];
  }

}
