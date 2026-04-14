<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\metsis_drupal\Service\MetVocabServiceInterface;

/**
 * Cron hook: keeps the Met vocabulary cache warm.
 */
class CronHook {

  /**
   * Constructs a CronHook.
   *
   * @param \Drupal\metsis_drupal\Service\MetVocabServiceInterface $vocabService
   *   The Met vocabulary service.
   */
  public function __construct(
    private readonly MetVocabServiceInterface $vocabService,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Warms the vocabulary cache when it is missing or expired. The service
   * respects the configured TTL and skips a rebuild when the cache is still
   * valid, so this only incurs the parsing cost once per TTL period.
   */
  #[Hook('cron')]
  public function warmVocabularyCache(): void {
    $this->vocabService->refresh(force: FALSE);
  }

}
