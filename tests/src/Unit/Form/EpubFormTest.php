<?php

namespace Drupal\Tests\blaetter_export_epub\Unit\Form;

use Drupal\blaetter_export_epub\Blaetter\Epub\IssueExporter;
use Drupal\blaetter_export_epub\Form\EpubForm;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
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
     * The entity type manager with an issue (28694) and an article (28697).
     *
     * @var EntityTypeManagerInterface
     */
    private $entityTypeManager;

    /**
     * The container of the test.
     *
     * @var ContainerBuilder
     */
    private $container;

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
        $this->entityTypeManager = $entity_type_manager;
        $entity_type_repository = $this->createMock(EntityTypeRepositoryInterface::class);
        $entity_type_repository->method('getEntityTypeFromClass')->willReturn('node');

        $container = $this->container = new ContainerBuilder();
        $container->set('entity_type.manager', $entity_type_manager);
        $container->set('entity_type.repository', $entity_type_repository);
        $container->set('string_translation', $this->getStringTranslationStub());
        $this->setPepgenDirectory('');
        \Drupal::setContainer($container);
    }

    /**
     * Sets the original directory of pepgen in the settings.
     */
    private function setPepgenDirectory(string $directory): void
    {
        $this->container->set('config.factory', $this->getConfigFactoryStub([
            'blaetter_export_epub.settings' => ['pepgen.original_dir' => $directory],
        ]));
    }

    /**
     * Creates the form.
     */
    private function createForm(): EpubForm
    {
        return new EpubForm(
            $this->entityTypeManager,
            $this->createMock(DateFormatterInterface::class),
            $this->createMock(ExtensionPathResolver::class)
        );
    }

    /**
     * Validates the form with the given values and returns the errors.
     */
    private function validate(array $values): array
    {
        $form = [];
        $form_state = (new FormState())->setValues($values + ['action' => 'download']);
        $this->createForm()->validateForm($form, $form_state);
        return array_map('strval', $form_state->getErrors());
    }

    /**
     * Tests, that selected editions can be exported; unchecked rows of the table are 0.
     */
    public function testValidSelection(): void
    {
        $this->assertSame([], $this->validate(['issues' => [28694 => 28694, 28700 => 0]]));
    }

    /**
     * Tests, that nothing selected, unknown and wrong nodes are reported.
     */
    public function testInvalidSelection(): void
    {
        $this->assertSame(
            ['issues' => 'Please select at least one issue.'],
            $this->validate(['issues' => [28694 => 0]])
        );
        $this->assertSame(
            ['issues' => 'The selected edition could not be found.'],
            $this->validate(['issues' => [99999 => 99999]])
        );
        $this->assertSame(
            ['issues' => 'Something is wrong with the selected node: Its no edition.'],
            $this->validate(['issues' => [28697 => 28697]])
        );
    }

    /**
     * Tests, that the hand over to pepgen is only offered and accepted when its directory is set and usable.
     */
    public function testActions(): void
    {
        $issues = ['issues' => [28694 => 28694]];
        $invalid = ['action' => 'The action is not valid.'];
        $this->assertSame($invalid, $this->validate($issues + ['action' => 'pepgen']));
        $this->assertSame($invalid, $this->validate($issues + ['action' => 'mail']));

        $this->setPepgenDirectory(sys_get_temp_dir());
        $this->assertSame([], $this->validate($issues + ['action' => 'pepgen']));
        $this->assertSame([], $this->validate($issues + ['action' => 'download']));

        $this->setPepgenDirectory(sys_get_temp_dir() . '/blaetter-missing-' . bin2hex(random_bytes(4)));
        $this->assertSame(
            ['action' => 'pepgen: The directory does not exist.'],
            $this->validate($issues + ['action' => 'pepgen'])
        );
    }

    /**
     * Tests, that at most MAX_DOWNLOAD issues can be downloaded at once, while the hand over has no limit.
     */
    public function testDownloadIsLimited(): void
    {
        $issues = array_fill_keys(range(28694, 28694 + EpubForm::MAX_DOWNLOAD), 1);
        foreach (array_keys($issues) as $nid) {
            $issues[$nid] = $nid;
        }
        $node = $this->entityTypeManager->getStorage('node')->load(28694);
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturn($node);
        $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $this->entityTypeManager->method('getStorage')->willReturn($storage);

        $this->assertSame(
            ['issues' => 'Please download at most 10 issues at once, or hand them over to pepgen.'],
            $this->validate(['issues' => $issues, 'action' => 'download'])
        );
        $this->setPepgenDirectory(sys_get_temp_dir());
        $this->assertSame([], $this->validate(['issues' => $issues, 'action' => 'pepgen']));
    }

    /**
     * Tests, that the hand over to pepgen comes first, when it is available, and the download is always offered.
     */
    public function testHandOverIsTheFirstAction(): void
    {
        $actions = new \ReflectionMethod(EpubForm::class, 'getActions');
        $this->assertSame(['download'], array_keys($actions->invoke($this->createForm())));

        $this->setPepgenDirectory(sys_get_temp_dir());
        $this->assertSame(['pepgen', 'download'], array_keys($actions->invoke($this->createForm())));
    }

    /**
     * Missing fonts are reported once, with the issues exported without them.
     */
    public function testMissingFontsMessage(): void
    {
        $this->assertSame(
            'Fonts are missing in templates/font/ of the module, so they are not part of the ePubs of Januar 2020, '
            . 'Dezember 2019: CandidaStd-Bold.otf, FrutigerLTStd-Light.otf',
            (string) EpubForm::missingFontsMessage(
                ['CandidaStd-Bold.otf' => 'CandidaStd-Bold.otf', 'FrutigerLTStd-Light.otf' => 'FrutigerLTStd-Light.otf'],
                ['Januar 2020', 'Dezember 2019']
            )
        );
    }

    /**
     * The watermark in the imprint is followed by a line break, so it does not run into the next sentence.
     */
    public function testImprintWatermarkIsFollowedByLineBreak(): void
    {
        $imprint = file_get_contents(dirname(__DIR__, 4) . '/templates/imprint.tmpl');
        $this->assertStringContainsString('___WATERMARK___', $imprint);

        $this->assertMatchesRegularExpression('#^<span [^>]*>XXXXX </span><br />$#', IssueExporter::IMPRINT_WATERMARK);
        // pepgen replaces the placeholder with inline text
        $this->assertStringContainsString(
            '<p class="Ebook-Impressum"><span class="Bold char-style-override-12">Lizenziert für Erika Muster </span>'
            . '<br />',
            str_replace(
                'XXXXX',
                'Lizenziert für Erika Muster',
                str_replace('___WATERMARK___', IssueExporter::IMPRINT_WATERMARK, $imprint)
            )
        );
    }
}
