<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

use Drupal\Core\Site\Settings;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\ClientInterface;
use Drupal\metsis_drupal\LoggerTrait;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for looking up featureType from OPeNDAP via fastAPI.
 *
 * This URL endpoint is read from the metsis configuration,
 * and calls the api with the url.
 *
 * The endpoint uses a short python code to try to open the dataset.
 * If fails, 423 error code are returned.
 *
 * When sucess the feature_type from the ds.attrs dict are returned.
 */
class FeatureTypeLookupService {
  use LoggerTrait;
  /**
   * The HTTP client for making API requests.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * FeatureTypeLookupService constructor.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
  }

  /**
   * Lookup featureType via FastAPI.
   *
   * @param string $url
   *   Query parameters for the bokeh request.
   *
   * @return string|null
   *   The bokeh script markup or NULL on failure.
   */
  public function lookup($url): ?string {
    // Get the endpoint from configuration.
    $config = $this->configFactory->get('metsis_drupal.settings');
    $endpoint = $config->get('feature_type_lookup_service');
    // Ensure 'render' key is present in params, default to FALSE if missing.
    if (Settings::get('environment') === 'development') {
      $this->getLogger()->debug('FeatureTypeLookupService: Using endpoint @endpoint with url: @url', [
        '@endpoint' => $endpoint,
        '@url' => $url,
      ]);
    }
    try {
      $response = $this->httpClient->request('GET', $endpoint, [
        'query' => ['url' => $url],
      ]);
      $feature_type = $response->getBody()->getContents();
      $this->getLogger()->debug("Got feature type: @type", ['@type' => $feature_type]);
      return json_decode($feature_type, TRUE)['feature_type'];
    }
    catch (ClientException $e) {
      // Handle 4xx errors.
      if ($e->getResponse()->getStatusCode() === 423) {
        $this->getLogger()->warning('Invalid OPeNDAP featureTypeLookup url: @url', ['@url' => $url]);
      }
      else {
        $this->getLogger()->error('FeatureTypeLookupService: @message', ['@message' => $e->getMessage()]);
      }
      return NULL;
    }
    catch (ServerException $e) {
      // Handle 5xx errors (e.g., 500)
      $errorBody = $e->getResponse()->getBody()->getContents();
      $this->getLogger()->error('FeatureTypeLookupService: Server error: @body', ['@body' => $errorBody]);
      return NULL;
    }
    catch (ConnectException $e) {
      // Handle connection issues.
      $this->getLogger()->error('FeatureTypeLookupService: Connection failed: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
    catch (\Exception $e) {
      // Catch any other unexpected exceptions.
      $this->getLogger()->error('FeatureTypeLookupService: Unexpected error: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

}
