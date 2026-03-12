<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\metsis_drupal\Service\BokehPlotService;
use Symfony\Component\HttpFoundation\Request;
use Drupal\metsis_drupal\LoggerTrait;

/**
 * Returns responses for METSIS Search routes.
 */
class BokehPlotController extends ControllerBase {
  use LoggerTrait;

  /**
   * The Bokeh plot service.
   *
   * @var \Drupal\metsis_drupal\Service\BokehPlotService
   */
  protected BokehPlotService $bokehPlotService;

  /**
   * TSPlotController constructor.
   */
  public function __construct(BokehPlotService $bokehPlotService) {
    $this->bokehPlotService = $bokehPlotService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create($container) {
    return new static(
      $container->get('metsis_drupal.bokeh_plot_service')
    );
  }

  /**
   * HTMX endpoint for Bokeh plot rendering.
   *
   * Accepts 'url' and 'feature_type' as query parameters.
   */
  public function getPlot(Request $request): array {
    $url = $request->query->get('url');
    $featureType = $request->query->get('feature_type');
    $this->getLogger()->debug('BokehPlotController: @url and @type', [
      '@url' => $url,
      '@type' => $featureType,
    ]);

    // Validate required parameters.
    if (empty($url)) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('Missing required parameter: url'),
      ];
    }

    // Prepare parameters for the service.
    $params = [
      'feature_type' => $featureType,
      'url' => $url,
    ];

    $plotMarkup = $this->bokehPlotService->fetchBokehPlot($params);

    if ($plotMarkup === NULL) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('Failed to fetch bokeh plot markup.'),
      ];
    }

    // Return the bokeh plot markup.
    return [
      '#type' => 'markup',
      '#markup' => $plotMarkup,
      '#allowed_tags' => ['div', 'script'],
    ];
  }

}
