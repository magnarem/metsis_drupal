<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use PHPUnit\Framework\TestCase;
use Drupal\metsis_drupal\Utility\WktHelper;

/**
 * Unit tests for envelope to polygon conversion.
 *
 * @group metsis_drupal
 */
final class EnvelopeToPolygonTest extends TestCase {

  /**
   * Test that a valid envelope converts correctly to polygon.
   */
  public function testValidEnvelopeConvertsToPolygon(): void {
    $env = 'ENVELOPE(5.0, 10.0, 62.0, 58.0)';
    $polygon = WktHelper::envelopeWktToPolygonWkt($env);
    $this->assertStringStartsWith('POLYGON ((', $polygon);
    $this->assertStringEndsWith('))', $polygon);
    // Expect lower-left, lower-right, upper-right, upper-left, lower-left.
    $expected = 'POLYGON ((5.000000 58.000000, 10.000000 58.000000, 10.000000 62.000000, 5.000000 62.000000, 5.000000 58.000000))';
    $this->assertSame($expected, $polygon);
  }

  /**
   * Test that an invalid envelope throws.
   */
  public function testInvalidEnvelopeThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    WktHelper::envelopeWktToPolygonWkt('ENVELOPE(-230, 190, 95, -95)');
  }

}
