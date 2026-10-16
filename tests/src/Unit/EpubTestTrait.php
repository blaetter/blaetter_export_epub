<?php

namespace Drupal\Tests\blaetter_export_epub\Unit;

use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Provides mocked nodes and users for the tests of the ePub export.
 */
trait EpubTestTrait
{
    /**
     * Creates a node mock with the given id.
     */
    protected function createNodeMock(int $node_id): NodeInterface
    {
        $node = $this->createMock(NodeInterface::class);
        $node->method('id')->willReturn($node_id);
        return $node;
    }

    /**
     * Creates a user mock with the fields used for the watermark.
     */
    protected function createUserMock(string $first_name, string $last_name, string $mail): UserInterface
    {
        $values = [
            'field_first_name' => $first_name,
            'field_last_name' => $last_name,
            'mail' => $mail,
        ];
        $user = $this->createMock(UserInterface::class);
        $user->method('get')->willReturnCallback(
            fn (string $field_name) => (object) ['value' => $values[$field_name]]
        );
        return $user;
    }
}
