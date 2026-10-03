<?php

namespace Drupal\Tests\blaetter_export_epub\Unit\Form;

use Drupal\blaetter_export_epub\Form\EpubForm;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Form\FormState;
use Drupal\node\Entity\Node;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the validation of the export form.
 *
 * @group blaetter_export_epub
 */
#[CoversClass(EpubForm::class)]
#[Group('blaetter_export_epub')]
class EpubFormTest extends UnitTestCase
{
    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $nodes = [];
        foreach ([28694 => 'ausgabe', 28697 => 'story'] as $nid => $type) {
            $node = $this->createMock(Node::class);
            $node->method('id')->willReturn($nid);
            $node->method('getType')->willReturn($type);
            $nodes[$nid] = $node;
        }
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturnCallback(fn ($id) => $nodes[$id] ?? null);

        $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
        $entity_type_manager->method('getStorage')->with('node')->willReturn($storage);
        $entity_type_repository = $this->createMock(EntityTypeRepositoryInterface::class);
        $entity_type_repository->method('getEntityTypeFromClass')->willReturn('node');

        $container = new ContainerBuilder();
        $container->set('entity_type.manager', $entity_type_manager);
        $container->set('entity_type.repository', $entity_type_repository);
        $container->set('string_translation', $this->getStringTranslationStub());
        \Drupal::setContainer($container);
    }

    /**
     * Validates the form with the given values and returns the errors.
     */
    private function validate(array $values): array
    {
        $form = [];
        $form_state = (new FormState())->setValues($values + ['export' => 'epub']);
        (new EpubForm())->validateForm($form, $form_state);
        return array_map('strval', $form_state->getErrors());
    }

    /**
     * Tests, that an edition can be exported as ePub.
     */
    public function testValidEdition(): void
    {
        $this->assertSame([], $this->validate(['edition' => '28694']));
    }

    /**
     * Tests, that missing, unknown and wrong nodes are reported.
     */
    public function testInvalidEdition(): void
    {
        $not_found = ['edition' => 'The selected edition could not be found.'];

        $this->assertSame($not_found, $this->validate(['edition' => '']));
        $this->assertSame($not_found, $this->validate(['edition' => '99999']));
        $this->assertSame(
            ['edition' => 'Something is wrong with the selected node: Its no edition.'],
            $this->validate(['edition' => '28697'])
        );
    }

    /**
     * Tests, that only the ePub export is accepted, since the mobi export was removed.
     */
    public function testOnlyEpubExport(): void
    {
        foreach (['mobi', '', null] as $export) {
            $this->assertSame(
                ['export' => 'Something is wrong with the export action.'],
                $this->validate(['edition' => '28694', 'export' => $export])
            );
        }
    }

    /**
     * The watermark in the imprint is followed by a line break, so it does not run into the next sentence.
     */
    public function testImprintWatermarkIsFollowedByLineBreak(): void
    {
        $imprint = file_get_contents(dirname(__DIR__, 4) . '/templates/imprint.tmpl');
        $this->assertStringContainsString('___WATERMARK___', $imprint);

        $this->assertMatchesRegularExpression('#^<span [^>]*>XXXXX </span><br />$#', EpubForm::IMPRINT_WATERMARK);
        // pepgen replaces the placeholder with inline text
        $this->assertStringContainsString(
            '<p class="Ebook-Impressum"><span class="Bold char-style-override-12">Lizenziert für Erika Muster </span>'
            . '<br />',
            str_replace(
                'XXXXX',
                'Lizenziert für Erika Muster',
                str_replace('___WATERMARK___', EpubForm::IMPRINT_WATERMARK, $imprint)
            )
        );
    }
}
