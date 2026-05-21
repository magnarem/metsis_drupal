<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

/**
 * Markdown content detector using heuristic pattern scoring.
 *
 * Analyzes text for common markdown patterns (headers, lists, bold/italic,
 * links, code blocks, etc.) and returns TRUE if 2+ patterns are detected.
 * The threshold of 2+ reduces false positives while catching real markdown.
 */
final class MarkdownDetector implements MarkdownDetectorInterface {

  /**
   * Markdown pattern threshold for positive detection.
   *
   * @var int
   */
  private const PATTERN_THRESHOLD = 1;

  /**
   * {@inheritdoc}
   */
  public function detectMarkdown(string $text): bool {
    if (empty($text)) {
      return FALSE;
    }

    $pattern_count = 0;

    // Pattern 1: Headers (lines starting with 1-6 #)
    if (preg_match('/^#{1,6}\s/m', $text)) {
      $pattern_count++;
    }

    // Pattern 2: Unordered lists (lines with *, -, or +)
    if (preg_match('/^\s*[\*\-\+]\s/m', $text)) {
      $pattern_count++;
    }

    // Pattern 3: Ordered lists (numbered with . or ))
    if (preg_match('/^\s*\d+[.)]\s/m', $text)) {
      $pattern_count++;
    }

    // Pattern 4: Bold text (**...**)
    if (preg_match('/\*\*[^\*]+\*\*/i', $text)) {
      $pattern_count++;
    }

    // Pattern 5: Italic text (*...* or _..._)
    if (preg_match('/(?<!\*)\*[^\*]+\*(?!\*)|_[^_]+_/i', $text)) {
      $pattern_count++;
    }

    // Pattern 6: Markdown links [text](url)
    if (preg_match('/\[[^\]]+\]\([^\)]+\)/', $text)) {
      $pattern_count++;
    }

    // Pattern 7: Code fence (``` or ~~~ blocks)
    if (preg_match('/```|~~~/', $text)) {
      $pattern_count++;
    }

    // Pattern 8: Indented code blocks (4+ spaces at line start)
    if (preg_match('/^    [^\s]/m', $text)) {
      $pattern_count++;
    }

    // Pattern 9: Horizontal rules (3+ *, -, or _)
    if (preg_match('/^\s*(\*{3,}|\-{3,}|_{3,})\s*$/m', $text)) {
      $pattern_count++;
    }

    // Pattern 10: Strikethrough (~~...~~)
    if (preg_match('/~~[^~]+~~/', $text)) {
      $pattern_count++;
    }

    return $pattern_count >= self::PATTERN_THRESHOLD;
  }

}
