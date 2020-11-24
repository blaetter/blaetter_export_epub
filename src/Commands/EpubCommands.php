<?php

namespace Drupal\blaetter_export_epub\Commands;

use Drupal\blaetter_export_epub\EpubCredentials;
use Drush\Commands\DrushCommands;
use Drupal\user\Entity\User;
use Drupal\node\Entity\Node;

/**
 * A drush command file.
 *
 * @package Drupal\blaetter_export_epub\Commands
 */
class EpubCommands extends DrushCommands
{

    /**
     * Drush command that displays the given text.
     *
     * @param int $node_id
     * @param int $user_id
     *   Argument with message to be displayed.
     * @command blaetter_export_epub:watermark
     * @aliases bl-watermark
     * @usage blaetter_export_epub:watermark node_id user_id
     */
    public function watermark($node_id, $user_id, $date = 'today')
    {
        $this->output()->writeln('Checking Node ' . $node_id . ' for user ' . $user_id);

        $user = User::load($user_id);
        $node = Node::load($node_id);

        $credentials = new EpubCredentials(
            $node,
            $user,
            $this->config->get('pepgen.salt', ''),
            strftime($date)
        );
        $this->output()->writeln($credentials->getWatermark());
        $this->output()->writeln($credentials->getToken());
    }
}
