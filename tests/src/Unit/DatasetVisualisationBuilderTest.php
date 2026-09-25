<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\Core\GeneratedUrl;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\metsis_drupal\Service\DatasetVisualisationBuilder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for DatasetVisualisationBuilder.
 */
#[CoversClass(DatasetVisualisationBuilder::class)]
#[Group('metsis_drupal')]
final class DatasetVisualisationBuilderTest extends UnitTestCase {

  /**
   * The builder under test.
   */
  private DatasetVisualisationBuilder $builder;

  /**
   * URLs generated while building controls.
   *
   * @var array<string, string>
   */
  private array $generatedUrls = [
    'metsis_drupal.bokeh_plot' => '/services/bokeh-plot/get',
    'metsis_drupal.wms_htmx' => '/metsis/wms/htmx',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $url_generator = $this->createMock(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')
      ->willReturnCallback(function (string $route_name, array $parameters, array $options, bool $collect): string|GeneratedUrl {
        $url = $this->generatedUrls[$route_name];
        if ($route_name === 'metsis_drupal.bokeh_plot') {
          $url .= '?' . http_build_query($options['query']);
        }
        elseif ($route_name === 'metsis_drupal.wms_htmx') {
          $url = sprintf(
            '/metsis/wms/htmx/%s/%s',
            $parameters['id'],
            $parameters['mount_id'],
          );
        }
        if (!$collect) {
          return $url;
        }
        return (new GeneratedUrl())->setGeneratedUrl($url);
      });

    $this->builder = new DatasetVisualisationBuilder(
      $this->createTranslator(),
      $url_generator,
    );
  }

  /**
   * Tests that eligible OPeNDAP and WMS controls share one render structure.
   */
  #[Test]
  public function testBuildsPlotAndWmsVisualisations(): void {
    $build = $this->builder->build([
      'data_access_url_opendap' => ['https://example.com/data.nc'],
      'feature_type' => ['timeSeries'],
      'data_access_json' => [[
        [
          'type' => 'OGC WMS',
          'resource' => 'https://example.com/wms',
        ],
      ],
      ],
    ], 'landing:no.met.adc:test', 'no.met.adc:test', TRUE);

    $this->assertSame(
      ['metsis_drupal/metsis_visualisations'],
      $build['#attached']['library'],
    );
    $this->assertSame(
      'Visualise timeSeries',
      (string) $build['controls']['plot_trigger']['#value'],
    );
    $this->assertSame(
      'Visualise WMS',
      (string) $build['controls']['wms_trigger']['#value'],
    );
    $this->assertSame(
      'metsis-plot-container-landing-no-met-adc-test',
      $build['plot_container']['#attributes']['id'],
    );

    $this->assertSame(
      '/services/bokeh-plot/get?url=https%3A%2F%2Fexample.com%2Fdata.nc&feature_type=timeSeries',
      (string) $build['controls']['plot_trigger']['#attributes']['data-hx-get'],
    );
    $this->assertSame(
      '/metsis/wms/htmx/no.met.adc:test/metsis-wms-map-app-landing-no-met-adc-test',
      (string) $build['controls']['wms_trigger']['#attributes']['data-hx-get'],
    );
  }

  /**
   * Tests that incomplete or invalid data does not produce controls.
   */
  #[Test]
  public function testSkipsIneligibleVisualisations(): void {
    $this->assertSame([], $this->builder->build([
      'data_access_url_opendap' => 'not-a-url',
      'feature_type' => 'timeSeries',
      'data_access_json' => [
        [
          'type' => 'OGC WMS',
          'resource' => 'not-a-url',
        ],
      ],
    ], 'landing-test', 'no.met.adc:test', TRUE));
  }

  /**
   * Creates a translator that resolves placeholders for label assertions.
   */
  private function createTranslator(): TranslationInterface {
    $translator = $this->createMock(TranslationInterface::class);

    $translator->method('translate')
      ->willReturnCallback(
        function (string $string, array $args = [], array $options = []) use ($translator): TranslatableMarkup {
          return match ($string) {
            'Visualise @feature_type' => new TranslatableMarkup(
              'Visualise @feature_type',
              $args,
              $options,
              $translator,
            ),
            'Visualise WMS' => new TranslatableMarkup(
              'Visualise WMS',
              $args,
              $options,
              $translator,
            ),
            default => throw new \LogicException(sprintf('Unexpected source string: %s', $string)),
          };
        },
      );

    $translator->method('translateString')
      ->willReturnCallback(
        static fn(TranslatableMarkup $string): string => strtr(
          $string->getUntranslatedString(),
          $string->getArguments(),
        ),
      );

    return $translator;
  }

}
