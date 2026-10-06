<?php

declare(strict_types=1);

namespace Drupal\Tests\nbs_extensions\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\nbs_extensions\Service\NetCDFOnDemandService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the NetCDF on-demand backend service.
 */
#[CoversClass(NetCDFOnDemandService::class)]
#[Group('nbs_extensions')]
final class NetCDFOnDemandServiceTest extends TestCase {

  /**
   * Tests a successful request and the backend payload.
   */
  #[Test]
  public function testRequestSendsExpectedPayload(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://example.com/netcdf',
        $this->callback(static function (array $options): bool {
          return ($options['json']['inputs']['email'] ?? NULL) === 'user@example.com'
            && ($options['json']['inputs']['products'] ?? NULL) === ['Product name']
            && ($options['http_errors'] ?? NULL) === FALSE;
        }),
      )
      ->willReturn(new Response(200, [], '{"value":"Request accepted"}'));

    $service = $this->createService($client, 'https://example.com/netcdf');

    self::assertSame(
      ['success' => TRUE, 'message' => 'Request accepted'],
      $service->request('Product name', 'user@example.com'),
    );
  }

  /**
   * Tests non-success status codes are returned as errors.
   */
  #[Test]
  public function testRequestReportsNon200Status(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->willReturn(new Response(503));

    $service = $this->createService($client, 'https://example.com/netcdf');

    self::assertSame(
      ['success' => FALSE, 'error' => 'http_error', 'status' => 503],
      $service->request('Product name', 'user@example.com'),
    );
  }

  /**
   * Tests missing endpoints do not issue backend requests.
   */
  #[Test]
  public function testRequestRejectsMissingEndpoint(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    $service = $this->createService($client, '');

    self::assertSame(
      ['success' => FALSE, 'error' => 'not_configured'],
      $service->request('Product name', 'user@example.com'),
    );
  }

  /**
   * Tests malformed backend responses do not appear as successful requests.
   */
  #[Test]
  public function testRequestRejectsInvalidResponse(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->willReturn(new Response(200, [], '{"unexpected":"response"}'));

    $service = $this->createService($client, 'https://example.com/netcdf');

    self::assertSame(
      ['success' => FALSE, 'error' => 'invalid_response'],
      $service->request('Product name', 'user@example.com'),
    );
  }

  /**
   * Tests backend connection failures are reported without leaking details.
   */
  #[Test]
  public function testRequestReportsConnectionFailure(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->willThrowException(new ConnectException(
        'connection failed',
        new Request('POST', 'https://example.com/netcdf'),
      ));

    $service = $this->createService($client, 'https://example.com/netcdf');

    self::assertSame(
      ['success' => FALSE, 'error' => 'request_failed'],
      $service->request('Product name', 'user@example.com'),
    );
  }

  /**
   * Creates a service with its endpoint configured.
   */
  private function createService(ClientInterface $client, string $endpoint): NetCDFOnDemandService {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->with('netcdf_ondemand_service_endpoint')
      ->willReturn($endpoint);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('nbs_extensions.settings')
      ->willReturn($config);

    return new NetCDFOnDemandService(
      $client,
      $configFactory,
      $this->createMock(LoggerInterface::class),
    );
  }

}
