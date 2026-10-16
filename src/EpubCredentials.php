<?php

namespace Drupal\blaetter_export_epub;

use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

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

    private $timestamp;

    private $token;

    private $watermark;

    /**
     * @param NodeInterface $node The node, the ePub is generated for.
     * @param UserInterface $user The user, the ePub is generated for.
     * @param string $salt The salt of the pepgen installation.
     * @param string $timestamp The date of the download as string for strtotime(), e.g. "today", not a timestamp.
     */
    public function __construct(NodeInterface $node, UserInterface $user, $salt, $timestamp = 'today')
    {
        $this->node_id = $node->id();
        $this->first_name = $user->get('field_first_name')->value;
        $this->last_name = $user->get('field_last_name')->value;
        $this->mail = $user->get('mail')->value;
        $this->salt = $salt;
        $this->timestamp = strtotime($timestamp);

        $this->createWatermark();

        $this->createToken();
    }

    private function createToken()
    {
        $this->token = md5(
            $this->node_id .
            $this->salt .
            $this->watermark .
            date('d.m.Y', $this->timestamp)
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
