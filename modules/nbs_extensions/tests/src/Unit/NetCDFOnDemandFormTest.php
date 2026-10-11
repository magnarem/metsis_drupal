<?php

declare(strict_types=1);

namespace Drupal\Tests\nbs_extensions\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\metsis_drupal\Service\SolrConnectorProvider;
use Drupal\metsis_drupal\Service\SolrDocumentLoader;
use Drupal\metsis_drupal\Service\SolrQueryFactory;
use Drupal\nbs_extensions\Form\NetCDFOnDemandForm;
use Drupal\nbs_extensions\Service\NetCDFOnDemandService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests request guards without contacting Solr or the backend.
 */
#[CoversClass(NetCDFOnDemandForm::class)]
#[Group('nbs_extensions')]
final class NetCDFOnDemandFormTest extends UnitTestCase {

  /**
   * Builds a form whose backend must never receive an HTTP request.
   */
  private function form(bool $authenticated = TRUE, ?string $email = ''): NetCDFOnDemandForm {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAuthenticated')->willReturn($authenticated);
    $account->method('hasPermission')->with('access content')->willReturn(TRUE);
    $account->method('getEmail')->willReturn($email);
    $connector = new SolrConnectorProvider($this->createMock(EntityTypeManagerInterface::class));
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');
    $form = new NetCDFOnDemandForm(
      $account,
      new SolrDocumentLoader(new SolrQueryFactory($connector), $connector),
      new NetCDFOnDemandService($client, $this->createMock(ConfigFactoryInterface::class), new NullLogger()),
    );
    $stack = new RequestStack();
    $request = Request::create('/metsis/netcdf-on-demand/modal-request/dataset-1', 'POST');
    $request->headers->set('HX-Request', 'true');
    $stack->push($request);
    $form->setRequestStack($stack);
    $form->setStringTranslation($this->getStringTranslationStub());
    return $form;
  }

  /**
   * Guards direct submissions even outside route access checking.
   */
  public function testAnonymousCannotSubmit(): void {
    $this->expectException(AccessDeniedHttpException::class);
    $form = [];
    $this->form(FALSE)->submitForm($form, new FormState());
  }

  /**
   * Prevents a repeated submission of an already accepted form state.
   */
  public function testAcceptedRequestCannotBeResubmitted(): void {
    $state = (new FormState())->set('request_result', [
      'success' => TRUE,
      'message' => 'Accepted',
    ]);
    $form = [];
    $this->form(TRUE, 'user@example.test')->submitForm($form, $state);
    $this->assertSame(['success' => TRUE, 'message' => 'Accepted'], $state->get('request_result'));
  }

  /**
   * Keeps missing-email errors in a rebuilt, retryable modal form.
   */
  public function testMissingEmailRebuildsWithoutBackendRequest(): void {
    $state = new FormState();
    $form = [];
    $this->form()->submitForm($form, $state);
    $this->assertSame(['success' => FALSE, 'error' => 'missing_email'], $state->get('request_result'));
    $this->assertTrue($state->isRebuilding());
    $this->assertTrue($state->isRedirectDisabled());
  }

}
