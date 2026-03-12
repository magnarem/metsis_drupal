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
 * Service for fetching Bokeh plot from fastAPI.
 *
 * This URL endpoint is read from the metsis configuration,
 * and calls the api with the url and feature_type parameters.
 *
 * If sucess, it returns the bokeh script for generating the plot,
 * or if it fails it returns NULL.
 */
final class BokehPlotService {
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
   * BokehPlotService constructor.
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
   * Example method to fetch a plot from the Bokeh API.
   *
   * @param array $params
   *   Query parameters for the bokeh request.
   *
   * @return string|null
   *   The bokeh script markup or NULL on failure.
   */
  public function fetchBokehPlot($params = []): ?string {
    // Get the endpoint from configuration.
    $config = $this->configFactory->get('metsis_drupal.settings');
    $endpoint = $config->get('bokeh_plot_service_url');
    // Ensure 'render' key is present in params, default to FALSE if missing.
    if (!array_key_exists('render', $params)) {
      $params['render'] = 'false';
    }
    if (Settings::get('environment') === 'development') {
      $this->getLogger()->debug('BokehPlotService: Using endpoint @endpoint with params: @params', [
        '@endpoint' => $endpoint,
        '@params' => json_encode($params),
      ]);
    }
    try {
      $response = $this->httpClient->request('GET', $endpoint, [
        'query' => $params,
      ]);
      return (string) $response->getBody();
    }
    catch (ClientException $e) {
      // Handle 4xx errors.
      $this->getLogger()->error('BokehPlotService: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
    catch (ServerException $e) {
      // Handle 5xx errors (e.g., 500)
      $errorBody = $e->getResponse()->getBody()->getContents();
      $this->getLogger()->error('BokehPlotService: Server error: @body', ['@body' => $errorBody]);
      return NULL;
    }
    catch (ConnectException $e) {
      // Handle connection issues.
      $this->getLogger()->error('BokehPlotService: Connection failed: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
    catch (\Exception $e) {
      // Catch any other unexpected exceptions.
      $this->getLogger()->error('BokehPlotService: Unexpected error: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

}
