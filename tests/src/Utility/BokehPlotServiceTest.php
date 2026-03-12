<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Tests\Utility;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Config;
use Drupal\metsis_drupal\Service\BokehPlotService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for BokehPlotService.
 */
#[Group('metsis_drupal')]
class BokehPlotServiceTest extends TestCase {

  /**
   * Unit test to ensure 'render' param is added and response is returned.
   */
  #[Test]
  public function testFetchBokehPlotAddsRenderParamAndReturnsBody() {
    $mockClient = $this->createMock(ClientInterface::class);
    $mockConfig = $this->createMock(Config::class);
    $mockConfigFactory = $this->createMock(ConfigFactoryInterface::class);
    $mockConfigFactory->method('get')
      ->with('metsis_drupal.settings')
      ->willReturn($mockConfig);
    $mockConfig->method('get')
      ->with('bokeh_plot_service_url')
      ->willReturn('http://example.com/api');

    $mockResponse = new Response(200, [], 'plot-html');
    $mockClient->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        'http://example.com/api',
        $this->callback(function ($options) {
          // Should have 'render' => 'false' in query.
          return isset($options['query']['render']) && $options['query']['render'] === 'false';
        })
      )
      ->willReturn($mockResponse);

    $service = new BokehPlotService($mockClient, $mockConfigFactory);
    $result = $service->fetchBokehPlot([]);
    $this->assertEquals('plot-html', $result);
  }

  /**
   * Unit test to ensure that if 'render' param is already set.
   */
  #[Test]
  public function testFetchBokehPlotDoesNotOverrideRenderParam() {
    $mockClient = $this->createMock(ClientInterface::class);
    $mockConfig = $this->createMock(Config::class);
    $mockConfigFactory = $this->createMock(ConfigFactoryInterface::class);
    $mockConfigFactory->method('get')
      ->with('metsis_drupal.settings')
      ->willReturn($mockConfig);
    $mockConfig->method('get')
      ->with('bokeh_plot_service_url')
      ->willReturn('http://example.com/api');

    $mockResponse = new Response(200, [], 'plot-html');
    $mockClient->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        'http://example.com/api',
        $this->callback(function ($options) {
          // Should keep 'render' => TRUE.
          return isset($options['query']['render']) && $options['query']['render'] === TRUE;
        })
      )
      ->willReturn($mockResponse);

    $service = new BokehPlotService($mockClient, $mockConfigFactory);
    $result = $service->fetchBokehPlot(['render' => TRUE]);
    $this->assertEquals('plot-html', $result);
  }

}
