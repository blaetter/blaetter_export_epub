<?php

namespace Drupal\Tests\blaetter_export_epub\Unit;

use Drupal\blaetter_export_epub\EpubCredentials;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the watermark and the token sent to pepgen.
 *
 * @group blaetter_export_epub
 */
#[CoversClass(EpubCredentials::class)]
#[Group('blaetter_export_epub')]
class EpubCredentialsTest extends UnitTestCase
{
    use EpubTestTrait;

    /**
     * Tests, that the watermark contains the name and mail of the user and is escaped.
     */
    public function testWatermark(): void
    {
        $credentials = new EpubCredentials(
            $this->createNodeMock(29381),
            $this->createUserMock('Erika', 'Muster & Co', 'erika@example.com'),
            'salt'
        );

        $this->assertSame('Erika Muster &amp; Co, E-Mail: erika@example.com', $credentials->getWatermark());
    }

    /**
     * Tests, that the token is built from node, salt, watermark and date of the download.
     */
    public function testToken(): void
    {
        $credentials = new EpubCredentials(
            $this->createNodeMock(29381),
            $this->createUserMock('Erika', 'Muster', 'erika@example.com'),
            'salt',
            '2026-10-03'
        );

        $this->assertSame(
            md5('29381saltErika Muster, E-Mail: erika@example.com03.10.2026'),
            $credentials->getToken()
        );
    }

    /**
     * Tests, that the token is valid for the whole day and changes with the date.
     */
    public function testTokenDependsOnDayOnly(): void
    {
        $node = $this->createNodeMock(29381);
        $user = $this->createUserMock('Erika', 'Muster', 'erika@example.com');

        $morning = new EpubCredentials($node, $user, 'salt', '2026-10-03 08:00');
        $evening = new EpubCredentials($node, $user, 'salt', '2026-10-03 22:00');
        $next_day = new EpubCredentials($node, $user, 'salt', '2026-10-04 08:00');

        $this->assertSame($morning->getToken(), $evening->getToken());
        $this->assertNotSame($morning->getToken(), $next_day->getToken());
    }

    /**
     * Tests, that the default date is today.
     */
    public function testDefaultDateIsToday(): void
    {
        $node = $this->createNodeMock(29381);
        $user = $this->createUserMock('Erika', 'Muster', 'erika@example.com');

        $this->assertSame(
            (new EpubCredentials($node, $user, 'salt', date('Y-m-d')))->getToken(),
            (new EpubCredentials($node, $user, 'salt'))->getToken()
        );
    }

    /**
     * Tests, that the token depends on the salt, the node and the user.
     */
    public function testTokenDependsOnSaltNodeAndUser(): void
    {
        $user = $this->createUserMock('Erika', 'Muster', 'erika@example.com');
        $token = (new EpubCredentials($this->createNodeMock(29381), $user, 'salt'))->getToken();

        $this->assertNotSame(
            $token,
            (new EpubCredentials($this->createNodeMock(29381), $user, 'other salt'))->getToken()
        );
        $this->assertNotSame(
            $token,
            (new EpubCredentials($this->createNodeMock(29382), $user, 'salt'))->getToken()
        );
        $this->assertNotSame(
            $token,
            (new EpubCredentials(
                $this->createNodeMock(29381),
                $this->createUserMock('Max', 'Muster', 'max@example.com'),
                'salt'
            ))->getToken()
        );
    }
}
