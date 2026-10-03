<?php

namespace Drupal\Tests\blaetter_export_epub\Unit\Blaetter\Epub;

use Drupal\blaetter_export_epub\Blaetter\Epub\XhtmlSanitizer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the conversion of the articles into XHTML 1.1.
 *
 * The cases are taken from the epubcheck results of real issues.
 *
 * @group blaetter_export_epub
 */
#[CoversClass(XhtmlSanitizer::class)]
#[Group('blaetter_export_epub')]
class XhtmlSanitizerTest extends UnitTestCase
{
    /**
     * Sanitizes the given HTML and checks, that the result is well-formed XML.
     */
    private function sanitize($html, ?XhtmlSanitizer $sanitizer = null)
    {
        $xhtml = ($sanitizer ?? new XhtmlSanitizer('https://www.blaetter.de'))->sanitize($html);
        $this->assertNotFalse(
            simplexml_load_string('<div xmlns="http://www.w3.org/1999/xhtml">' . $xhtml . '</div>'),
            'The result is no well-formed XML: ' . $xhtml
        );
        return $xhtml;
    }

    public static function htmlProvider(): array
    {
        return [
            'text stays as it is' => [
                '<p class="Ebook-Fliesstext">Der <strong>Text</strong> &amp; mehr</p>',
                '<p class="Ebook-Fliesstext">Der <strong>Text</strong> &amp; mehr</p>',
            ],
            'embedded video becomes a link' => [
                '<p><iframe src="https://www.youtube.com/embed/abc" frameborder="no" allowfullscreen></iframe></p>',
                '<p><a href="https://www.youtube.com/embed/abc">https://www.youtube.com/embed/abc</a></p>',
            ],
            'scripts are removed' => ['<p>Text<script>alert(1)</script></p>', '<p>Text</p>'],
            'comments are removed' => ['<p>Text<!-- Word --></p>', '<p>Text</p>'],
            'font is replaced by its text' => [
                '<h2><font size="3">Der Widerstand der Privilegierten</font></h2>',
                '<h2>Der Widerstand der Privilegierten</h2>',
            ],
            'presentational attributes are removed' => [
                '<p><br clear="all"/><span flaggen="">Text</span></p>',
                '<p><br/><span>Text</span></p>',
            ],
            'only valid dir values are kept' => [
                '<p>Nyír<span dir="auto">ő</span> <span dir="ltr">x</span></p>',
                '<p>Nyír<span>ő</span> <span dir="ltr">x</span></p>',
            ],
            'table attributes are kept' => [
                '<table border="1"><tr><td colspan="2" align="right" onclick="x()">1</td></tr></table>',
                '<table border="1"><tr><td colspan="2" align="right">1</td></tr></table>',
            ],
            'duplicate footnote anchors of Word' => [
                '<p><a href="#_ftn2" id="_ftnref2">[2]</a></p>'
                . '<p><a href="#_ftnref2" id="_ftn2" title=""></a><a href="#_ftnref2" id="_ftn2" title="">[2]</a> Vgl.</p>',
                '<p><a href="#_ftn2" id="_ftnref2">[2]</a></p>'
                . '<p><a href="#_ftnref2" id="_ftn2" title=""/><a href="#_ftnref2" title="">[2]</a> Vgl.</p>',
            ],
            'links to missing footnotes become text' => [
                '<p><a href="#_ftnref8" id="_ftn8"><sup>[8]</sup></a> Text</p>',
                '<p><sup>[8]</sup> Text</p>',
            ],
            'invalid ids are removed' => ['<p id="1 2">Text</p>', '<p>Text</p>'],
            'links without scheme' => [
                '<p><a href="www.tomascastelazo.com" target="_blank">Foto</a></p>',
                '<p><a href="https://www.tomascastelazo.com">Foto</a></p>',
            ],
            'links relative to the website' => [
                '<p><a href="/node/29381">Ausgabe</a></p>',
                '<p><a href="https://www.blaetter.de/node/29381">Ausgabe</a></p>',
            ],
            'spaces in links are encoded' => [
                '<p><a href="https://commons.wikimedia.org/wiki/File:A B.jpg">Foto</a></p>',
                '<p><a href="https://commons.wikimedia.org/wiki/File:A%20B.jpg">Foto</a></p>',
            ],
            'text as link target becomes text' => [
                '<p><a href="IG Metall Bamberg">IG Metall Bamberg</a></p>',
                '<p>IG Metall Bamberg</p>',
            ],
            'javascript links become text' => [
                '<p><a href="javascript:alert(1)">Klick</a></p>',
                '<p>Klick</p>',
            ],
            'mail links are kept' => [
                '<p><a href="mailto:abo@blaetter.de">abo@blaetter.de</a></p>',
                '<p><a href="mailto:abo@blaetter.de">abo@blaetter.de</a></p>',
            ],
            'external images get an alt text' => [
                '<p><img src="https://www.blaetter.de/a.jpg" align="left" vspace="5" rel="x"/></p>',
                '<p><img src="https://www.blaetter.de/a.jpg" alt=""/></p>',
            ],
            'images of the website without resolver are removed' => [
                '<p>Text<img src="sites/default/files/a.jpg" alt="A"/></p>',
                '<p>Text</p>',
            ],
            'unknown elements are replaced by their content' => [
                '<figure><img src="https://www.blaetter.de/a.jpg" alt="A"/><figcaption>Foto</figcaption></figure>',
                '<img src="https://www.blaetter.de/a.jpg" alt="A"/>Foto',
            ],
        ];
    }

