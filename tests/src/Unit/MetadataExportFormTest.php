<?php

declare(strict_types=1);

namespace Drupal\Tests\metsis_drupal\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\metsis_drupal\Form\MetadataExportForm;
use Drupal\metsis_drupal\Service\MetadataExportService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MetadataExportForm.
 */
#[CoversClass(MetadataExportForm::class)]
#[Group('metsis_drupal')]
class MetadataExportFormTest extends TestCase {

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

    // Since MetadataExportService is final, we can't mock it.
    // Create a real instance with mocked dependencies.
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);

    // Mock the storage to prevent actual DB calls.
    $storage = $this->createMock(EntityStorageInterface::class);
    $entity_type_manager->method('getStorage')->willReturn($storage);
    $storage->method('load')->willReturn(NULL);

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
   * Test form builds without ID parameter.
   */
  #[Test]
  public function testBuildFormWithoutId(): void {
    $form_state = new FormState();
    $form = $this->form->buildForm([], $form_state, '');

    $this->assertArrayHasKey('#prefix', $form);
    $this->assertArrayHasKey('#suffix', $form);
    $this->assertArrayHasKey('solr_id', $form);
    $this->assertSame('', $form['solr_id']['#value']);

    // Should have markup explaining missing ID.
    $this->assertArrayHasKey('export', $form);
    $this->assertSame('markup', $form['export']['#type']);
  }

  /**
   * Test form rejects invalid ID with special characters.
   */
  #[Test]
  public function testBuildFormInvalidIdWithSpecialChars(): void {
    $form_state = new FormState();
    $id = 'invalid@dataset#id';

    $form = $this->form->buildForm([], $form_state, $id);

    // Should show error markup.
    $this->assertArrayHasKey('export', $form);
    $this->assertSame('markup', $form['export']['#type']);
  }

  /**
   * Test form rejects invalid ID with slashes.
   */
  #[Test]
  public function testBuildFormInvalidIdWithSlashes(): void {
    $form_state = new FormState();
    $id = 'invalid/dataset/id';

    $form = $this->form->buildForm([], $form_state, $id);

    // Should show error markup.
    $this->assertArrayHasKey('export', $form);
    $this->assertSame('markup', $form['export']['#type']);
  }

  /**
   * Test form accepts valid IDs with allowed special characters.
   *
   * @param string $id
   *   Test dataset ID.
   */
  #[Test]
  #[DataProvider('validIdProvider')]
  public function testBuildFormValidIdWithAllowedChars(string $id): void {
    $form_state = new FormState();
    $form = $this->form->buildForm([], $form_state, $id);

    $this->assertArrayHasKey('solr_id', $form);
    $this->assertSame($id, $form['solr_id']['#value']);
  }

  /**
   * Data provider for testBuildFormValidIdWithAllowedChars.
   */
  public static function validIdProvider(): array {
    return [
      ['simple-id'],
      ['id.with.dots'],
      ['id:with:colons'],
      ['id_with_underscores'],
      ['mixed-id.with:all_chars'],
      ['ABC123def'],
    ];
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
   * Test invalid ID patterns are rejected.
   *
   * @param string $id
   *   Invalid dataset ID to test.
   */
  #[Test]
  #[DataProvider('invalidIdProvider')]
  public function testInvalidIdPatterns(string $id): void {
    $form_state = new FormState();
    $form = $this->form->buildForm([], $form_state, $id);

    // Empty string is checked separately but returns the "missing" message.
    if ($id === '') {
      $this->assertArrayHasKey('export', $form);
      $this->assertSame('markup', $form['export']['#type']);
    }
    else {
      // Non-empty invalid IDs should show error markup.
      $this->assertArrayHasKey('export', $form);
      $this->assertSame('markup', $form['export']['#type']);
    }
  }

  /**
   * Data provider for testInvalidIdPatterns.
   */
  public static function invalidIdProvider(): array {
    return [
      ['id with spaces'],
      ['id@with@at'],
      ['id#with#hash'],
      ['id&with&ampersand'],
      ['id$with$dollar'],
      ['id/with/slash'],
      ['id\\with\\backslash'],
      ['id|with|pipe'],
      ['id?with?question'],
      ['id*with*star'],
    ];
  }

  /**
   * Test valid ID patterns are accepted.
   *
   * @param string $id
   *   Valid dataset ID to test.
   */
  #[Test]
  #[DataProvider('validIdPatternProvider')]
  public function testValidIdPatterns(string $id): void {
    $form_state = new FormState();
    $form = $this->form->buildForm([], $form_state, $id);

    // Valid IDs should have solr_id field properly set.
    $this->assertArrayHasKey('solr_id', $form);
    $this->assertSame($id, $form['solr_id']['#value']);
  }

  /**
   * Data provider for testValidIdPatterns.
   */
  public static function validIdPatternProvider(): array {
    return [
      ['simple'],
      ['with-dash'],
      ['with_underscore'],
      ['with.dot'],
      ['with:colon'],
      ['MixedCase123'],
      ['complex-id.with:multiple_chars'],
      ['a'],
      ['1'],
      ['A1b2C3'],
    ];
  }

}
