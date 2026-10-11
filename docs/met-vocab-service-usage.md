# MetVocabService Usage Examples

Date: 2026-04-14

## Service ID and Interface

- Service ID: `metsis_drupal.met_vocab_service`
- Interface: `Drupal\metsis_drupal\Service\MetVocabServiceInterface`

## What It Returns

All concept lookups return a normalized structure:

```php
[
  'uri' => 'https://vocab.met.no/mmd/Use_Constraint/CC-BY-4.0',
  'pref_label' => 'CC-BY-4.0',
  'alt_labels' => ['Creative Commons Attribution 4.0 International', 'CC BY 4.0'],
  'definition' => 'This license lets others distribute, remix, adapt, and build upon your work...',
  'group_uri' => 'https://vocab.met.no/mmd/Use_Constraint',
  'group_label' => 'Use Constraint',
  'see_also' => ['https://creativecommons.org/licenses/by/4.0/'],
  'broader' => [],
]
```

Language fallback order is:

1. requested language (for example `nb`)
2. `en`
3. first available language

## Injecting The Service In A Class

```php
<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

use Drupal\metsis_drupal\Service\MetVocabServiceInterface;

final class ExampleConsumerService {

  public function __construct(
    private readonly MetVocabServiceInterface $metVocabService,
  ) {}

  public function getLicenseInfo(string $license): ?array {
    return $this->metVocabService->lookupByLabel('Use_Constraint', $license, 'en');
  }

}
```

## Example 1: Lookup By Label + Vocabulary

```php
$concept = $metVocabService->lookupByLabel('Use_Constraint', 'CC-BY-4.0', 'en');

if ($concept !== NULL) {
  $uri = $concept['uri'];
  $definition = $concept['definition'];
  $altLabels = $concept['alt_labels'];
}
```

## Example 2: Lookup By URI

```php
$concept = $metVocabService->lookupByUri(
  'https://vocab.met.no/mmd/Keywords_Vocabulary/GCMDSK',
  'en',
);
```

## Example 3: Get Parent Concept

```php
$parent = $metVocabService->getParent(
  'https://vocab.met.no/mmd/Some_Group/Some_Child_Concept',
  'en',
);

if ($parent !== NULL) {
  $parentLabel = $parent['pref_label'];
}
```

## Example 4: Get Group Metadata

```php
$group = $metVocabService->getGroup('Keywords_Vocabulary', 'en');

// Keys: uri, label, definition, member_count
```

## Example 5: Get All Concepts In A Group

```php
$concepts = $metVocabService->getGroupConcepts('Keywords_Vocabulary', 'en');

foreach ($concepts as $concept) {
  $label = $concept['pref_label'];
}
```

## Example 6: Cron Refresh (TTL-Aware)

```php
// Does not force rebuild if cache is still valid.
$metVocabService->refresh(FALSE);

// Force immediate rebuild and recache.
$metVocabService->refresh(TRUE);
```

## Drush Eval Quick Checks

```bash
drush php:eval "
$svc = \Drupal::service('metsis_drupal.met_vocab_service');
print_r($svc->lookupByLabel('Use_Constraint', 'CC-BY-4.0'));
"
```

```bash
drush php:eval "
$svc = \Drupal::service('metsis_drupal.met_vocab_service');
print_r($svc->getGroup('Keywords_Vocabulary'));
"
```

## Typical Search-Row Enrichment Pattern

```php
$keywords = $solr_doc['keywords_gcmd'] ?? [];
$typeInfo = $metVocabService->lookupByLabel('Keywords_Vocabulary', 'GCMDSK', 'en');

$build['keyword_group'] = [
  '#theme' => 'item_list',
  '#items' => $keywords,
  '#attributes' => ['class' => ['metsis-keywords']],
  '#prefix' => $typeInfo ? '<strong>' . $typeInfo['pref_label'] . ':</strong>' : '',
];
```

## Tooltip UI Snippets (HTML + CSS Classes)

The stylesheet `css/metsis_default_row_layout.css` now contains reusable tooltip classes:

- `.metsis-tooltip-group`
- `.metsis-tooltip-label`
- `.metsis-tooltip-trigger`
- `.metsis-tooltip-popover`

### Example A: Vocabulary label with info icon and popover

```html
<span class="metsis-tooltip-group">
  <span class="metsis-tooltip-label">GCMD Science Keywords</span>
  <button
    class="metsis-tooltip-trigger"
    type="button"
    popovertarget="kw-vocab-gcmd"
    popovertargetaction="toggle"
    aria-label="Show vocabulary information">
    i
  </button>
</span>

<div id="kw-vocab-gcmd" class="metsis-tooltip-popover" popover="auto" role="tooltip">
  <p>
    Keywords from the GCMD Earth Science Keywords vocabulary.
  </p>
  <p>
    <a href="https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords" target="_blank" rel="noopener noreferrer">
      Open vocabulary source
    </a>
  </p>
</div>
```

### Example B: Twig snippet in a search-row template

```twig
{% if vocab_info %}
  <span class="metsis-tooltip-group">
    <span class="metsis-tooltip-label">{{ vocab_info.pref_label }}</span>
    <button
      class="metsis-tooltip-trigger"
      type="button"
      popovertarget="vocab-{{ loop.index }}"
      popovertargetaction="toggle"
      aria-label="Show details for {{ vocab_info.pref_label }}">
      i
    </button>
  </span>

  <div id="vocab-{{ loop.index }}" class="metsis-tooltip-popover" popover="auto" role="tooltip">
    {% if vocab_info.definition %}
      <p>{{ vocab_info.definition }}</p>
    {% endif %}
    {% if vocab_info.see_also[0] is defined %}
      <p>
        <a href="{{ vocab_info.see_also[0] }}" target="_blank" rel="noopener noreferrer">
          More details
        </a>
      </p>
    {% endif %}
  </div>
{% endif %}
```

### Example C: Small inline fallback if popover is not available

```html
<span
  class="metsis-tooltip-group"
  title="Keywords from controlled vocabulary. Click info button for details where supported.">
  <span class="metsis-tooltip-label">Use Constraint</span>
  <button class="metsis-tooltip-trigger" type="button" aria-label="Info">i</button>
</span>
```

## Source And Cache Defaults

Current defaults are configured in `metsis_drupal.settings`:

- `vocab_source_path`: `vendor/metno/mmd/thesauri/mmd-vocabulary.ttl`
- `vocab_cache_ttl`: `86400` seconds (24 hours)
