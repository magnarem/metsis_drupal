<?php

declare(strict_types=1);

namespace Drupal\nbs_extensions\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Sends NetCDF on-demand requests to the configured backend.
 */
final class NetCDFOnDemandService {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Requests a NetCDF file from the backend.
   *
   * @param string $productId
   *   The product identifier expected by the backend.
   * @param string $email
   *   The authenticated user's email address.
   *
   * @return array{success: bool, message?: string, error?: string, status?: int}
   *   The outcome of the backend request.
   */
  public function request(string $productId, string $email): array {
    $endpoint = trim((string) $this->configFactory
      ->get('nbs_extensions.settings')
      ->get('netcdf_ondemand_service_endpoint'));
    $parts = parse_url($endpoint);
    if (
      $endpoint === ''
      || !is_array($parts)
      || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], TRUE)
      || empty($parts['host'])
    ) {
      return ['success' => FALSE, 'error' => 'not_configured'];
    }

    try {
      $response = $this->httpClient->request('POST', $endpoint, [
        'headers' => ['Accept' => 'application/json'],
        'json' => [
          'inputs' => [
            'email' => $email,
            'products' => [$productId],
          ],
        ],
        'http_errors' => FALSE,
        'timeout' => 30,
      ]);
    }
    catch (GuzzleException) {
      $this->logger->error('The NetCDF on-demand backend request failed.');
      return ['success' => FALSE, 'error' => 'request_failed'];
    }

    if ($response->getStatusCode() !== 200) {
      $this->logger->warning('The NetCDF on-demand backend returned HTTP @status.', [
        '@status' => $response->getStatusCode(),
      ]);
      return [
        'success' => FALSE,
        'error' => 'http_error',
        'status' => $response->getStatusCode(),
      ];
    }

    try {
      $data = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      $this->logger->error('The NetCDF on-demand backend returned invalid JSON.');
      return ['success' => FALSE, 'error' => 'invalid_response'];
    }

    $message = is_array($data) && is_scalar($data['value'] ?? NULL)
      ? trim((string) $data['value'])
      : '';
    if ($message === '') {
      $this->logger->error('The NetCDF on-demand backend response did not include a confirmation value.');
      return ['success' => FALSE, 'error' => 'invalid_response'];
    }

    return ['success' => TRUE, 'message' => $message];
  }

}
