<?php

namespace Drupal\blaetter_export_epub\Commands;

use Drupal\blaetter_export_epub\EpubCredentials;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drush\Commands\DrushCommands;

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

    public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager)
    {
        parent::__construct();
        $this->configFactory = $config_factory;
        $this->entityTypeManager = $entity_type_manager;
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
}
