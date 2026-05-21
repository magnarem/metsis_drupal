<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

/**
 * Interface for detecting markdown content in text.
 */
interface MarkdownDetectorInterface {

  /**
   * Detect if a string contains markdown syntax.
   *
   * Uses heuristic scoring to identify common markdown patterns
   * (headers, lists, bold/italic, links, code blocks, etc.).
   * Returns TRUE if 2 or more markdown patterns are detected,
   * reducing false positives while catching real markdown content.
   *
   * @param string $text
   *   The text to analyze.
   *
   * @return bool
   *   TRUE if markdown patterns are detected, FALSE otherwise.
   */
  public function detectMarkdown(string $text): bool;

}
