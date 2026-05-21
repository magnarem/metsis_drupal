<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit\Service;

use Drupal\metsis_drupal\Service\MarkdownDetector;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for the MarkdownDetector service.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(MarkdownDetector::class)]
#[\PHPUnit\Framework\Attributes\Group('metsis_drupal')]
class MarkdownDetectorTest extends UnitTestCase {

  /**
   * The markdown detector service under test.
   *
   * @var \Drupal\metsis_drupal\Service\MarkdownDetector
   */
  private MarkdownDetector $detector;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->detector = new MarkdownDetector();
  }

  /**
   * Test detection returns FALSE for empty string.
   */
  public function testDetectMarkdownEmptyString(): void {
    $result = $this->detector->detectMarkdown('');
    $this->assertFalse($result, 'Empty string should not be detected as markdown');
  }

  /**
   * Test detection returns FALSE for whitespace-only string.
   */
  public function testDetectMarkdownWhitespaceOnly(): void {
    $result = $this->detector->detectMarkdown('   \n  \t  ');
    $this->assertFalse($result, 'Whitespace-only string should not be detected as markdown');
  }

  /**
   * Test detection returns FALSE for plain HTML text.
   */
  public function testDetectMarkdownPlainHtml(): void {
    $html = '<p>This is a paragraph.</p><a href="http://example.com">Link</a>';
    $result = $this->detector->detectMarkdown($html);
    $this->assertFalse($result, 'Plain HTML should not be detected as markdown');
  }

  /**
   * Test detection returns FALSE for plain text without markdown patterns.
   */
  public function testDetectMarkdownPlainText(): void {
    $text = 'This is just plain text with no special formatting. It contains some sentences but nothing that looks like markdown syntax.';
    $result = $this->detector->detectMarkdown($text);
    $this->assertFalse($result, 'Plain text without markdown patterns should not be detected');
  }

  /**
   * Test detection with single markdown header.
   *
   * Should be FALSE - below threshold.
   */
  public function testDetectMarkdownSingleHeader(): void {
    $text = '# Header 1';
    $result = $this->detector->detectMarkdown($text);
    $this->assertFalse($result, 'Single pattern (header only) should not pass threshold of 2+');
  }

  /**
   * Test detection with header and list (should be TRUE).
   */
  public function testDetectMarkdownHeaderAndList(): void {
    $text = "# Main Header\n\n* Item 1\n* Item 2";
    $result = $this->detector->detectMarkdown($text);
    $this->assertTrue($result, 'Header + list should be detected as markdown (2 patterns)');
  }

  /**
   * Test detection with unordered list markers.
   *
   * Should be TRUE with another pattern.
   */
  public function testDetectMarkdownUnorderedLists(): void {
    $text = "- Item 1\n+ Item 2\n* Item 3\n\n**bold text**";
    $result = $this->detector->detectMarkdown($text);
    $this->assertTrue($result, 'Unordered list + bold should be detected as markdown');
  }

  /**
   * Test detection with ordered list (should be TRUE with another pattern).
   */
  public function testDetectMarkdownOrderedList(): void {
    $text = "1. First\n2. Second\n3. Third\n\n*italic text*";
    $result = $this->detector->detectMarkdown($text);
    $this->assertTrue($result, 'Ordered list + italic should be detected as markdown');
  }

  /**
   * Test detection with bold text patterns.
   */
  public function testDetectMarkdownBold(): void {
    $text = "This contains **bold text** and also __bold__ variations.";
    $result = $this->detector->detectMarkdown($text);
    $this->assertTrue($result, 'Multiple bold patterns should be detected as markdown');
  }

  /**
   * Test detection with italic text patterns.
   */
  public function testDetectMarkdownItalic(): void {
    $text = "This contains *italic* and _italic_ text variations.\n\n1. Ordered list";
    $result = $this->detector->detectMarkdown($text);
    $this->assertTrue($result, 'Italic + ordered list should be detected as markdown');
  }

  /**
   * Test detection with markdown links.
   */
  public function testDetectMarkdownLinks(): void {
    $text = "Check [this link](http://example.com) and [another](https://test.org) for more info.\n\n# Header";
    $result = $this->detector->detectMarkdown($text);
    $this->assertTrue($result, 'Markdown links + header should be detected');
  }

  /**
   * Test detection with code fence.
   */
  public function testDetectMarkdownCodeFence(): void {
    $text = "```php\n\$code = 'here';\n```\n\nAnd some text with **bold**.";
    $result = $this->detector->detectMarkdown($text);
    $this->assertTrue($result, 'Code fence + bold should be detected as markdown');
  }

  /**
   * Test detection with indented code block.
   */
  public function testDetectMarkdownIndentedCode(): void {
    $text = "# Code example\n\n    \$var = 'code';\n    echo \$var;";
    $result = $this->detector->detectMarkdown($text);
    $this->assertTrue($result, 'Header + indented code block should be detected as markdown');
  }

  /**
   * Test detection with horizontal rule.
   */
  public function testDetectMarkdownHorizontalRule(): void {
    $text = "Section 1\n\n---\n\nSection 2 with **bold**.";
    $result = $this->detector->detectMarkdown($text);
    $this->assertTrue($result, 'Horizontal rule + bold should be detected as markdown');
  }

  /**
   * Test detection with strikethrough.
   */
  public function testDetectMarkdownStrikethrough(): void {
    $text = "This is ~~strikethrough~~ text and also a [link](http://example.com).";
    $result = $this->detector->detectMarkdown($text);
    $this->assertTrue($result, 'Strikethrough + link should be detected as markdown');
  }

  /**
   * Test with real-world markdown example from user request.
   */
  public function testDetectMarkdownRealWorldAbstract(): void {
    $text = <<<'EOT'
This dataset contains results from water column samples.

Measured variables include
* inorganic nutrients (ammonium, nitrate plus nitrite, phosphate and silicate)
* chlorophyll a and phaeopigments
* particulate organic carbon and nitrogen

Samples were collected between the surface and 4090 m depth from January until June 2015.

### Quality

Methods
=======

### 1) Inorganic nutrients

Water samples for the inorganic nutrients nitrate and nitrite (NO3 + NO2) were collected in 20 mL scintillation vials. Concentrations were measured on a modified Scalar auto-analyzer.

**Measurement of nitrite in seawater**
The method is based on that nitrite reacts colorimetrically with aromatic amine.
EOT;
    $result = $this->detector->detectMarkdown($text);
    $this->assertTrue($result, 'Real-world markdown abstract should be detected');
  }

  /**
   * Test with mixed markdown and HTML (should detect markdown patterns).
   */
  public function testDetectMarkdownMixedWithHtml(): void {
    $text = "<p>Some HTML</p>\n\n# Markdown Header\n\n* List item\n\n<a href=\"link\">HTML link</a>";
    $result = $this->detector->detectMarkdown($text);
    $this->assertTrue($result, 'Mixed markdown and HTML with multiple patterns should be detected');
  }

  /**
   * Test case sensitivity of header detection.
   *
   * Note: markdown headers are case-sensitive.
   * (only lowercase or uppercase # works)
   */
  public function testDetectMarkdownHeaderCaseSensitivity(): void {
    $text = "# Valid header\n\n## Another header\n\n**Bold text**";
    $result = $this->detector->detectMarkdown($text);
    $this->assertTrue($result, 'Multiple headers + bold should be detected as markdown');
  }

  /**
   * Test detection with emphasis variations.
   */
  public function testDetectMarkdownEmphasisVariations(): void {
    $text = "Text with *emphasis*, **strong**, __strong__, and _emphasis_ all together.\n\n- List item";
    $result = $this->detector->detectMarkdown($text);
    $this->assertTrue($result, 'Multiple emphasis variations + list should be detected');
  }

}
