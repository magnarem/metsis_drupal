<?php

declare(strict_types=1);

namespace Drupal\nbs_extensions\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Prevents browsers and intermediaries from storing account-specific forms.
 */
final class NetCDFResponseSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Apply after core's cache-header policy, including bare flood responses.
    return [KernelEvents::RESPONSE => ['onResponse', -100]];
  }

  /**
   * Marks all NetCDF responses private and non-storable.
   */
  public function onResponse(ResponseEvent $event): void {
    if (in_array($event->getRequest()->attributes->get('_route'), [
      'nbs_extensions.netcdf_on_demand',
      'nbs_extensions.netcdf_modal',
      'nbs_extensions.netcdf_modal_login',
      'nbs_extensions.netcdf_modal_request',
    ], TRUE)) {
      $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
    }
  }

}
