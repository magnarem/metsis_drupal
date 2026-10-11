<?php

declare(strict_types=1);

namespace Drupal\nbs_extensions_test;

use Drupal\Core\State\StateInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Intercepts only the configured test backend's requests.
 */
final class TestBackend {

  /**
   * Constructs the middleware.
   */
  public function __construct(private readonly StateInterface $state) {}

  /**
   * Creates an isolated backend client with no network-capable handler.
   */
  public function client(): Client {
    $handler = ($this->__invoke())(static function (): never {
      throw new \LogicException('Unexpected backend URL in the NetCDF functional test.');
    });
    return new Client(['handler' => $handler]);
  }

  /**
   * Builds HTTP middleware with persistent request-count assertions.
   */
  public function __invoke(): callable {
    return function (callable $handler): callable {
      return function (RequestInterface $request, array $options) use ($handler) {
        if ($request->getUri()->getHost() !== 'nbs-backend.invalid') {
          return $handler($request, $options);
        }
        $this->state->set('nbs_test.requests', $this->state->get('nbs_test.requests', 0) + 1);
        $this->state->set('nbs_test.payload', json_decode((string) $request->getBody(), TRUE));
        return Create::promiseFor(new Response(
          $this->state->get('nbs_test.status', 200),
          ['Content-Type' => 'application/json'],
          '{"value":"Your test request was accepted."}',
        ));
      };
    };
  }

}
