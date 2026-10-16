<?php

namespace Drupal\Tests\blaetter_export_epub\Unit\Blaetter\Epub;

use Drupal\blaetter_export_epub\Blaetter\Epub\Chapter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the footer below the articles.
 *
 * @group blaetter_export_epub
 */
#[CoversClass(Chapter::class)]
#[Group('blaetter_export_epub')]
class ChapterFooterTest extends UnitTestCase
{
    /**
     * Tests, that the palm logo is referenced within the ePub and not on the live site.
     */
    public function testPalmImageIsPartOfTheEpub(): void
    {
        $this->assertDoesNotMatchRegularExpression('#^[a-z]+://|^/#i', Chapter::PALM_IMAGE);
        $this->assertFileExists(dirname(__DIR__, 5) . '/' . Chapter::PALM_IMAGE_SOURCE);

        $chapter = (new \ReflectionClass(Chapter::class))->newInstanceWithoutConstructor();
        $chapter->content = '<p>Text</p>';
        $chapter->image = '';
        $chapter->data = new class {
            public $edition = 'Dezember';
            public $year = '2018';

            public function getType()
            {
                return 'story';
            }

            public function getTitle()
            {
                return 'Identität durch Erinnerung';
            }

            public function get($field_name)
            {
                return (object) ['value' => ['field_seite_von' => '45', 'field_seite_bis' => '56'][$field_name]];
            }
        };

        $footer = $chapter->buildFooter('</body></html>');

        $this->assertStringContainsString('<img class="frame-4" src="images/palme.jpeg" ', $footer);
        $this->assertStringNotContainsString('blaetter.de', $footer);
        $this->assertStringContainsString('Aus: »Blätter« 12/2018, Seite 45-56', $footer);
    }
}
