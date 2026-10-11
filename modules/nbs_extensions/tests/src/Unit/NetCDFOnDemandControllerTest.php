<?php

declare(strict_types=1);

namespace Drupal\Tests\nbs_extensions\Unit;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\nbs_extensions\Controller\NetCDFOnDemandController;
use Drupal\nbs_extensions\Form\NetCDFOnDemandForm;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the NetCDF dialog's shared template contract.
 */
#[CoversClass(NetCDFOnDemandController::class)]
#[Group('nbs_extensions')]
final class NetCDFOnDemandControllerTest extends UnitTestCase {

  /**
   * Supplies a translated title without modifying the shared dialog template.
   */
  public function testModalTitleAndProtectedForm(): void {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $builder = $this->createMock(FormBuilderInterface::class);
    $builder->expects($this->once())->method('getForm')
      ->with(NetCDFOnDemandForm::class, 'dataset-1', TRUE)
      ->willReturn(['#type' => 'form']);
    $controller = new NetCDFOnDemandController($builder, $account);
    $controller->setStringTranslation($this->getStringTranslationStub());

    $build = $controller->modal('dataset-1');
    $this->assertSame('metsis_metadata_document_dialog', $build['#theme']);
    $this->assertSame('NetCDF on demand', (string) $build['#title']);
    $this->assertSame('#nbs-netcdf-modal-content', '#' . $build['#content']['#attributes']['id']);
    $this->assertSame(0, $build['#cache']['max-age']);
  }

}
