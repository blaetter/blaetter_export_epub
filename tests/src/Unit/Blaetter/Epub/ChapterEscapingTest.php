<?php

namespace Drupal\Tests\blaetter_export_epub\Unit\Blaetter\Epub;

use Drupal\blaetter_export_epub\Blaetter\Epub\Chapter;
use Drupal\blaetter_export_epub\Blaetter\Epub\Cover;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests, that the plain text fields are escaped in the XHTML of the ePub, while the body stays HTML.
 *
 * @group blaetter_export_epub
 */
#[CoversClass(Chapter::class)]
#[CoversClass(Cover::class)]
#[Group('blaetter_export_epub')]
class ChapterEscapingTest extends UnitTestCase
{
    /**
     * Creates a chapter with the given values without the need of a complete node.
     */
    private function createChapter(string $class, array $values): Chapter
    {
        $chapter = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $values += [
            'header' => '<html><body>',
            'footer' => '</body></html>',
            'title' => '',
            'subtitle' => '',
            'rubric' => '',
            'author' => '',
            'author_names' => [],
            'image' => '',
            'content' => '',
            // The rubric of the previous chapter.
            'data' => (object) ['rubric' => ''],
        ];
        foreach ($values as $property => $value) {
            $chapter->{$property} = $value;
        }
        return $chapter;
    }

    /**
     * Tests, that title, subtitle, rubric and authors are escaped.
     */
    public function testPlainTextIsEscaped(): void
    {
        $content = $this->createChapter(Chapter::class, [
            'title' => 'Foodora & Co.: <Die> Revolte',
            'subtitle' => 'Über "Plattformen" & Arbeit',
            'rubric' => 'Kommentare & Berichte',
            'author_names' => ['Orion Reith & Co.', 'Anna <Muster>', 'Bernd Beispiel'],
            'content' => '<p>Der <strong>Text</strong> &amp; mehr</p>',
        ])->getContent();

        $this->assertStringContainsString('>Kommentare &amp; Berichte</p>', $content);
        $this->assertStringContainsString('<p class="Ebook-Titel Ebook-Titel-Untertitel">Foodora &amp; Co.: &lt;Die&gt; Revolte</p>', $content);
        $this->assertStringContainsString(
            '<p class="Ebook-Untertitel Ebook-Untertitel-Autor">Über &quot;Plattformen&quot; &amp; Arbeit</p>',
            $content
        );
        $this->assertStringContainsString(
            '<p class="Ebook-Autor"><span class="Ebook-Autor-Von">Von </span>'
                . '<span class="Ebook-Autor-Name">Orion Reith &amp; Co.</span>, '
                . '<span class="Ebook-Autor-Name">Anna &lt;Muster&gt;</span><span class="Ebook-Autor-Von"> und </span>'
                . '<span class="Ebook-Autor-Name">Bernd Beispiel</span></p>',
            $content
        );
        // The body is HTML and must not be escaped.
        $this->assertStringContainsString('<p class="Ebook-Fliesstext">Der <strong>Text</strong> &amp; mehr</p>', $content);
        // The result is well-formed XML.
        $this->assertNotFalse(simplexml_load_string('<div>' . $content . '</div>'));
    }

    /**
     * Tests, that the inserts of blocks, contents and media are removed completely, with their paragraph if they
     * stand alone.
     */
    public function testInsertsAreRemoved(): void
    {
        $content = $this->createChapter(Chapter::class, [
            'content' => '<p>Erster Absatz.</p><p>[content:13]</p><p>&nbsp;[block:weihnachtsabo]&nbsp;</p>'
                . '<h2>Zwischentitel</h2><p>Text [media:9] und [content:5] weiter.</p>',
        ])->getContent();

        $this->assertStringNotContainsString(']', $content);
        $this->assertStringContainsString('<p class="Ebook-Fliesstext">Erster Absatz.</p><h2>Zwischentitel</h2>', $content);
        $this->assertStringContainsString('<p class="Ebook-Fliesstext">Text  und  weiter.</p>', $content);
    }

    /**
     * Tests, that the cover page repeats the cover without a heading, followed by the placeholder for the watermark.
     */
    public function testCoverHasNoHeading(): void
    {
        $cover = $this->createChapter(Cover::class, ['title' => 'Mai & Juni <2026>', 'image' => 'images/cover.png']);
        $cover->setExportType('epub');
        $content = $cover->getContent();

        $this->assertStringNotContainsString('Titelbild Mai', $content);
        $this->assertStringContainsString('<img class="frame-1" src="images/cover.png"', $content);
        $this->assertStringContainsString('XXXXX', $content);
    }

    public static function imageCreditProvider(): array
    {
        return [
            'plain text' => ['Tim Reckmann (CC BY 2.0)', 'Tim Reckmann (CC BY 2.0)'],
            'link' => [
                'Santiago Engelhardt | http://santiagoengelhardt.de',
                '<span class="link"><a href="http://santiagoengelhardt.de" rel="nofollow">Santiago Engelhardt</a></span>',
            ],
            'link without scheme' => [
                'Tomas Castelazo | www.tomascastelazo.com',
                '<span class="link"><a href="https://www.tomascastelazo.com" rel="nofollow">Tomas Castelazo</a></span>',
            ],
            'quotes in the target' => ['Tim Reckmann | a59.de: "flaggen"', 'Tim Reckmann'],
            'text as target' => ['IG Metall Bamberg (CC BY-ND 2.0) | IG Metall Bamberg', 'IG Metall Bamberg (CC BY-ND 2.0)'],
            'html in the text' => ['A & B <script> | https://example.com', '<span class="link"><a href="https://example.com" rel="nofollow">A &amp; B &lt;script&gt;</a></span>'],
        ];
    }

    /**
     * Tests, that the image credits entered by the editors become valid XHTML.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('imageCreditProvider')]
    public function testImageCreditLine(string $line, string $expected): void
    {
        $this->assertSame($expected, $this->createChapter(Chapter::class, [])->formatImageCreditLine($line));
    }
}
