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
            'author' => 'Orion Reith & Co.',
            'content' => '<p>Der <strong>Text</strong> &amp; mehr</p>',
        ])->getContent();

        $this->assertStringContainsString('>Kommentare &amp; Berichte<br />', $content);
        $this->assertStringContainsString('<p class="Ebook-Titel">Foodora &amp; Co.: &lt;Die&gt; Revolte', $content);
        $this->assertStringContainsString('<span class="Light">Über &quot;Plattformen&quot; &amp; Arbeit</span>', $content);
        $this->assertStringContainsString('<span class="Light">von </span>Orion Reith &amp; Co.</p>', $content);
        // The body is HTML and must not be escaped.
        $this->assertStringContainsString('<p class="Ebook-Fliesstext">Der <strong>Text</strong> &amp; mehr</p>', $content);
        // The result is well-formed XML.
        $this->assertNotFalse(simplexml_load_string('<div>' . $content . '</div>'));
    }

    /**
     * Tests, that the title of the cover is escaped.
     */
    public function testCoverTitleIsEscaped(): void
    {
        $content = $this->createChapter(Cover::class, ['title' => 'Mai & Juni <2026>'])->getContent();

        $this->assertStringContainsString('Titelbild Mai &amp; Juni &lt;2026&gt;</p>', $content);
    }
}
