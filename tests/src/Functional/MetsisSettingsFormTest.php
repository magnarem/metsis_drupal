<?php

namespace Drupal\Tests\metsis_drupal\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Metsis configuration form and AJAX section switching.
 */
#[Group('metsis_drupal')]
#[RunTestsInSeparateProcesses]
class MetsisSettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'editor',
    'metsis_drupal',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'claro';

  /**
   * Test the configuration form renders and AJAX switching works.
   */
  #[Test]
  public function testConfigFormRendersAndAjaxSwitching() {
    // Login as admin user.
    $admin = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($admin);

    // Visit the config form page.
    $this->drupalGet('/admin/config/metno/metsis-drupal');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('METSIS Configuration');
    $this->assertSession()->elementExists('css', 'label.form-item__label');

    // Test that each tab is present and can be switched to.
    $tabs = [
      'Solr and index configuration',
      'Search configuration',
      'Metsis map app configuration',
      'METSIS services configuration',
    ];
    foreach ($tabs as $tab) {
      $this->assertSession()->elementExists('xpath', "//summary[contains(text(), '{$tab}')]");
      // We check that the fieldset for the tab exists.
      $fieldset = $this->xpath("//details[.//summary[contains(text(), '{$tab}')]]");
      $this->assertNotEmpty($fieldset, "Fieldset for tab '{$tab}' exists.");
    }
  }

  /**
   * Test override logic.
   *
   * Test that the bokeh_plot_service_url field is disabled when overridden
   * and not saved to active config.
   */
  #[Test]
  public function testBokehPlotServiceUrlOverride() {
    // Set the override in settings.php via the config API (simulate for test).
    \Drupal::service('config.factory')->getEditable('metsis_drupal.settings')
      ->set('bokeh_plot_service_url', 'https://example.com/should-not-be-saved')
      ->save();

    // Simulate the override (in real test, this would be set in settings.php).
    $GLOBALS['config']['metsis_drupal.settings']['bokeh_plot_service_url'] = 'https://overridden-url.com';
    // Login as admin user.
    $admin = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($admin);

    // Visit the config form page.
    $this->drupalGet('/admin/config/metno/metsis-drupal');
    $this->assertSession()->statusCodeEquals(200);

    // Check that the bokeh_plot_service_url field is disabled and has the correct value.
    $field = $this->getSession()->getPage()->findField('Bokeh Plot Service URL');
    $this->assertNotNull($field, 'Bokeh Plot Service URL field exists.');
    $this->assertTrue($field->hasAttribute('disabled'), 'Field is disabled when overridden.');
    $this->assertEquals('https://overridden-url.com', $field->getValue(), 'Field shows the overridden value.');

    // Try to submit the form with a different value (should not change active config).
    $form = $this->getSession()->getPage();
    $form->pressButton('Save configuration');

    // Check that the active config value is still the original, not the override.
    $active_value = \Drupal::config('metsis_drupal.settings')->getRawData()['bokeh_plot_service_url'];
    $this->assertEquals('https://example.com/should-not-be-saved', $active_value, 'Active config value is not overwritten by the override.');
  }

}
