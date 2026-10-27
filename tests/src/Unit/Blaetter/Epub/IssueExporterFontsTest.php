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
        $this->assertStringContainsString('font-family:"Frutiger LT Std 45 Light", sans-serif;', $css);
    }

    /**
     * Only the rules of the missing fonts are removed.
     */
    public function testOneFontMissing(): void
    {
        $css = IssueExporter::removeFontFaces($this->css(), ['CandidaStd-Roman.otf']);
        $this->assertStringNotContainsString('CandidaStd-Roman.otf', $css);
        $this->assertStringContainsString('CandidaStd-Bold.otf', $css);
        $this->assertStringContainsString('FrutigerLTStd-LightCn.otf', $css);
    }

    /**
     * With all fonts installed, the styles stay as they are.
     */
    public function testNoFontMissing(): void
    {
        $this->assertSame($this->css(), IssueExporter::removeFontFaces($this->css(), []));
    }
}
