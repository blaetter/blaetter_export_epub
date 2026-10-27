<?php

namespace Drupal\Tests\blaetter_export_epub\Unit\Blaetter\Epub;

use Drupal\blaetter_export_epub\Blaetter\Epub\IssueExporter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tests, that one ePub is offered as it is and several as zip archive.
 *
 * @group blaetter_export_epub
 */
#[CoversClass(IssueExporter::class)]
#[Group('blaetter_export_epub')]
class IssueExporterDownloadTest extends UnitTestCase
{
    public function testOneEpub(): void
    {
        $file = IssueExporter::writeTemporaryFile('epub data');
        $response = IssueExporter::downloadResponse(['33093.epub' => $file]);

        $this->assertSame('epub data', file_get_contents($response->getFile()->getPathname()));
        $this->assertSame('application/epub+zip', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename=33093.epub', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        unlink($response->getFile()->getPathname());
    }

    public function testSeveralEpubsAsZip(): void
    {
        $files = [
            '33093.epub' => IssueExporter::writeTemporaryFile('first'),
            '33050.epub' => IssueExporter::writeTemporaryFile('second'),
        ];
        $response = IssueExporter::downloadResponse($files);
        // the single ePubs are removed once they are in the zip archive
        $this->assertFileDoesNotExist($files['33093.epub']);

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression(
            '/^attachment; filename=blaetter-epubs-\d{4}-\d{2}-\d{2}\.zip$/',
            $response->headers->get('Content-Disposition')
        );

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($response->getFile()->getPathname()));
        $this->assertSame('first', $zip->getFromName('33093.epub'));
        $this->assertSame('second', $zip->getFromName('33050.epub'));
        // the ePubs are stored, they are zip archives already
        $this->assertSame(\ZipArchive::CM_STORE, $zip->statName('33093.epub')['comp_method']);
        $zip->close();
        unlink($response->getFile()->getPathname());
    }
}
