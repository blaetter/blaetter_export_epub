<?php

namespace Drupal\Tests\blaetter_export_epub\Unit\Commands;

use Drupal\blaetter_export_epub\Commands\EpubCommands;
use Drupal\blaetter_export_epub\EpubCredentials;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\blaetter_export_epub\Unit\EpubTestTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests the drush command to check watermark and token of an ePub.
 *
 * @group blaetter_export_epub
 */
#[CoversClass(EpubCommands::class)]
#[Group('blaetter_export_epub')]
class EpubCommandsTest extends UnitTestCase
{
    use EpubTestTrait;

    private const SALT = 'pepgen-salt';

    /**
     * The output of the command.
     *
     * @var BufferedOutput
     */
    private $output;

    /**
     * Creates the command with the given loadable nodes and users.
     */
    private function createCommand(array $nodes, array $users): EpubCommands
    {
        $storages = [];
        foreach (['node' => $nodes, 'user' => $users] as $entity_type => $entities) {
            $storage = $this->createMock(EntityStorageInterface::class);
            $storage->method('load')->willReturnCallback(fn ($id) => $entities[$id] ?? null);
            $storages[$entity_type] = $storage;
        }
        $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
        $entity_type_manager->method('getStorage')->willReturnCallback(fn ($entity_type) => $storages[$entity_type]);

        $command = new EpubCommands(
            $this->getConfigFactoryStub([
                'blaetter_export_epub.settings' => ['pepgen' => ['salt' => self::SALT]],
            ]),
            $entity_type_manager
        );
        $this->output = new BufferedOutput();
        $command->setOutput($this->output);
        return $command;
    }

    /**
     * Tests, that the command shows the same token as the download controller sends to pepgen.
     */
    public function testWatermarkUsesSaltAndDateOfDownload(): void
    {
        $node = $this->createNodeMock(29381);
        $user = $this->createUserMock('Erika', 'Muster', 'erika@example.com');

        $this->createCommand([29381 => $node], [15 => $user])->watermark(29381, 15, '2026-10-03');

        $expected = new EpubCredentials($node, $user, self::SALT, '2026-10-03');
        $this->assertSame(
            [
                'Checking Node 29381 for user 15',
                'Erika Muster, E-Mail: erika@example.com',
                $expected->getToken(),
            ],
            explode(PHP_EOL, trim($this->output->fetch()))
        );
        // Without the salt (e.g. reading the Drush instead of the Drupal configuration) the token differs.
        $this->assertNotSame((new EpubCredentials($node, $user, '', '2026-10-03'))->getToken(), $expected->getToken());
    }

    /**
     * Tests, that the date of the download defaults to today.
     */
    public function testWatermarkDefaultsToToday(): void
    {
        $node = $this->createNodeMock(29381);
        $user = $this->createUserMock('Erika', 'Muster', 'erika@example.com');

        $this->createCommand([29381 => $node], [15 => $user])->watermark(29381, 15);

        $this->assertStringEndsWith(
            (new EpubCredentials($node, $user, self::SALT, 'today'))->getToken(),
            trim($this->output->fetch())
        );
    }

    /**
     * Tests, that unknown nodes, users and dates are reported.
     */
    public function testInvalidArguments(): void
    {
        $node = $this->createNodeMock(29381);
        $user = $this->createUserMock('Erika', 'Muster', 'erika@example.com');

        foreach ([[1, 15, 'today'], [29381, 99, 'today'], [29381, 15, 'no date']] as [$node_id, $user_id, $date]) {
            try {
                $this->createCommand([29381 => $node], [15 => $user])->watermark($node_id, $user_id, $date);
                $this->fail('An exception should have been thrown.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString(
                    1 === $node_id ? 'node' : (99 === $user_id ? 'user' : 'date'),
                    $e->getMessage()
                );
            }
        }
    }
}
