<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Solarium\Core\Client\Adapter\AdapterHelper;
use Solarium\Core\Event\Events as SolariumEvents;
use Solarium\Core\Event\PostExecuteRequest;
use Solarium\Core\Event\PreExecuteRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

/**
 * Profiles raw Solarium request execution time.
 */
final class SolariumRequestTimingSubscriber implements EventSubscriberInterface {

  /**
   * Profiler logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $profilerLogger;

  /**
   * Per-request timers keyed by Solarium request object hash.
   *
   * @var array<string, int>
   */
  private array $timers = [];

  /**
   * Constructs a SolariumRequestTimingSubscriber.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->profilerLogger = $logger_factory->get('metsis_row_profiler');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SolariumEvents::PRE_EXECUTE_REQUEST => 'preExecuteRequest',
      SolariumEvents::POST_EXECUTE_REQUEST => 'postExecuteRequest',
    ];
  }

  /**
   * Start a wall-clock timer right before Solarium dispatches the request.
   */
  public function preExecuteRequest(PreExecuteRequest $event): void {
    $request = $event->getRequest();
    if ($request->getHandler() !== 'select') {
      return;
    }

    $this->timers[spl_object_hash($request)] = hrtime(TRUE);
  }

  /**
   * Log Solarium wall time and response metadata after request completion.
   */
  public function postExecuteRequest(PostExecuteRequest $event): void {
    $request = $event->getRequest();
    if ($request->getHandler() !== 'select') {
      return;
    }

    $request_key = spl_object_hash($request);
    $elapsed_ms = 0.0;
    if (isset($this->timers[$request_key])) {
      $elapsed_ms = (hrtime(TRUE) - $this->timers[$request_key]) / 1e6;
      unset($this->timers[$request_key]);
    }

    $response = $event->getResponse();
    $response_body = (string) $response->getBody();
    $response_bytes = strlen($response_body);

    $solr_qtime = 'n/a';
    $decoded = json_decode($response_body, TRUE);
    if (is_array($decoded) && isset($decoded['responseHeader']['QTime'])) {
      $solr_qtime = (string) $decoded['responseHeader']['QTime'];
    }
    elseif (preg_match('/"QTime"\s*:\s*([0-9]+)/', $response_body, $matches) === 1) {
      $solr_qtime = (string) $matches[1];
    }

    $headers = $response->getHeaders();
    $content_type = 'n/a';
    if (isset($headers['content-type'])) {
      $content_type = is_array($headers['content-type'])
        ? implode(',', $headers['content-type'])
        : (string) $headers['content-type'];
    }

    $uri = AdapterHelper::buildUri($request, $event->getEndpoint());

    $this->profilerLogger->debug(
      'Solarium HTTP: elapsed=@elapsed ms | qtime=@qtime ms | bytes=@bytes | method=@method | handler=@handler | content_type=@ctype | uri=@uri',
      [
        '@elapsed' => round($elapsed_ms, 1),
        '@qtime' => $solr_qtime,
        '@bytes' => $response_bytes,
        '@method' => $request->getMethod(),
        '@handler' => $request->getHandler(),
        '@ctype' => $content_type,
        '@uri' => $uri,
      ]
    );
  }

}
