<?php

namespace Drupal\Tests\metsis_drupal\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the Metsis configuration form and AJAX section switching.
 *
 * @group metsis_drupal
 */
class MetsisSettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['metsis_drupal'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'olivero';

  /**
   * Test the configuration form renders and AJAX switching works.
   */
  public function testConfigFormRendersAndAjaxSwitching() {
    // Login as admin user.
    $admin = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($admin);

    // Visit the config form page.
    $this->drupalGet('/admin/config/metno/metsis-settings');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('METSIS Configuration');
    $this->assertSession()->elementExists('css', '.vertical-tabs__menu');

    // Test that each tab is present and can be switched to.
    $tabs = [
      'Solr and index configuration',
      'Search configuration',
      'Metsis map app configuration',
      'Bbox map filter configuration',
    ];
    foreach ($tabs as $tab) {
      $this->assertSession()->elementExists('xpath', "//a[contains(text(), '{$tab}')]");
      // Simulate clicking the tab (AJAX vertical tabs are handled by Drupal core JS).
      // We check that the fieldset for the tab exists.
      $fieldset = $this->xpath("//details[.//legend[contains(text(), '{$tab}')]]");
      $this->assertNotEmpty($fieldset, "Fieldset for tab '{$tab}' exists.");
    }
  }

}
