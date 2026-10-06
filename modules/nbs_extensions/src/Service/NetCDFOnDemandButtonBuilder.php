<?php

declare(strict_types=1);

namespace Drupal\nbs_extensions\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\metsis_drupal\Utility\MetsisSolrUtilities;

/**
 * Builds a NetCDF on-demand action for supported search results.
 */
final class NetCDFOnDemandButtonBuilder {

  /**
   * Constructs the button builder.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds the action link when the Solr document has a supported product.
   *
   * @param array<string, mixed> $solrDocument
   *   Solr document from the search result.
   *
   * @return array<string, mixed>|null
   *   Button link render array, or NULL when the product is not supported.
   */
  public function build(array $solrDocument): ?array {
    $solr_id = MetsisSolrUtilities::firstValue($solrDocument['id'] ?? '');
    if (!MetsisSolrUtilities::isValidIdentifier($solr_id)) {
      return NULL;
    }

    $supported_products = $this->configFactory
      ->get('nbs_extensions.settings')
      ->get('netcdf_ondemand_products');
    if (!is_array($supported_products)) {
      return NULL;
    }

    if ($this->hasNetCdfFile($solrDocument['data_access_json'] ?? [])) {
      return NULL;
    }

    $product_types = $solrDocument['platform_instrument_product_type'] ?? [];
    $product_types = is_array($product_types) ? $product_types : [$product_types];
    foreach ($product_types as $product_type) {
      if (is_scalar($product_type) && in_array((string) $product_type, $supported_products, TRUE)) {
        $title = new TranslatableMarkup('Request "CF-NetCDF file"');
        return [
          '#type' => 'link',
          '#title' => $title,
          '#url' => Url::fromRoute('nbs_extensions.netcdf_on_demand', [
            'datasetId' => $solr_id,
          ]),
          '#attributes' => [
            'class' => ['button', 'button--small', 'nbs-netcdf-on-demand'],
            'title' => $title,
          ],
        ];
      }
    }

    return NULL;
  }

  /**
   * Determines whether the document already has an HTTP NetCDF file resource.
   *
   * @param mixed $dataAccess
   *   Parsed data_access_json field value.
   *
   * @return bool
   *   TRUE when an HTTP resource points to a .nc file.
   */
  private function hasNetCdfFile(mixed $dataAccess): bool {
    if (!is_array($dataAccess)) {
      return FALSE;
    }

    if (isset($dataAccess[0]) && is_array($dataAccess[0]) && array_is_list($dataAccess[0])) {
      $dataAccess = $dataAccess[0];
    }

    foreach ($dataAccess as $entry) {
      if (
        !is_array($entry)
        || strtolower(MetsisSolrUtilities::firstValue($entry['type'] ?? '')) !== 'http'
      ) {
        continue;
      }

      $resource = MetsisSolrUtilities::firstValue($entry['resource'] ?? '');
      if (str_contains($resource, "\0")) {
        continue;
      }
      $path = parse_url($resource, PHP_URL_PATH);
      if (is_string($path) && str_ends_with(strtolower($path), '.nc')) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
