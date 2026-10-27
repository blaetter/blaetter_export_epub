<?php

namespace Drupal\Tests\blaetter_export_epub\Unit\Blaetter\Epub;

use Drupal\blaetter_export_epub\Blaetter\Epub\IssueExporter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests, that the @font-face rules of missing fonts are removed from the styles of the ePub.
 *
 * @group blaetter_export_epub
 */
#[CoversClass(IssueExporter::class)]
#[Group('blaetter_export_epub')]
class IssueExporterFontsTest extends UnitTestCase
{
    /**
     * Returns the styles of the ePub.
     */
    private function css(): string
    {
        return file_get_contents(dirname(__DIR__, 5) . '/templates/css/styles.css');
    }

    /**
     * Every font of the styles is listed, so a missing one can be left out.
     */
    public function testAllFontsOfTheStylesAreKnown(): void
    {
        preg_match_all('#url\("\./font/([^"]+)"\)#', $this->css(), $matches);
        $this->assertNotEmpty($matches[1]);
        $this->assertSame([], array_diff($matches[1], array_keys(IssueExporter::FONTS)));
    }

    /**
     * Without any font, no @font-face rule is left, the other rules stay.
     */
    public function testAllFontsMissing(): void
    {
        $css = IssueExporter::removeFontFaces($this->css(), array_keys(IssueExporter::FONTS));
        $this->assertStringNotContainsStringIgnoringCase('@font-face', $css);
        $this->assertStringNotContainsString('./font/', $css);
        $this->assertStringContainsString('font-family:"Droid Sans", sans-serif;', $css);
    }

    /**
     * Only the rules of the missing fonts are removed.
     */
    public function testOneFontMissing(): void
    {
        $css = IssueExporter::removeFontFaces($this->css(), ['DroidSerif-Regular.ttf']);
        $this->assertStringNotContainsString('DroidSerif-Regular.ttf', $css);
        $this->assertStringContainsString('DroidSerif-Bold.ttf', $css);
        $this->assertStringContainsString('PlayfairDisplay-Regular.ttf', $css);
    }

    /**
     * Every font of the list is part of the module, together with the licenses named in the notice of the imprint.
     */
    public function testAllFontsAreInstalled(): void
    {
        $directory = dirname(__DIR__, 5) . '/templates/font/';
        foreach (array_keys(IssueExporter::FONTS) as $file) {
            $this->assertFileExists($directory . $file);
        }
        $this->assertFileExists($directory . 'OFL.txt');
        $this->assertFileExists($directory . 'LICENSE-Apache-2.0.txt');
    }

    /**
     * The notice on the licenses of the fonts comes before the copyright line, once.
     */
    public function testFontLicensesBeforeTheCopyright(): void
    {
        $notice = '<p class="Ebook-Impressum Ebook-Schriften">Schriften: …</p>';
        $imprint = '<p class="Ebook-Impressum">Verlag</p>' . "\n"
            . '<p class="Ebook-Impressum">' . "\n" . '  <span class="Bold">© Blätter</span></p>';

        $with_notice = IssueExporter::withFontLicenses($imprint, $notice);
        $this->assertSame(
            '<p class="Ebook-Impressum">Verlag</p>' . "\n" . $notice
                . '<p class="Ebook-Impressum">' . "\n" . '  <span class="Bold">© Blätter</span></p>',
            $with_notice
        );
        // an imprint taken from an ePub with the notice gets it once, in its current form
        $again = IssueExporter::withFontLicenses(str_replace('…', 'alt', $with_notice), $notice);
        $this->assertSame(1, substr_count($again, 'Ebook-Schriften'));
        $this->assertStringNotContainsString('alt', $again);
        // without the fonts the notice is removed
        $this->assertSame($imprint, IssueExporter::withFontLicenses($with_notice, ''));
        // without a copyright line the notice comes last
        $this->assertSame('<p>Verlag</p>' . $notice, IssueExporter::withFontLicenses('<p>Verlag</p>', $notice));
    }

    /**
     * With all fonts installed, the styles stay as they are.
     */
    public function testNoFontMissing(): void
    {
        $this->assertSame($this->css(), IssueExporter::removeFontFaces($this->css(), []));
    }
}
