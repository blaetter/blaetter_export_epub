<?php

namespace Drupal\Tests\blaetter_export_epub\Unit;

use Drupal\blaetter_export_epub\PepgenPublisher;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests, that an ePub is unzipped into the original directory of pepgen and replaces the previous one safely.
 *
 * @group blaetter_export_epub
 */
#[CoversClass(PepgenPublisher::class)]
#[Group('blaetter_export_epub')]
class PepgenPublisherTest extends UnitTestCase
{
    /**
     * The original directory of the test.
     *
     * @var string
     */
    private $directory;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $container = new ContainerBuilder();
        $container->set('string_translation', $this->getStringTranslationStub());
        \Drupal::setContainer($container);
        $this->directory = sys_get_temp_dir() . '/blaetter-pepgen-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
        parent::tearDown();
    }

    /**
     * Returns a minimal ePub (zip data) with the given chapter text, or with other files.
     */
    private function epub(string $text, ?array $files = null): string
    {
        $file = tempnam(sys_get_temp_dir(), 'blaetter-pepgen-zip-');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);
        foreach ($files ?? [
            'mimetype' => 'application/epub+zip',
            'META-INF/container.xml' => '<container/>',
            'OEBPS/33094.html' => $text,
        ] as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $data = file_get_contents($file);
        unlink($file);
        return $data;
    }

    /**
     * Returns the entries of the original directory, hidden ones included.
     */
    private function entries(): array
    {
        return array_values(array_diff(scandir($this->directory), ['.', '..']));
    }

    public function testPublish(): void
    {
        $path = (new PepgenPublisher($this->directory . '/', new NullLockBackend()))->publish($this->epub('first'), 33093);

        $this->assertSame($this->directory . '/33093.epub', $path);
        $this->assertSame('application/epub+zip', file_get_contents($path . '/mimetype'));
        $this->assertSame('first', file_get_contents($path . '/OEBPS/33094.html'));
        $this->assertSame(['33093.epub'], $this->entries());
    }

    public function testReplaceKeepsThePreviousVersion(): void
    {
        $publisher = new PepgenPublisher($this->directory, new NullLockBackend());
        $publisher->publish($this->epub('first'), 33093);
        $publisher->publish($this->epub('second'), 33093);
        $publisher->publish($this->epub('third'), 33093);

        $this->assertSame(['.33093.epub.previous', '33093.epub'], $this->entries());
        $this->assertSame('third', file_get_contents($this->directory . '/33093.epub/OEBPS/33094.html'));
        $this->assertSame('second', file_get_contents($this->directory . '/.33093.epub.previous/OEBPS/33094.html'));
    }

    public function testBrokenDataKeepsTheCurrentVersion(): void
    {
        $publisher = new PepgenPublisher($this->directory, new NullLockBackend());
        $publisher->publish($this->epub('first'), 33093);

        foreach (['no zip', $this->epub('', ['OEBPS/33094.html' => 'no epub'])] as $data) {
            try {
                $publisher->publish($data, 33093);
                $this->fail('Broken data was published.');
            } catch (\RuntimeException $e) {
                $this->assertSame('first', file_get_contents($this->directory . '/33093.epub/OEBPS/33094.html'));
                $this->assertSame(['33093.epub'], $this->entries());
            }
        }
    }

    public function testIdHasToBeANumber(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PepgenPublisher($this->directory, new NullLockBackend()))->publish($this->epub('first'), '../33093');
    }

    public function testCheckDirectory(): void
    {
        $this->assertNull(PepgenPublisher::checkDirectory($this->directory));
        $this->assertSame('The directory has to be an absolute path.', (string) PepgenPublisher::checkDirectory('epub'));
        $this->assertSame('The directory has to be an absolute path.', (string) PepgenPublisher::checkDirectory(''));
        $this->assertSame(
            'The directory does not exist.',
            (string) PepgenPublisher::checkDirectory($this->directory . '/missing')
        );
    }

    public function testInterruptedSwapIsRepaired(): void
    {
        $publisher = new PepgenPublisher($this->directory, new NullLockBackend());
        $publisher->publish($this->epub('first'), 33093);
        // a run that stopped between the two renames leaves only the previous version
        rename($this->directory . '/33093.epub', $this->directory . '/.33093.epub.previous');

        $publisher->publish($this->epub('second'), 33093);
        $this->assertSame(['.33093.epub.previous', '33093.epub'], $this->entries());
        $this->assertSame('second', file_get_contents($this->directory . '/33093.epub/OEBPS/33094.html'));
        $this->assertSame('first', file_get_contents($this->directory . '/.33093.epub.previous/OEBPS/33094.html'));
    }

    public function testBusyIssueIsNotReplaced(): void
    {
        $lock = $this->createMock(LockBackendInterface::class);
        $lock->method('acquire')->willReturn(false);
        $lock->expects($this->once())->method('wait')->with('blaetter_export_epub:pepgen:33093');
        $lock->expects($this->never())->method('release');

        $this->expectExceptionMessage('is being replaced right now');
        (new PepgenPublisher($this->directory, $lock))->publish($this->epub('first'), 33093);
    }

    public function testLockIsReleasedAfterAFailure(): void
    {
        $lock = $this->createMock(LockBackendInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lock->expects($this->once())->method('release')->with('blaetter_export_epub:pepgen:33093');

        $this->expectException(\RuntimeException::class);
        (new PepgenPublisher($this->directory, $lock))->publish('no zip', 33093);
    }
}
