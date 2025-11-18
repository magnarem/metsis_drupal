<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests install and uninstall of the metsis_drupal module.
 */
#[Group('metsis_drupal')]
class MetsisDrupalInstallUninstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'search_api',
    'metsis_drupal',
    'search_api_solr',
  ];

  /**
   * Tests install and uninstall.
   */
  #[Test]
  public function testInstallUninstall() {
    // Ensure the module is installed.
    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('metsis_drupal'), 'metsis_drupal module is installed.');

    // Create a dummy Search API server config
    // to simulate what the module would create .
    /** @var \Drupal\Core\Config\ConfigFactoryInterface $config_factory */
    $config_factory = $this->container->get('config.factory');
    $config = $config_factory->getEditable('search_api.server.metsis_solr');
    $config->set('backend', 'search_api_solr')
      ->set('name', 'METSIS Solr')
      ->save();

    // Confirm the config exists.
    $this->assertNotNull($config_factory->get('search_api.server.metsis_solr')->get('backend'), 'Solr server config exists before uninstall.');

    // Uninstall the module.
    \Drupal::service('module_installer')->uninstall(['metsis_drupal']);

    // Confirm the module is uninstalled.
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('metsis_drupal'), 'metsis_drupal module is uninstalled.');

    // Confirm the config is deleted.
    $this->assertNull($config_factory->get('search_api.server.metsis_solr')->get('backend'), 'Solr server config is deleted on uninstall.');
  }

}
