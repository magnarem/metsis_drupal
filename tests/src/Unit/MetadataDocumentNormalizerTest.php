<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\metsis_drupal\Service\MetadataDocumentNormalizer;
use Drupal\metsis_drupal\Service\MetVocabServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MetadataDocumentNormalizer.
 */
#[CoversClass(MetadataDocumentNormalizer::class)]
#[Group('metsis_drupal')]
final class MetadataDocumentNormalizerTest extends TestCase {

  /**
   * Met vocabulary service mock.
   *
   * @var \Drupal\metsis_drupal\Service\MetVocabServiceInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private MetVocabServiceInterface&MockObject $metVocabService;

  /**
   * Normalizer under test.
   */
  private MetadataDocumentNormalizer $normalizer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->metVocabService = $this->createMock(MetVocabServiceInterface::class);
    $this->normalizer = new MetadataDocumentNormalizer($this->metVocabService);
  }

  /**
   * Test that platform entries render correctly.
   *
   * Names, links, nested instruments, and vocab popover metadata.
   */
  #[Test]
  public function buildSectionsRendersPlatformAndInstrumentVocabularyMetadata(): void {
    $lookup_calls = [];
    $concept_map = [
      'Platform' => [
        'Sentinel-1C' => $this->createConcept(
          'https://vocab.met.no/mmd/Platform/Sentinel-1C',
          'Sentinel-1C',
          'Platform satellite concept',
          ['S1C'],
        ),
        'S1A' => $this->createConcept(
          'https://vocab.met.no/mmd/Platform/S1A',
          'S1A',
          'Platform satellite concept',
          ['Sentinel-1A'],
        ),
      ],
      'Instrument' => [
        'SAR-C' => $this->createConcept(
          'https://vocab.met.no/mmd/Instrument/SAR-C',
          'SAR-C',
          'Synthetic Aperture Radar instrument concept',
          ['Synthetic Aperture Radar (C-band)'],
        ),
      ],
      'Instrument_Modes' => [
        'IW' => $this->createConcept(
          'https://vocab.met.no/mmd/Instrument_Modes/IW',
          'IW',
          'Interferometric Wide mode',
        ),
      ],
      'Polarisation_Modes' => [
        'VV+VH' => $this->createConcept(
          'https://vocab.met.no/mmd/Polarisation_Modes/VV-VH',
          'VV+VH',
          'Dual-polarisation mode',
        ),
      ],
    ];

    $this->metVocabService->method('lookupByLabel')->willReturnCallback(
      function (string $collection_key, string $label) use (&$lookup_calls, $concept_map): ?array {
        $lookup_calls[] = [$collection_key, $label];

        return $concept_map[$collection_key][$label] ?? NULL;
      }
    );
    $this->metVocabService->method('lookupByUri')->willReturnCallback(
      function (string $uri) use (&$lookup_calls): ?array {
        $lookup_calls[] = ['uri', $uri];

        return NULL;
      }
    );

    $sections = $this->normalizer->buildSections([
      'platform_json' => [
        [
          'short_name' => 'Sentinel-1C',
          'long_name' => 'Sentinel-1C',
          'resource' => 'https://space.oscar.wmo.int/satellites/view/sentinel_1c',
        ],
        [
          'short_name' => 'S1A',
          'long_name' => 'Sentinel-1A',
          'resource' => 'https://space.oscar.wmo.int/satellites/view/sentinel_1a',
          'orbit_direction' => 'ascending',
          'orbit_relative' => '121',
          'orbit_absolute' => '62391',
          'instrument' => [
            'short_name' => 'SAR-C',
            'long_name' => 'Synthetic Aperture Radar (C-band)',
            'resource' => 'https://space.oscar.wmo.int/instruments/view/sar_c_sentinel_1',
            'mode' => 'IW',
            'polarisation' => 'VV+VH',
          ],
          'ancillary' => [
            'cloud_coverage' => '23.4',
            'scene_coverage' => '77',
            'timeliness' => 'NRT',
          ],
        ],
      ],
    ]);

    $this->assertCount(1, $sections);
    $platform_section = $sections[0];
    $this->assertSame('platform_json', $platform_section['field']);
    $this->assertSame('Platforms and instruments', $platform_section['title']);
    $this->assertArrayHasKey('platform_entries', $platform_section);
    $this->assertCount(2, $platform_section['platform_entries']);

    $first_platform = $platform_section['platform_entries'][0];
    $this->assertSame('Sentinel-1C', $first_platform['name']['text']);
    $this->assertSame('https://space.oscar.wmo.int/satellites/view/sentinel_1c', $first_platform['name']['resource_url']);
    $this->assertSame('platform-0-name', $first_platform['name']['popover_id']);
    $this->assertSame('https://vocab.met.no/mmd/Platform/Sentinel-1C', $first_platform['name']['vocabulary']['uri']);
    $this->assertSame(['S1C'], $first_platform['name']['vocabulary']['alt_labels']);
    $this->assertCount(0, $first_platform['details']);
    $this->assertCount(0, $first_platform['instruments']);
    $this->assertCount(0, $first_platform['ancillary']);

    $second_platform = $platform_section['platform_entries'][1];
    $this->assertSame('Sentinel-1A', $second_platform['name']['text']);
    $this->assertSame('https://space.oscar.wmo.int/satellites/view/sentinel_1a', $second_platform['name']['resource_url']);
    $this->assertSame('platform-1-name', $second_platform['name']['popover_id']);
    $this->assertSame('https://vocab.met.no/mmd/Platform/S1A', $second_platform['name']['vocabulary']['uri']);
    $this->assertSame(['Sentinel-1A'], $second_platform['name']['vocabulary']['alt_labels']);

    $this->assertSame('Orbit direction', $second_platform['details'][0]['label']);
    $this->assertSame('ascending', $second_platform['details'][0]['value']['text']);
    $this->assertNull($second_platform['details'][0]['value']['vocabulary']);

    $this->assertSame('Orbit relative', $second_platform['details'][1]['label']);
    $this->assertSame('121', $second_platform['details'][1]['value']['text']);

    $this->assertSame('Orbit absolute', $second_platform['details'][2]['label']);
    $this->assertSame('62391', $second_platform['details'][2]['value']['text']);

    $this->assertCount(1, $second_platform['instruments']);
    $instrument = $second_platform['instruments'][0];
    $this->assertSame('Synthetic Aperture Radar (C-band)', $instrument['name']['text']);
    $this->assertSame('https://space.oscar.wmo.int/instruments/view/sar_c_sentinel_1', $instrument['name']['resource_url']);
    $this->assertSame('platform-1-instrument-0-name', $instrument['name']['popover_id']);
    $this->assertSame('https://vocab.met.no/mmd/Instrument/SAR-C', $instrument['name']['vocabulary']['uri']);
    $this->assertSame(['Synthetic Aperture Radar (C-band)'], $instrument['name']['vocabulary']['alt_labels']);
    $this->assertSame('Mode', $instrument['details'][0]['label']);
    $this->assertSame('IW', $instrument['details'][0]['value']['text']);
    $this->assertSame('https://vocab.met.no/mmd/Instrument_Modes/IW', $instrument['details'][0]['value']['vocabulary']['uri']);
    $this->assertSame('Polarisation', $instrument['details'][1]['label']);
    $this->assertSame('VV+VH', $instrument['details'][1]['value']['text']);
    $this->assertSame('https://vocab.met.no/mmd/Polarisation_Modes/VV-VH', $instrument['details'][1]['value']['vocabulary']['uri']);

    $this->assertCount(3, $second_platform['ancillary']);
    $this->assertSame('Cloud coverage', $second_platform['ancillary'][0]['label']);
    $this->assertSame('23.4', $second_platform['ancillary'][0]['value']['text']);
    $this->assertNull($second_platform['ancillary'][0]['value']['vocabulary']);

    $this->assertSame('Scene coverage', $second_platform['ancillary'][1]['label']);
    $this->assertSame('77', $second_platform['ancillary'][1]['value']['text']);

    $this->assertSame('Timeliness', $second_platform['ancillary'][2]['label']);
    $this->assertSame('NRT', $second_platform['ancillary'][2]['value']['text']);

    $this->assertSame([
      ['Platform', 'Sentinel-1C'],
      ['Platform', 'Sentinel-1A'],
      ['Platform', 'S1A'],
      ['Instrument', 'Synthetic Aperture Radar (C-band)'],
      ['Instrument', 'SAR-C'],
      ['Instrument_Modes', 'IW'],
      ['Polarisation_Modes', 'VV+VH'],
    ], $lookup_calls);
  }

  /**
   * Create a minimal concept info array for test assertions.
   *
   * @param string $uri
   *   Concept URI.
   * @param string $pref_label
   *   Preferred label.
   * @param string $definition
   *   Concept definition.
   * @param string[] $alt_labels
   *   Alternative labels.
   *
   * @return array<string, mixed>
   *   Concept info payload.
   */
  private function createConcept(string $uri, string $pref_label, string $definition, array $alt_labels = []): array {
    return [
      'uri' => $uri,
      'pref_label' => $pref_label,
      'alt_labels' => $alt_labels,
      'definition' => $definition,
      'group_uri' => 'https://vocab.met.no/mmd/' . rawurlencode($pref_label),
      'group_label' => $pref_label,
      'see_also' => [],
      'broader' => [],
    ];
  }

}
