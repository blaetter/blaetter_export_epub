<?php

namespace Drupal\blaetter_export_epub\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\blaetter_export_epub\Blaetter\Epub\IssueExporter;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Offers the ePub of a single issue as download, for the operations of the export page.
 */
class ExportController extends ControllerBase
{
    /**
     * The extension path resolver, for the templates of the module.
     *
     * @var ExtensionPathResolver
     */
    protected $pathResolver;

    public function __construct(ExtensionPathResolver $path_resolver)
    {
        $this->pathResolver = $path_resolver;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static($container->get('extension.path.resolver'));
    }

    /**
     * Builds the ePub of the issue and sends it.
     *
     * @param \Drupal\node\NodeInterface $node
     *   The issue.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function download(NodeInterface $node)
    {
        if ('ausgabe' !== $node->getType()) {
            throw new NotFoundHttpException();
        }
        $exporter = new IssueExporter($this->pathResolver->getPath('module', 'blaetter_export_epub'));
        return IssueExporter::downloadResponse($exporter->buildFiles([$node]));
    }
}
