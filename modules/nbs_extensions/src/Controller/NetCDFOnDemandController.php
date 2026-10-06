<?php

declare(strict_types=1);

namespace Drupal\nbs_extensions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the NetCDF on-demand request form.
 */
final class NetCDFOnDemandController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected readonly FormBuilderInterface $nbsFormBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('form_builder'));
  }

  /**
   * Returns the form for the requested dataset.
   *
   * @param string $datasetId
   *   The Solr document identifier.
   *
   * @return array
   *   The request form render array.
   */
  public function content(string $datasetId): array {
    return $this->nbsFormBuilder->getForm(
      'Drupal\nbs_extensions\Form\NetCDFOnDemandForm',
      $datasetId,
    );
  }

}