    /**
     * Tests the conversion of typical HTML of the website.
     */
    #[DataProvider('htmlProvider')]
    public function testSanitize(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->sanitize($html));
    }

    /**
     * Tests, that images of the website are resolved to files within the epub.
     */
    public function testImagesOfTheWebsiteAreResolved(): void
    {
        $sources = [];
        $sanitizer = new XhtmlSanitizer('', function ($src) use (&$sources) {
            $sources[] = $src;
            return 'missing.jpg' === basename($src) ? null : 'images/local-1.jpg';
        });

        $this->assertSame(
            '<p><img src="images/local-1.jpg" alt="Caracas"/>Text</p>',
            $this->sanitize(
                '<p><img src="sites/default/files/caracas.jpg" alt="Caracas"/><img src="/missing.jpg"/>Text</p>',
                $sanitizer
            )
        );
        $this->assertSame(['sites/default/files/caracas.jpg', '/missing.jpg'], $sources);
    }

    /**
     * Tests, that links relative to the website are removed without base url.
     */
    public function testRelativeLinksWithoutBaseUrl(): void
    {
        $this->assertSame('<p>Ausgabe</p>', $this->sanitize('<p><a href="/node/1">Ausgabe</a></p>', new XhtmlSanitizer()));
    }

    /**
     * Tests the normalization of URLs, e.g. from image credits.
     */
    public function testNormalizeUrl(): void
    {
        $sanitizer = new XhtmlSanitizer('https://www.blaetter.de/');

        $this->assertSame('https://www.example.com', $sanitizer->normalizeUrl(' www.example.com '));
        $this->assertSame('https://www.blaetter.de/node/1', $sanitizer->normalizeUrl('/node/1'));
        $this->assertSame('https://example.com/a?b=1&c=2', $sanitizer->normalizeUrl('https://example.com/a?b=1&amp;c=2'));
        $this->assertNull($sanitizer->normalizeUrl('a59.de: "flaggen"'));
        $this->assertNull($sanitizer->normalizeUrl('IG Metall Bamberg'));
        $this->assertNull($sanitizer->normalizeUrl('//cdn.example.com/a.js'));
        $this->assertNull($sanitizer->normalizeUrl(''));
    }

    /**
     * Tests, that an empty article stays empty.
     */
    public function testEmptyContent(): void
    {
        $this->assertSame('', (new XhtmlSanitizer())->sanitize(''));
        $this->assertSame('', (new XhtmlSanitizer())->sanitize(null));
    }
}
