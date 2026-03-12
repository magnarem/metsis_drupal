<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Functional test for BokehPlotService using a real endpoint.
 */
#[Group('metsis_drupal')]
#[RunTestsInSeparateProcesses]
class BokehPlotServiceFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'editor',
    'search_api',
    'search_api_solr',
    'metsis_drupal',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'olivero';


  /**
   * The BokehPlotService to test.
   *
   * @var \Drupal\metsis_drupal\Service\BokehPlotService
   */
  protected $bokehPlotService;

  /**
   * Set up the test environment and inject the BokehPlotService.
   */
  protected function setUp(): void {
    parent::setUp();
    $container = \Drupal::getContainer();
    $this->bokehPlotService = $container->get('metsis_drupal.bokeh_plot_service');
  }

  /**
   * Test fetching a plot from a real endpoint.
   */
  #[Test]
  public function testFetchBokehPlotRealEndpoint() {
    // Replace with a real, public endpoint for actual testing.
    $params = ['foo' => 'bar'];
    $result = $this->bokehPlotService->fetchBokehPlot($params);
    $this->assertNotNull($result, 'Should get a response from the endpoint');
    $this->assertStringContainsString('httpbin', $result);
  }

}
