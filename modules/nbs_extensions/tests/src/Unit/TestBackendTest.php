<?php

declare(strict_types=1);

namespace Drupal\Tests\nbs_extensions\Unit;

use Drupal\Core\State\StateInterface;
use Drupal\nbs_extensions_test\TestBackend;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the backend fixture's fail-closed network isolation.
 */
#[CoversClass(TestBackend::class)]
#[Group('nbs_extensions')]
final class TestBackendTest extends UnitTestCase {

  /**
   * Unknown external backend POSTs cannot reach a network handler.
   */
  public function testUnknownBackendIsRejected(): void {
    $fixture = new TestBackend($this->createMock(StateInterface::class));
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Unexpected backend URL');
    $fixture->client()->request('POST', 'https://unexpected-backend.invalid/request');
  }

}
