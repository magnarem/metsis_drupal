<?php

declare(strict_types=1);

namespace Drupal\Tests\nbs_extensions\Unit;

use Drupal\nbs_extensions\EventSubscriber\NetCDFResponseSubscriber;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests the narrowly scoped non-storable response policy.
 */
#[CoversClass(NetCDFResponseSubscriber::class)]
#[Group('nbs_extensions')]
final class NetCDFResponseSubscriberTest extends UnitTestCase {

  /**
   * Applies private no-store headers to every NetCDF endpoint.
   */
  public function testFormsAreNotStored(): void {
    foreach ([
      'nbs_extensions.netcdf_modal',
      'nbs_extensions.netcdf_modal_login',
      'nbs_extensions.netcdf_modal_request',
      'nbs_extensions.netcdf_on_demand',
    ] as $route) {
      $request = Request::create('/');
      $request->attributes->set('_route', $route);
      $response = new Response();
      $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
      (new NetCDFResponseSubscriber())->onResponse($event);
      $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }
  }

  /**
   * Leaves other pages' existing cache policy untouched.
   */
  public function testOtherRoutesAreUnchanged(): void {
    $request = Request::create('/');
    $request->attributes->set('_route', 'user.login');
    $response = new Response('', 200, ['Cache-Control' => 'public, max-age=60']);
    $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    (new NetCDFResponseSubscriber())->onResponse($event);
    $this->assertSame('max-age=60, public', $response->headers->get('Cache-Control'));
  }

}
