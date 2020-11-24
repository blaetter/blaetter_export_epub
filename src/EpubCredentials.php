<?php

namespace Drupal\blaetter_export_epub;

use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

/**
 * Class EpubCredentials.
 *
 * @package Drupal\blaetter_export_epub
 */
class EpubCredentials
{

    private $first_name;

    private $last_name;

    private $mail;

    private $node_id;

    private $salt;

    private $token;

    private $watermark;

    public function __construct(Node $node, User $user, $salt)
    {
        $this->node_id = $node->id();
        $this->first_name = $user->get('field_first_name')->value;
        $this->last_name = $user->get('field_last_name')->value;
        $this->mail = $user->get('mail')->value;
        $this->salt = $salt;

        $this->createWatermark();

        $this->createToken();
    }

    private function createToken()
    {
        $this->token = md5(
            $this->node_id .
            $this->salt .
            $this->watermark .
            strftime("%d.%m.%Y")
        );
        return $this;
    }

    private function createWatermark()
    {
        $this->watermark = htmlspecialchars(
            $this->first_name .
            ' ' .
            $this->last_name .
            ', E-Mail: ' .
            $this->mail
        );
        return $this;
    }

    public function getWatermark()
    {
        return $this->watermark;
    }

    public function getToken()
    {
        return $this->token;
    }
}
