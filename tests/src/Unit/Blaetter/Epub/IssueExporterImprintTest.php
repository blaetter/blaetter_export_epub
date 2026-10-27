<?php

namespace Drupal\Tests\blaetter_export_epub\Unit\Blaetter\Epub;

use Drupal\blaetter_export_epub\Blaetter\Epub\IssueExporter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests, that the imprint of an existing ePub is taken over with the current placeholder for the watermark, and its
 * authors page if it lists the same authors.
 *
 * @group blaetter_export_epub
 */
#[CoversClass(IssueExporter::class)]
#[Group('blaetter_export_epub')]
class IssueExporterImprintTest extends UnitTestCase
{
    /**
     * Returns an imprint chapter like the exports of earlier years.
     */
    private function chapter(string $content): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">'
            . '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>Impressum</title></head>'
            . '<body xml:lang="de-DE"><div class="Einfacher-Textrahmen"><p class="Ebook-Titel">Impressum<br/></p>'
            . $content . '</div></body></html>';
    }

    public function testOldPlaceholderGetsTheLineBreak(): void
    {
        $content = IssueExporter::extractImprint($this->chapter(
            '<p class="Ebook-Impressum"><span class="Bold">Verlag:</span> Postfach 54 02 46</p>'
            . '<p class="Ebook-Impressum"><span class="Bold char-style-override-12">XXXXX </span>'
            . '<span class="Bold char-style-override-5">Mit dem Erwerb des E-Books erhalten Sie das Recht.</span></p>'
        ), IssueExporter::IMPRINT_WATERMARK);

        $this->assertSame(
            '<p class="Ebook-Impressum"><span class="Bold">Verlag:</span> Postfach 54 02 46</p>'
            . '<p class="Ebook-Impressum">' . IssueExporter::IMPRINT_WATERMARK
            . '<span class="Bold char-style-override-5">Mit dem Erwerb des E-Books erhalten Sie das Recht.</span></p>',
            $content
        );
        // the title is added by the chapter again
        $this->assertStringNotContainsString('Ebook-Titel', $content);
    }

    public function testCurrentPlaceholderStays(): void
    {
        $paragraph = '<p class="Ebook-Impressum">' . IssueExporter::IMPRINT_WATERMARK . '<span>Mit dem Erwerb des E-Books</span></p>';
        // serialized as XML, the line break is written as <br/>
        $this->assertSame(
            str_replace('<br />', '<br/>', $paragraph),
            IssueExporter::extractImprint($this->chapter($paragraph), IssueExporter::IMPRINT_WATERMARK)
        );
    }

    public function testMissingPlaceholderIsAdded(): void
    {
        $content = IssueExporter::extractImprint($this->chapter(
            '<p class="Ebook-Impressum"><span class="Bold">Mit dem Erwerb des E-Books erhalten Sie das Recht.</span></p>'
        ), IssueExporter::IMPRINT_WATERMARK);
        $this->assertSame(
            '<p class="Ebook-Impressum">' . IssueExporter::IMPRINT_WATERMARK
            . '<span class="Bold">Mit dem Erwerb des E-Books erhalten Sie das Recht.</span></p>',
            $content
        );

        $content = IssueExporter::extractImprint($this->chapter('<p>Verlag</p>'), IssueExporter::IMPRINT_WATERMARK);
        $this->assertSame('<p>Verlag</p><p class="Ebook-Impressum">' . IssueExporter::IMPRINT_WATERMARK . '</p>', $content);
    }

    public function testMalformedImprintOfOldExports(): void
    {
        // exports until 2020: a span closed by the paragraph
        $content = IssueExporter::extractImprint($this->chapter(
            '<p class="Ebook-Impressum"><span class="Bold">Verlag: Postfach 54 02 46</p>'
            . '<p class="Ebook-Impressum"><span class="Bold char-style-override-12">XXXXX </span>Mit dem Erwerb des E-Books</p>'
        ), IssueExporter::IMPRINT_WATERMARK);

        $this->assertStringContainsString('Postfach 54 02 46', $content);
        $this->assertStringContainsString('XXXXX </span><br', $content);
        $this->assertStringNotContainsString('Ebook-Titel', $content);
    }

    public function testUnknownChapterIsNotUsed(): void
    {
        $this->assertNull(IssueExporter::extractImprint('no xml', IssueExporter::IMPRINT_WATERMARK));
        $this->assertNull(IssueExporter::extractImprint(
            '<html xmlns="http://www.w3.org/1999/xhtml"><body><p>Impressum</p></body></html>',
            IssueExporter::IMPRINT_WATERMARK
        ));
    }

    /**
     * Returns the markup of an authors page with the given authors and bios.
     */
    private function authors(array $bios): string
    {
        $paragraphs = [];
        foreach ($bios as $name => $bio) {
            $paragraphs[] = '<p class="Ebook-Fliesstext-ohneEinzug"><span class="char-style-override-15">' . $name
                . '</span>, ' . $bio . '</p>';
        }
        return implode('<p class="Ebook-Fliesstext-ohneEinzug">&#160;</p>', $paragraphs);
    }

    public function testAuthorsPageWithTheSameAuthorsIsUsed(): void
    {
        $old = $this->authors(['Ulrike Baureithel' => 'geb. 1957, freie Journalistin.', 'Rudolf &amp; Co' => 'Verlag.']);
        // the current bios differ, the names are the same in another order and with another escaping
        $current = $this->authors(['Rudolf &amp; Co' => 'Verlag seit 1956.', 'Ulrike  Baureithel' => 'Journalistin.']);

        $content = IssueExporter::extractAuthors($this->chapter($old), $current);

        $this->assertStringContainsString('geb. 1957, freie Journalistin.', $content);
        $this->assertStringNotContainsString('Ebook-Titel', $content);
    }

    public function testAuthorsPageWithOtherAuthorsIsNotUsed(): void
    {
        $old = $this->authors(['Ulrike Baureithel' => 'geb. 1957.']);
        $current = $this->authors(['Ulrike Baureithel' => 'geb. 1957.', 'Albrecht von Lucke' => 'geb. 1967.']);

        $this->assertNull(IssueExporter::extractAuthors($this->chapter($old), $current));
        $this->assertNull(IssueExporter::extractAuthors('no xml', $current));
    }
}
