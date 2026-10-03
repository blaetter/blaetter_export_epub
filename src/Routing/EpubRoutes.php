<?php
/**
 * @file
 * Contains \Drupal\blaetter_export_epub\Routing\EpubRoutes.
 */

namespace Drupal\blaetter_export_epub\Routing;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Defines dynamic routes for the ePub export.
 */
class EpubRoutes
{
    use StringTranslationTrait;

    /**
    * {@inheritdoc}
    */
    public function routes()
    {
        $route_collection = new RouteCollection();

        // Route for downloading the watermarked ePub of an issue.
        $route_collection->add(
            'blaetter_export_epub.epub.download',
            new Route(
                // Path to attach this route to:
                '/download/epub/{node}',
                // Route defaults:
                [
                    '_controller' => '\Drupal\blaetter_export_epub\Controller\DownloadController::downloadEpub',
                ],
                // Route requirements:
                [
                    '_custom_access' => '\Drupal\blaetter_export_epub\Controller\DownloadController::access'
                ],
                // Route Options
                [
                    'parameters' => [
                        'node' => [
                            'type' => 'entity:node',
                        ]
                    ]
                ]
            )
        );

        return $route_collection;
    }
}
