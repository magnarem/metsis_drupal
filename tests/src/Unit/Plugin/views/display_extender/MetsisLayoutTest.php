<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit\Plugin\views\display_extender;

use Drupal\metsis_drupal\Plugin\views\display_extender\MetsisLayout;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests safe CSS lengths and the shared default.
 */
final class MetsisLayoutTest extends UnitTestCase {

  /**
   * Tests permitted widths, zero rejection, and CSS injection rejection.
   */
  #[DataProvider('widths')]
  public function testWidths(string $width, bool $valid): void {
    self::assertSame($valid, MetsisLayout::isValidWidth($width));
  }

  /**
   * Provides CSS length cases.
   */
  public static function widths(): array {
    return [
      ['24rem', TRUE], ['20.5em', TRUE], ['320px', TRUE],
      ['.5rem', TRUE], ['0.25em', TRUE], ['0px', FALSE],
      ['0.0rem', FALSE], ['-20px', FALSE], ['24%', FALSE],
      ['24rem;color:red', FALSE], ["24rem\n", FALSE], ['', FALSE],
    ];
  }

}
