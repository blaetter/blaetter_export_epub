<?php

namespace Drupal\Tests\blaetter_export_epub\Unit\Blaetter\Epub;

use Drupal\blaetter_export_epub\Blaetter\Epub\Chapter;
use Drupal\blaetter_export_epub\Blaetter\Epub\Generator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests, that images of the website are added to the epub from the file system.
 *
 * @group blaetter_export_epub
 */
#[CoversClass(Generator::class)]
#[Group('blaetter_export_epub')]
class GeneratorTest extends UnitTestCase
{
    /**
     * The Drupal root of the test.
     *
     * @var string
     */
    private $drupal_root;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->drupal_root = sys_get_temp_dir() . '/blaetter-epub-test-' . bin2hex(random_bytes(6));
        mkdir($this->drupal_root . '/sites/default/files/downloads', 0777, true);
        // a minimal jpeg
        file_put_contents($this->drupal_root . '/sites/default/files/downloads/caracas.jpg', base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////' .
            '////////////////////////2wBDAf//////////////////////////////////////////////////////////////////' .
            '////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFAABAAAAAAAAAAAAAAAAAAAAA//EABQQAQAAAAAAAAAAAAAAAAAA' .
            'AAD/xAAUAQEAAAAAAAAAAAAAAAAAAAAA/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AP//Z'
        ));
        file_put_contents($this->drupal_root . '/sites/default/files/downloads/notes.txt', 'text');
        file_put_contents(dirname($this->drupal_root) . '/blaetter-epub-outside.jpg', 'x');
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->drupal_root));
        @unlink(dirname($this->drupal_root) . '/blaetter-epub-outside.jpg');
        parent::tearDown();
    }

    private function createGenerator(): Generator
    {
        return new Generator('Dezember 2018', 28694, 'Blätter', 'https://www.blaetter.de/node/28694', 'de', 'https://www.blaetter.de', $this->drupal_root);
    }

    /**
     * Tests, that images of the website are added once and other files are refused.
     */
    public function testAddLocalImage(): void
    {
        $generator = $this->createGenerator();
        $expected = 'images/local-' . md5(realpath($this->drupal_root . '/sites/default/files/downloads/caracas.jpg')) . '.jpg';

        $this->assertSame($expected, $generator->addLocalImage('sites/default/files/downloads/caracas.jpg'));
        $this->assertSame($expected, $generator->addLocalImage('/sites/default/files/downloads/caracas.jpg?itok=1'));
        $this->assertSame($expected, $generator->addLocalImage('sites/default/files/downloads/caracas.jpg'));

        // missing files, other file types and files outside the Drupal root are not added
        $this->assertNull($generator->addLocalImage('sites/default/files/downloads/missing.jpg'));
        $this->assertNull($generator->addLocalImage('sites/default/files/downloads/notes.txt'));
        $this->assertNull($generator->addLocalImage('../blaetter-epub-outside.jpg'));
        $this->assertNull($generator->addLocalImage(''));
    }

    /**
     * Tests, that the chapters use the images of the website within the epub.
     */
    public function testChapterUsesLocalImages(): void
    {
        $chapter = (new \ReflectionClass(Chapter::class))->newInstanceWithoutConstructor();
        foreach ([
            'header' => file_get_contents(dirname(__DIR__, 5) . '/templates/head.tmpl'),
            'footer' => file_get_contents(dirname(__DIR__, 5) . '/templates/foot.tmpl'),
            'title' => 'Der Kampf um die Stadt',
            'subtitle' => '', 'rubric' => 'Analysen und Alternativen', 'author' => 'Richard Sennett',
            'image' => '', 'filename' => '28700', 'data' => (object) ['rubric' => 'Analysen und Alternativen'],
            'content' => '<p><img src="sites/default/files/downloads/caracas.jpg" align="left"/>'
                . '<img src="sites/default/files/downloads/missing.jpg"/>Caracas</p>',
        ] as $property => $value) {
            $chapter->{$property} = $value;
        }

        $this->createGenerator()->addChapter($chapter);

        $content = $chapter->getContent();
        $this->assertStringContainsString('<img src="images/local-', $content);
        $this->assertStringNotContainsString('sites/default/files', $content);
        $this->assertStringNotContainsString('align=', $content);
    }
}
