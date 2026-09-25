<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Service;

use Drupal\Core\Htmx\Htmx;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;

/**
 * Builds inline dataset visualisation controls.
 */
final class DatasetVisualisationBuilder {
  use StringTranslationTrait;

  /**
   * Constructs the visualisation builder.
   */
  public function __construct(
    TranslationInterface $string_translation,
    private readonly UrlGeneratorInterface $urlGenerator,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds eligible OPeNDAP and WMS visualisations for a Solr document.
   *
   * @param array<string, mixed> $document
   *   The Solr document.
   * @param string $scope_id
   *   A value used to create page-unique element IDs.
   * @param string $metadata_identifier
   *   The metadata identifier used by the WMS endpoint.
   * @param bool $use_visualise_label
   *   Whether the OPeNDAP button label should use "Visualise".
   *
   * @return array<string, mixed>
   *   Render array containing controls and their inline targets.
   */
  public function build(
    array $document,
    string $scope_id,
    string $metadata_identifier,
    bool $use_visualise_label = FALSE,
  ): array {
    $scope_id = $this->sanitizeIdValue($scope_id);
    $build = [
      '#attached' => [
        'library' => ['metsis_drupal/metsis_visualisations'],
      ],
      'controls' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['metsis-visualisation-controls'],
        ],
      ],
    ];

    $this->buildPlot($build, $document, $scope_id, $use_visualise_label);
    $this->buildWms($build, $document, $scope_id, $metadata_identifier);

    if (count($build['controls']) === 2) {
      return [];
    }

