<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\metsis_drupal\Service\BokehPlotService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BokehPlotService.
 */
#[CoversClass(BokehPlotService::class)]
#[Group('metsis_drupal')]
final class BokehPlotServiceTest extends TestCase {

  /**
   * Ensures the default render parameter is added to requests.
   */
  #[Test]
  public function testFetchBokehPlotAddsRenderParamAndReturnsBody(): void {
    $mock_client = $this->createMock(ClientInterface::class);
    $mock_config = $this->createMock(Config::class);
    $mock_config_factory = $this->createMock(ConfigFactoryInterface::class);
    $mock_config_factory->method('get')
      ->with('metsis_drupal.settings')
      ->willReturn($mock_config);
    $mock_config->method('get')
      ->with('bokeh_plot_service_url')
      ->willReturn('http://example.com/api');

    $mock_response = new Response(200, [], 'plot-html');
    $mock_client->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        'http://example.com/api',
        $this->callback(function (array $options): bool {
          return isset($options['query']['render']) && $options['query']['render'] === 'false';
        })
      )
      ->willReturn($mock_response);

    $service = new BokehPlotService($mock_client, $mock_config_factory);

    $this->assertSame('plot-html', $service->fetchBokehPlot([]));
  }

  /**
   * Ensures an explicit render parameter is preserved.
   */
  #[Test]
  public function testFetchBokehPlotDoesNotOverrideRenderParam(): void {
    $mock_client = $this->createMock(ClientInterface::class);
    $mock_config = $this->createMock(Config::class);
    $mock_config_factory = $this->createMock(ConfigFactoryInterface::class);
    $mock_config_factory->method('get')
      ->with('metsis_drupal.settings')
      ->willReturn($mock_config);
    $mock_config->method('get')
      ->with('bokeh_plot_service_url')
      ->willReturn('http://example.com/api');

    $mock_response = new Response(200, [], 'plot-html');
    $mock_client->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        'http://example.com/api',
        $this->callback(function (array $options): bool {
          return isset($options['query']['render']) && $options['query']['render'] === TRUE;
        })
      )
      ->willReturn($mock_response);

    $service = new BokehPlotService($mock_client, $mock_config_factory);

    $this->assertSame('plot-html', $service->fetchBokehPlot(['render' => TRUE]));
  }

}
