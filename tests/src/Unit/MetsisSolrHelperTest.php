<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\metsis_drupal\Utility\MetsisSolrUtilities;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for MetsisSolrUtilities.
 */
#[CoversClass(MetsisSolrUtilities::class)]
#[Group('metsis_drupal')]
class MetsisSolrHelperTest extends TestCase {

  /**
   * Tests the toSolrId method.
   */
  #[Test]
  #[DataProvider('solrIdProvider')]
  public function testToSolrId(string $input, string $expected): void {
    $this->assertSame($expected, MetsisSolrUtilities::toSolrId($input));
  }

  /**
   * Data provider for testToSolrId.
   */
  public static function solrIdProvider(): array {
    return [
      ['abc:def', 'abc-def'],
      ['abc/def', 'abc-def'],
      ['abc.def', 'abc-def'],
      ['abc:def/ghi.jkl', 'abc-def-ghi-jkl'],
      [':/./', '----'],
      ['no_special_chars', 'no_special_chars'],
      ['', ''],
    ];
  }

}
