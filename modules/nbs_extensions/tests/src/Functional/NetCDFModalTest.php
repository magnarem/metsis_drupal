<?php

declare(strict_types=1);

namespace Drupal\Tests\nbs_extensions\Functional;

use Drupal\Core\Url;
use Drupal\Core\Config\FileStorage;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Behat\Mink\Driver\BrowserKitDriver;

/**
 * Tests real core login sessions and the protected NetCDF workflow.
 */
#[Group('nbs_extensions')]
final class NetCDFModalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['editor', 'nbs_extensions_test'];

  /**
   * The existing parent View contains unrelated BEF/bbox schema gaps.
   *
   * @var string[]
   */
  protected static $configSchemaCheckerExclusions = ['views.view.metsis_search'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * An account permitted to use the NetCDF request form.
   */
  private UserInterface $account;

  /**
   * Installs English field types required by the default METSIS server.
   */
  protected function installModulesFromClassProperty(ContainerInterface $container): void {
    $container->get('module_installer')->install(['search_api_solr']);
    $container = \Drupal::getContainer();
    $storage = new FileStorage($container->get('extension.list.module')->getPath('search_api_solr') . '/config/optional');
    foreach (['text_en_7_0_0', 'text_phonetic_en_7_0_0'] as $id) {
      $name = 'search_api_solr.solr_field_type.' . $id;
      $container->get('config.factory')->getEditable($name)->setData($storage->read($name))->save();
    }
    parent::installModulesFromClassProperty($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->account = $this->drupalCreateUser(['access content']);
    $this->config('nbs_extensions.settings')
      ->set('netcdf_ondemand_service_endpoint', 'https://nbs-backend.invalid/request')
      ->save();
  }

  /**
   * Uses ordinary HTTP requests to assert HTMX headers and session cookies.
   */
  public function testAnonymousLoginAndRequest(): void {
    $this->drupalGet($this->route('nbs_extensions.netcdf_modal_request'));
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($this->route('nbs_extensions.netcdf_on_demand'));
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalGet($this->route('nbs_extensions.netcdf_modal'), [], $this->htmxHeaders());
    $this->assertSession()->elementExists('css', 'dialog[data-metsis-metadata-dialog]');
    $this->assertSession()->elementTextContains('css', '.metsis-metadata-dialog__title', 'NetCDF on demand');
    $this->assertSession()->hiddenFieldValueEquals('form_id', 'nbs_extensions_netcdf_modal_login');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
    $this->submitForm(['name' => $this->account->getAccountName(), 'pass' => 'wrong'], 'Log in');
    $this->assertSession()->pageTextContains('Unrecognized username or password');
    $this->assertSession()->elementExists('css', '#nbs-netcdf-modal-content input[name="pass"]');

    $this->submitForm(['name' => $this->account->getAccountName(), 'pass' => $this->account->passRaw], 'Log in');
    $this->assertSession()->statusCodeEquals(204);
    $this->assertSession()->responseHeaderEquals('HX-Trigger', 'nbsNetcdfAuthenticated');
    $this->assertSession()->responseHeaderDoesNotExist('Location');

    // This separate GET must use the session finalized by core login.
    $this->drupalGet($this->route('nbs_extensions.netcdf_modal_request'), [], $this->htmxHeaders());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('NBS test product');
    $this->assertSession()->fieldExists('confirm');
    $this->submitForm([], 'Send request');
    $this->assertSession()->pageTextContains('field is required');

    $this->container->get('state')->set('nbs_test.status', 503);
    $this->submitForm(['confirm' => TRUE], 'Send request');
    $this->assertSession()->pageTextContains('HTTP status 503');
    $this->assertSession()->buttonExists('Send request');
    $this->container->get('state')->set('nbs_test.status', 200);
    $this->submitForm(['confirm' => TRUE], 'Send request');
    $this->assertSession()->pageTextContains('Your test request was accepted.');
    $this->assertSession()->elementExists('css', 'input[type="submit"][disabled]');
    $this->postCurrentForm(['confirm' => 1]);
    $this->container->get('state')->resetCache();
    $this->assertSame(2, $this->container->get('state')->get('nbs_test.requests'));
    $this->assertSame(
      ['inputs' => ['email' => $this->account->getEmail(), 'products' => ['NBS test product']]],
      $this->container->get('state')->get('nbs_test.payload'),
    );
  }

  /**
   * Preserves blocked-account and core flood denial inside the modal.
   */
  public function testBlockedAccountAndFlood(): void {
    $this->account->block()->save();
    $this->drupalGet($this->route('nbs_extensions.netcdf_modal_login'), [], $this->htmxHeaders());
    $this->submitForm(['name' => $this->account->getAccountName(), 'pass' => $this->account->passRaw], 'Log in');
    $this->assertSession()->pageTextContains('has not been activated or is blocked');

    $this->config('user.flood')->set('ip_limit', 0)->save();
    $this->drupalGet($this->route('nbs_extensions.netcdf_modal_login'), [], $this->htmxHeaders());
    $this->submitForm(['name' => $this->account->getAccountName(), 'pass' => $this->account->passRaw], 'Log in');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Login is temporarily blocked');
    $this->assertSession()->elementExists('css', '#nbs-netcdf-modal-content[role="alert"]');
    $this->drupalGet($this->route('nbs_extensions.netcdf_modal_request'));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Keeps the no-JavaScript login redirect and authenticated page usable.
   */
  public function testFullPageFallbackAndMissingEmail(): void {
    $this->drupalGet($this->route('nbs_extensions.netcdf_modal_login'));
    $this->submitForm(['name' => $this->account->getAccountName(), 'pass' => $this->account->passRaw], 'Log in');
    $this->assertSession()->addressEquals($this->route('nbs_extensions.netcdf_on_demand'));
    $this->assertSession()->fieldExists('confirm');

    $account = User::load($this->account->id());
    $account->setEmail('')->save();
    $this->drupalGet($this->route('nbs_extensions.netcdf_on_demand'));
    $this->submitForm(['confirm' => TRUE], 'Send request');
    $this->assertSession()->pageTextContains('Your account does not have an email address');
    $this->container->get('state')->resetCache();
    $this->assertSame(0, $this->container->get('state')->get('nbs_test.requests', 0));
  }

  /**
   * Authenticated fragments remain protected by core Form API CSRF tokens.
   */
  public function testAuthenticatedEntryAndCsrfProtection(): void {
    $this->drupalLogin($this->account);
    $this->drupalGet($this->route('nbs_extensions.netcdf_modal'), [], $this->htmxHeaders());
    $this->assertSession()->hiddenFieldValueEquals('form_id', 'nbs_extensions_netcdf_on_demand');
    $this->assertSession()->fieldNotExists('pass');
    $this->postCurrentForm(['confirm' => 1, 'form_token' => 'invalid-token']);
    $this->assertSession()->pageTextContains('The form has become outdated');
    $this->container->get('state')->resetCache();
    $this->assertSame(0, $this->container->get('state')->get('nbs_test.requests', 0));
  }

  /**
   * Core HTMX messages are rendered once and replaced on login continuation.
   */
  public function testCoreHtmxMessagesDoNotPersistAfterLogin(): void {
    $url = $this->route('nbs_extensions.netcdf_modal_login');
    $url->setOption('query', ['_wrapper_format' => 'drupal_htmx']);
    $this->drupalGet($url, [], $this->htmxHeaders());
    $this->postCurrentForm([
      'name' => $this->account->getAccountName(),
      'pass' => 'wrong',
    ], 'form', TRUE);
    $this->assertSession()->pageTextContains('Unrecognized username or password');
    $this->assertSession()->elementsCount('css', '[role="alert"]', 1);
    $this->assertSession()->elementNotExists('css', '#nbs-netcdf-modal-content [role="alert"]');
    $this->assertSession()->elementAttributeContains('css', 'form', 'data-hx-target', 'closest .metsis-metadata-dialog__body');
    $this->assertSession()->elementAttributeContains('css', 'form', 'data-hx-swap', 'innerHTML');
    $this->assertSession()->elementAttributeContains('css', '[data-hx-trigger]', 'data-hx-target', 'closest .metsis-metadata-dialog__body');

    $this->postCurrentForm([
      'name' => $this->account->getAccountName(),
      'pass' => $this->account->passRaw,
    ], 'form', TRUE);
    $this->assertSession()->statusCodeEquals(204);
    $this->assertSession()->responseHeaderEquals('HX-Trigger', 'nbsNetcdfAuthenticated');
    $url = $this->route('nbs_extensions.netcdf_modal_request');
    $url->setOption('query', ['_wrapper_format' => 'drupal_htmx']);
    $this->drupalGet($url, [], $this->htmxHeaders());
    $this->assertSession()->pageTextNotContains('Unrecognized username or password');
    $this->assertSession()->elementNotExists('css', '[role="alert"]');
    $this->assertSession()->fieldExists('confirm');
    $this->assertSession()->elementAttributeContains('css', 'form', 'data-hx-target', 'closest .metsis-metadata-dialog__body');
  }

  /**
   * Exercises forged posts, including a disabled button or invalid CSRF token.
   */
  private function postCurrentForm(array $overrides, string $selector = '#nbs-netcdf-on-demand-form', bool $htmx = FALSE): void {
    $form = $this->getSession()->getPage()->find('css', $selector);
    $parameters = [];
    foreach ($form->findAll('css', 'input[type="hidden"], input[type="submit"]') as $input) {
      $parameters[$input->getAttribute('name')] = $input->getValue();
    }
    $driver = $this->getSession()->getDriver();
    if (!$driver instanceof BrowserKitDriver) {
      throw new \LogicException('These HTTP assertions require the BrowserKit driver.');
    }
    $action = $form->getAttribute('action');
    if ($htmx) {
      $action .= '?_wrapper_format=drupal_htmx';
    }
    $driver->getClient()->request('POST', $action, $overrides + $parameters, [], $htmx ? ['HTTP_HX_REQUEST' => 'true'] : []);
  }

  /**
   * Returns a URL retaining the selected dataset.
   */
  private function route(string $name): Url {
    return Url::fromRoute($name, ['datasetId' => 'dataset-1']);
  }

  /**
   * Declares the core HTMX main-content request format.
   */
  private function htmxHeaders(): array {
    return ['HX-Request' => 'true'];
  }

}
