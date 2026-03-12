<?php

namespace Drupal\metsis_components_tests\Plugin\Block;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a block that renders the Metsis Map.
 *
 * @Block(
 *   id = "metsis_components_tests_block",
 *   admin_label = @Translation("METSIS Map Test Block"),
 *   provider_name = @Translation("metsis_components_tests"),
 *   category = @Translation("METSIS"),
 * )
 * {@inheritdoc}
 */
class MetsisComponentsTestsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#theme' => 'metsis_components_tests_page',
      '#attached' => [
        'library' => [
          // 'metsis_components_tests/metsis_components_tests.behaviours',
          'metsis_drupal/metsis_map',
        ],
      ],
    ];
  }

}
