<?php

namespace Drupal\blaetter_export_epub\Commands;

use Drupal\blaetter_export_epub\Blaetter\Epub\IssueExporter;
use Drupal\blaetter_export_epub\EpubCredentials;
use Drupal\blaetter_export_epub\PepgenPublisher;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\book\BookManager;
use Drupal\book\BookOutlineStorageInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A drush command file.
 *
 * @package Drupal\blaetter_export_epub\Commands
 */
class EpubCommands extends DrushCommands
{
    /**
     * The config factory.
     *
     * Note: $this->config of DrushCommands holds the Drush configuration, not the Drupal one.
     *
     * @var ConfigFactoryInterface
     */
    protected $configFactory;

    /**
     * The entity type manager.
     *
     * @var EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * The request stack, for the address of the website (drush --uri).
     *
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * The extension path resolver, for the templates of the module.
     *
     * @var ExtensionPathResolver
     */
    protected $pathResolver;

    /**
     * The lock backend, so the export page and drush do not replace the same ePub at the same time.
     *
     * @var LockBackendInterface
     */
    protected $lock;

    /**
     * The book outline storage, for the articles of an issue.
     *
     * @var BookOutlineStorageInterface
     */
    protected $bookOutlineStorage;

    public function __construct(
        ConfigFactoryInterface $config_factory,
        EntityTypeManagerInterface $entity_type_manager,
        RequestStack $request_stack,
        ExtensionPathResolver $path_resolver,
        LockBackendInterface $lock,
        BookOutlineStorageInterface $book_outline_storage
    ) {
        parent::__construct();
        $this->configFactory = $config_factory;
        $this->entityTypeManager = $entity_type_manager;
        $this->requestStack = $request_stack;
        $this->pathResolver = $path_resolver;
        $this->lock = $lock;
        $this->bookOutlineStorage = $book_outline_storage;
    }

    /**
     * Displays the watermark and the token of the ePub of a node for a user.
     *
     * The token is the same, the download controller sends to pepgen at the given date.
     *
     * @param int $node_id
     *   The id of the node (the issue).
     * @param int $user_id
     *   The id of the user that downloads the ePub.
     * @param string $date
     *   The date of the download, e.g. "today" or "2026-10-03".
     * @command blaetter_export_epub:watermark
     * @aliases bl-watermark
     * @usage blaetter_export_epub:watermark node_id user_id [date]
     */
    public function watermark($node_id, $user_id, $date = 'today')
    {
        $this->output()->writeln('Checking Node ' . $node_id . ' for user ' . $user_id);

        $node = $this->entityTypeManager->getStorage('node')->load($node_id);
        if (!$node instanceof NodeInterface) {
            throw new \InvalidArgumentException('There is no node with the id ' . $node_id . '.');
        }
        $user = $this->entityTypeManager->getStorage('user')->load($user_id);
        if (!$user instanceof UserInterface) {
            throw new \InvalidArgumentException('There is no user with the id ' . $user_id . '.');
        }
        if (false === strtotime($date)) {
            throw new \InvalidArgumentException('The date ' . $date . ' is not valid.');
        }

        $credentials = new EpubCredentials(
            $node,
            $user,
            $this->configFactory->get('blaetter_export_epub.settings')->get('pepgen.salt') ?? '',
            $date
        );
        $this->output()->writeln($credentials->getWatermark());
        $this->output()->writeln($credentials->getToken());
    }

