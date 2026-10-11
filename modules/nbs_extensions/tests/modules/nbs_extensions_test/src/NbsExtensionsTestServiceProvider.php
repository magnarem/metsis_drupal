<?php

declare(strict_types=1);

namespace Drupal\nbs_extensions_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Replaces external Solr access with deterministic test data.
 */
final class NbsExtensionsTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $container->getDefinition('nbs_extensions.netcdf_on_demand')
      ->replaceArgument(0, new Reference('nbs_extensions_test.http_client'));
    $container->getDefinition('metsis_drupal.solr_connector_provider')
      ->setFactory([TestSolrConnector::class, 'provider'])
      ->setArguments([new Reference('entity_type.manager')]);
  }

}
