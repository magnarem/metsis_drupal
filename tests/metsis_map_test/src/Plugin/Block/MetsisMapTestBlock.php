<?php

namespace Drupal\metsis_map_test\Plugin\Block;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a block that renders the Metsis Map.
 *
 * @Block(
 *   id = "metsis_map_test_block",
 *   admin_label = @Translation("METSIS Map Test Block"),
 *   provider_name = @Translation("metsis_map_test"),
 *   category = @Translation("METSIS"),
 * )
 * {@inheritdoc}
 */
class MetsisMapTestBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
      '#theme' => 'metsis_map_test_page',
      '#attached' => [
        'library' => [
          // 'metsis_map_test/metsis_map_test.behaviours',
          'metsis_drupal/metsis_map',
        ],
      ],
    ];
  }

}