    /**
     * Exports issues as ePub and hands them over to pepgen, or writes them unzipped into another directory.
     *
     * Each ePub replaces the existing one of the issue, the previous version is kept as hidden directory
     * ".<nid>.epub.previous". Links relative to the website need the address of the website, so --uri is required.
     *
     * Drush runs as anonymous user, so unpublished articles of an issue are left out (the export page includes them
     * for editors). If an issue has unpublished articles, the command lists them and asks whether to export it anyway;
     * with --yes it does, with --no or --no-interaction the issue is skipped.
     *
     * @param string $node_ids
     *   Comma separated node ids of issues, may be empty with --from.
     * @param array $options
     *   The options of the command.
     * @option from Also export all published issues with a node id from this one on.
     * @option destination Directory to write the ePubs into instead of the original directory of pepgen.
     * @command blaetter_export_epub:export
     * @aliases bl-export
     * @usage drush --uri=https://www.blaetter.de blaetter_export_epub:export 33093
     *   Exports one issue and hands it over to pepgen.
     * @usage drush --uri=https://www.blaetter.de blaetter_export_epub:export --from=29382 --destination=/tmp/epubs
     *   Exports all issues from node 29382 on into /tmp/epubs.
     */
    public function export($node_ids = '', array $options = ['from' => null, 'destination' => null])
    {
        $request = $this->requestStack->getCurrentRequest();
        $host = $request ? $request->getHost() : '';
        if ('' === $host || 'default' === $host) {
            throw new \InvalidArgumentException(
                'The address of the website is unknown, links would point to "' . $host . '". Use --uri, '
                . 'e.g. drush --uri=https://www.blaetter.de blaetter_export_epub:export ...'
            );
        }

        $directory = (string) ($options['destination']
            ?? $this->configFactory->get('blaetter_export_epub.settings')->get('pepgen.original_dir'));
        if ('' === $directory) {
            throw new \InvalidArgumentException(
                'The original directory of pepgen is not set in the settings, use --destination.'
            );
        }
        $problem = PepgenPublisher::checkDirectory($directory);
        if (null !== $problem) {
            throw new \InvalidArgumentException($directory . ': ' . $problem);
        }

        $ids = array_filter(array_map('trim', explode(',', (string) $node_ids)), 'strlen');
        if (null !== $options['from'] && '' !== (string) $options['from']) {
            $ids = array_merge($ids, $this->entityTypeManager->getStorage('node')->getQuery()
                ->accessCheck(false)
                ->condition('type', 'ausgabe')
                ->condition('status', 1)
                ->condition('nid', (int) $options['from'], '>=')
                ->sort('nid')
                ->execute());
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            throw new \InvalidArgumentException('No issues given, use node ids or --from.');
        }

        // the imprint of an existing ePub always comes from pepgen, also when writing into another directory
        $exporter = new IssueExporter(
            $this->pathResolver->getPath('module', 'blaetter_export_epub'),
            (string) $this->configFactory->get('blaetter_export_epub.settings')->get('pepgen.original_dir')
        );
        $publisher = new PepgenPublisher($directory, $this->lock);
        $failed = [];
        $skipped = [];
        $without_fonts = [];
        foreach ($ids as $id) {
            $node = $this->entityTypeManager->getStorage('node')->load($id);
            if (!$node instanceof NodeInterface || 'ausgabe' !== $node->getType()) {
                $this->logger()->error($id . ': no issue.');
                $failed[] = $id;
                continue;
            }
            $unpublished = $this->getUnpublishedArticles($node);
            if ($unpublished) {
                $this->logger()->warning(
                    $id . ': ' . $node->getTitle() . ' has ' . count($unpublished) . ' unpublished articles, drush '
                    . 'leaves them out (the export page includes them): ' . implode(', ', $unpublished)
                );
                if (!$this->io()->confirm('Export ' . $node->getTitle() . ' without these articles?', false)) {
                    $skipped[] = $id;
                    continue;
                }
            }
            try {
                $path = $publisher->publish($exporter->build($node)->getBookData(), $id);
                $this->logger()->success(
                    $id . ': ' . $node->getTitle() . ' -> ' . $path
                    . ($exporter->usedPreviousImprint() ? ' (imprint of the existing ePub)' : ' (current imprint)')
                );
                if ($exporter->getMissingFonts()) {
                    $without_fonts[] = $id;
                }
            } catch (\Throwable $e) {
                $this->logger()->error($id . ': ' . $e->getMessage());
                $failed[] = $id;
            }
            // the entity caches would grow with every issue
            $this->entityTypeManager->getStorage('node')->resetCache();
        }
        if ($without_fonts) {
            $this->logger()->warning(
                'Fonts missing in templates/font/ of the module, so they are not part of the ePubs of '
                . implode(', ', $without_fonts) . ': ' . implode(', ', $exporter->getMissingFonts())
            );
        }
        if ($skipped) {
            $this->logger()->warning('Skipped because of unpublished articles: ' . implode(', ', $skipped));
        }
        if ($failed) {
            throw new \RuntimeException(count($failed) . ' of ' . count($ids) . ' issues failed: ' . implode(', ', $failed));
        }
    }

    /**
     * Returns the unpublished articles in the outline of an issue (the levels the export uses), keyed by node id.
     *
     * @param \Drupal\node\NodeInterface $issue
     *   The issue.
     *
     * @return string[]
     *   The titles of the unpublished articles.
     */
    protected function getUnpublishedArticles(NodeInterface $issue)
    {
        $nids = [];
        $links = $this->bookOutlineStorage->getBookMenuTree((int) $issue->id(), ['max_depth' => 2], 1, BookManager::BOOK_MAX_DEPTH);
        foreach ($links as $link) {
            if ((int) $link->nid !== (int) $issue->id()) {
                $nids[] = (int) $link->nid;
            }
        }
        if (!$nids) {
            return [];
        }
        $storage = $this->entityTypeManager->getStorage('node');
        $unpublished = $storage->getQuery()
            ->accessCheck(false)
            ->condition('nid', $nids, 'IN')
            ->condition('status', 0)
            ->execute();
        return array_map(fn ($node) => $node->getTitle(), $storage->loadMultiple($unpublished));
    }
}
