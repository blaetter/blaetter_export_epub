<?php

namespace Drupal\Tests\blaetter_export_epub\Unit\Routing;

use Drupal\blaetter_export_epub\Routing\EpubRoutes;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the dynamic routes of the ePub export.
 *
 * @group blaetter_export_epub
 */
#[CoversClass(EpubRoutes::class)]
#[Group('blaetter_export_epub')]
class EpubRoutesTest extends UnitTestCase
{
    /**
     * Tests, that only the ePub download is provided and checks the access to the node.
     */
    public function testOnlyEpubDownloadIsProvided(): void
    {
        $routes = (new EpubRoutes())->routes();

        $this->assertSame(['blaetter_export_epub.epub.download'], array_keys($routes->all()));

        $route = $routes->get('blaetter_export_epub.epub.download');
        $this->assertSame('/download/epub/{node}', $route->getPath());
        $this->assertSame(
            '\Drupal\blaetter_export_epub\Controller\DownloadController::access',
            $route->getRequirement('_custom_access')
        );
    }
}
