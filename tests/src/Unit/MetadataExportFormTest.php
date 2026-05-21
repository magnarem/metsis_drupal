<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\metsis_drupal\Form\MetadataExportForm;
use Drupal\metsis_drupal\Service\MetadataExportService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MetadataExportForm.
 */
#[CoversClass(MetadataExportForm::class)]
#[Group('metsis_drupal')]
final class MetadataExportFormTest extends TestCase {

  /**
   * Form under test.
   *
   * @var \Drupal\metsis_drupal\Form\MetadataExportForm
   */
  protected MetadataExportForm $form;

  /**
   * Mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Mocked metadata export service.
   *
   * @var \Drupal\metsis_drupal\Service\MetadataExportService
   */
  protected MetadataExportService $metadataExportService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $metadata_export_config = $this->createMock(ImmutableConfig::class);
    $this->configFactory->method('get')
      ->with('metsis_drupal.metadata_export')
      ->willReturn($metadata_export_config);

    // Since MetadataExportService is final, we can't mock it.
    // Create a real instance because submitForm() error branches do not invoke
    // the service methods that reach Drupal storage.
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);

    $this->metadataExportService = new MetadataExportService(
      $this->configFactory,
      $entity_type_manager
    );

    $this->form = new MetadataExportForm(
      $this->metadataExportService,
      $this->configFactory
    );

    // Avoid \Drupal::translation() static container access in unit tests.
    $string_translation = $this->createMock(TranslationInterface::class);
    $string_translation->method('translate')
      ->willReturnCallback(static fn (string $string, array $args = []): string => strtr($string, $args));
    $this->form->setStringTranslation($string_translation);
  }

  /**
   * Test form ID is correct.
   */
  #[Test]
  public function testGetFormId(): void {
    $this->assertSame('metsis_drupal.metadata_export.form', $this->form->getFormId());
  }

  /**
   * Test submit handler with empty ID.
   */
  #[Test]
  public function testSubmitFormWithEmptyId(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->setValues([
      'solr_id' => '',
      'list' => 'mmd',
    ]);

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addError');
    $this->form->setMessenger($messenger);

    $this->form->submitForm($form, $form_state);
  }

  /**
   * Test submit handler with invalid ID.
   */
  #[Test]
  public function testSubmitFormWithInvalidId(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->setValues([
      'solr_id' => 'invalid@id!',
      'list' => 'mmd',
    ]);

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addError');
    $this->form->setMessenger($messenger);

    $this->form->submitForm($form, $form_state);
  }

  /**
   * Test submit handler with missing export type.
   */
  #[Test]
  public function testSubmitFormWithMissingExportType(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->setValues([
      'solr_id' => 'valid-id',
      'list' => '',
    ]);

    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addError');
    $this->form->setMessenger($messenger);

    $this->form->submitForm($form, $form_state);
  }

}