    return $build;
  }

  /**
   * Adds the OPeNDAP/Bokeh plot control when required fields are available.
   */
  private function buildPlot(
    array &$build,
    array $document,
    string $scope_id,
    bool $use_visualise_label,
  ): void {
    $opendap_url = $this->firstString($document['data_access_url_opendap'] ?? NULL);
    $feature_type = $this->firstString($document['feature_type'] ?? NULL);

    if ($opendap_url === '' || $feature_type === '' || filter_var($opendap_url, FILTER_VALIDATE_URL) === FALSE) {
      return;
    }

    $trigger_id = 'metsis-plot-trigger-' . $scope_id;
    $container_id = 'metsis-plot-container-' . $scope_id;
    $spinner_id = 'metsis-plot-spinner-' . $scope_id;
    $target_id = 'metsis-plot-target-' . $scope_id;
    $plot_url = Url::fromRoute('metsis_drupal.bokeh_plot', [], [
      'query' => [
        'url' => $opendap_url,
        'feature_type' => $feature_type,
      ],
    ])->setUrlGenerator($this->urlGenerator);
    $button_label = $use_visualise_label
      ? $this->t('Visualise @feature', ['@feature' => $feature_type])
      : $this->t('Plot @feature', ['@feature' => $feature_type]);

    $button = [
      '#type' => 'button',
      '#value' => $button_label,
      '#attributes' => [
        'id' => $trigger_id,
        'type' => 'button',
        'class' => ['metsis-plot-trigger', 'button--secondary'],
        'aria-controls' => $container_id,
        'aria-expanded' => 'false',
        'data-label-closed' => (string) $button_label,
        'data-label-open' => (string) $this->t('Close visualisation ×'),
        'data-plot-spinner' => $spinner_id,
        'data-plot-target' => $target_id,
      ],
    ];
    (new Htmx())
      ->get($plot_url)
      ->onlyMainContent()
      ->trigger('metsis:loadPlot')
      ->target('#' . $target_id)
      ->swap('innerHTML')
      ->on('htmx:beforeRequest', 'Drupal.metsis.rowPlot.beforeRequest(this);')
      ->on('htmx:afterRequest', 'Drupal.metsis.rowPlot.afterRequest(this);')
      ->on('htmx:responseError', 'Drupal.metsis.rowPlot.onError(this);')
      ->applyTo($button);
    $build['controls']['plot_trigger'] = $button;

    $build['plot_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $container_id,
        'class' => ['metsis-plot-container'],
      ],
      'plot_close' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => '×',
        '#attributes' => [
          'type' => 'button',
          'class' => ['metsis-close-button', 'metsis-plot-close'],
          'aria-label' => (string) $this->t('Close visualisation'),
          'data-plot-trigger' => $trigger_id,
        ],
      ],
      'spinner' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => $spinner_id,
          'class' => ['metsis-plot-spinner', 'hidden'],
          'aria-hidden' => 'true',
        ],
        '#allowed_tags' => ['div', 'svg'],
        'icon' => [
          '#type' => 'icon',
          '#pack_id' => 'metsis_drupal_spinners',
          '#icon_id' => 'puff',
          '#settings' => [
            'stroke' => '#0074D9',
            'height' => '48',
            'width' => '48',
          ],
        ],
      ],
      'plot_target' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => $target_id,
          'class' => ['metsis-plot-target'],
        ],
        '#allowed_tags' => ['div', 'script'],
      ],
    ];

    (new Htmx())
      ->swapOob('innerHTML')
      ->applyTo($build['plot_container']['plot_target']);
  }

  /**
   * Adds the inline WMS control when the document contains a valid endpoint.
   */
  private function buildWms(
    array &$build,
    array $document,
    string $scope_id,
    string $identifier,
  ): void {
    if ($identifier === '' || !$this->hasWmsEndpoint($document['data_access_json'] ?? [])) {
      return;
    }

    $mount_id = 'metsis-wms-map-app-' . $scope_id;
    $target_id = 'metsis-wms-target-' . $scope_id;
    $spinner_id = 'metsis-wms-spinner-' . $scope_id;
    $wms_url = Url::fromRoute('metsis_drupal.wms_htmx', [
      'id' => $identifier,
      'mount_id' => $mount_id,
    ])->setUrlGenerator($this->urlGenerator);
    $button = [
      '#type' => 'button',
      '#value' => $this->t('Visualise WMS'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['metsis-wms-trigger', 'button--secondary'],
        'aria-controls' => $target_id,
        'aria-expanded' => 'false',
      ],
    ];

    (new Htmx())
      ->get($wms_url)
      ->onlyMainContent()
      ->target('#' . $target_id)
      ->swap('innerHTML')
      ->indicator('#' . $spinner_id)
      ->applyTo($button);
    $build['controls']['wms_trigger'] = $button;

    $build['wms_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'metsis-wms-container-' . $scope_id,
        'class' => ['metsis-wms-container'],
      ],
      'spinner' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => $spinner_id,
          'class' => ['htmx-indicator', 'metsis-wms-spinner'],
          'aria-hidden' => 'true',
        ],
        'icon' => [
          '#type' => 'icon',
          '#pack_id' => 'metsis_drupal_spinners',
          '#icon_id' => 'puff',
          '#settings' => [
            'stroke' => '#0074D9',
            'height' => '48',
            'width' => '48',
          ],
        ],
      ],
      'target' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => $target_id,
          'class' => ['metsis-wms-target'],
        ],
      ],
    ];
  }

  /**
   * Determines whether data access contains a valid OGC WMS endpoint.
   */
  private function hasWmsEndpoint(mixed $data_access): bool {
    foreach ($this->extractDataAccessRows($data_access) as $entry) {
      if (
        strtoupper(trim((string) ($entry['type'] ?? ''))) === 'OGC WMS'
        && $this->isValidUrl($entry['resource'] ?? NULL)
      ) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Normalizes the nested data_access_json shapes returned by Solr.
   *
   * @return array<int, array<string, mixed>>
   *   Data access entries.
   */
  private function extractDataAccessRows(mixed $value): array {
    if (!is_array($value) || $value === [] || !array_is_list($value)) {
      return [];
    }

    $first = reset($value);
    if (is_array($first) && array_is_list($first)) {
      $value = $first;
    }

    return array_values(array_filter($value, 'is_array'));
  }

  /**
   * Returns the first non-empty scalar string.
   */
  private function firstString(mixed $value): string {
    if (is_array($value)) {
      foreach ($value as $item) {
        $string = $this->firstString($item);
        if ($string !== '') {
          return $string;
        }
      }
      return '';
    }

    return is_scalar($value) ? trim((string) $value) : '';
  }

  /**
   * Checks whether a value contains a valid absolute URL.
   */
  private function isValidUrl(mixed $value): bool {
    return filter_var($this->firstString($value), FILTER_VALIDATE_URL) !== FALSE;
  }

  /**
   * Builds a safe value for use in element IDs and CSS selectors.
   */
  private function sanitizeIdValue(string $value): string {
    $sanitized = trim(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $value) ?? '', '-_');
    return $sanitized !== '' ? $sanitized : 'id-' . substr(md5($value), 0, 8);
  }

}
