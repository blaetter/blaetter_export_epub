<?php

namespace Drupal\Tests\blaetter_export_epub\Unit\Blaetter\Epub;

use Drupal\blaetter_export_epub\Blaetter\Epub\Chapter;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the authors of a chapter, used for the byline and the author page of the ePub.
 *
 * @group blaetter_export_epub
 */
#[CoversClass(Chapter::class)]
#[Group('blaetter_export_epub')]
class ChapterAuthorsTest extends UnitTestCase
{
    /**
     * Creates an author term.
     *
     * @param int $tid The term id
     * @param string $name The name of the author
     * @param string|null $sort The sort value, null if the term has no sort field
     * @param string $bio The bio notice
     */
    private function createAuthor(int $tid, string $name, ?string $sort, string $bio = ''): TermInterface
    {
        $values = [
            'field_sort' => $sort,
            'field_short_bio' => '',
            'field_bio' => $bio,
        ];
        $term = $this->createMock(TermInterface::class);
        $term->method('id')->willReturn($tid);
        $term->method('getName')->willReturn($name);
        $term->method('hasField')->willReturnCallback(
            fn ($field) => array_key_exists($field, $values) && ('field_sort' !== $field || null !== $sort)
        );
        $term->method('get')->willReturnCallback(fn ($field) => (object) ['value' => $values[$field]]);
        return $term;
    }

    /**
     * Creates a chapter for an article with the given authors.
     *
     * The constructor needs a complete node, so only the data used for the authors is set.
     */
    private function createChapter(array $authors): Chapter
    {
        $field = new class ($authors) {
            public function __construct(private array $authors)
            {
            }

            public function referencedEntities()
            {
                return $this->authors;
            }
        };

        $node = $this->createMock(NodeInterface::class);
        $node->method('hasField')->willReturnCallback(fn ($field_name) => Chapter::TAXONOMY_AUTHOR === $field_name);
        $node->method('get')->willReturn($field);

        $chapter = (new \ReflectionClass(Chapter::class))->newInstanceWithoutConstructor();
        $chapter->data = $node;
        return $chapter;
    }

    /**
     * Builds the author page entries like EpubForm::submitForm() does.
     */
    private function buildAuthorPage(Chapter ...$chapters): array
    {
        $authors = [];
        foreach ($chapters as $chapter) {
            $authors = array_merge($authors, $chapter->getAuthors(true));
        }
        ksort($authors, SORT_LOCALE_STRING);
        return array_values($authors);
    }

    /**
     * Tests, that authors with the same sort value are both listed.
     */
    public function testAuthorsWithSameSortValue(): void
    {
        $chapter = $this->createChapter([
            $this->createAuthor(3935, 'Christine Wicht', 'Wicht, Christine'),
            $this->createAuthor(3810, 'Christine Wicht', 'Wicht, Christine'),
        ]);

        $this->assertCount(2, $chapter->getAuthors());
        $this->assertCount(2, $chapter->getAuthors(true));
    }

    /**
     * Tests, that authors without sort field and with the same last name are both listed.
     */
    public function testAuthorsWithSameLastName(): void
    {
        $chapter = $this->createChapter([
            $this->createAuthor(1, 'Aleida Assmann', null),
            $this->createAuthor(2, 'Jan Assmann', null),
        ]);

        $this->assertSame('Aleida Assmann, Jan Assmann', $chapter->buildAuthorString());
        $this->assertSame(
            [
                '<span class="char-style-override-15">Aleida Assmann</span>',
                '<span class="char-style-override-15">Jan Assmann</span>',
            ],
            $this->buildAuthorPage($chapter)
        );
    }

    /**
     * Tests, that the names on the author page are escaped, while the bio notice is HTML.
     */
    public function testAuthorNameIsEscaped(): void
    {
        $chapter = $this->createChapter([
            $this->createAuthor(
                576,
                'Orion Reith & Co.',
                'Reith & Co., Orion',
                'Orion Reith & Co., Reederei in <em>Hamburg</em>'
            ),
        ]);

        $this->assertSame(
            ['<span class="char-style-override-15">Orion Reith &amp; Co.</span>,  Reederei in Hamburg'],
            $this->buildAuthorPage($chapter)
        );
        // The byline is escaped, when the content of the chapter is built.
        $this->assertSame('Orion Reith & Co.', $chapter->buildAuthorString());
    }

    /**
     * Tests, that the author page lists every author once, sorted by the sort value.
     */
    public function testAuthorPageIsSortedAndUnique(): void
    {
        $sennett = $this->createAuthor(10, 'Richard Sennett', 'Sennett, Richard');
        $page = $this->buildAuthorPage(
            $this->createChapter([$sennett]),
            $this->createChapter([
                $sennett,
                $this->createAuthor(11, 'Andrej Holm', 'Holm, Andrej', 'Andrej Holm, geb. 1970 in Leipzig'),
            ]),
            $this->createChapter([
                $this->createAuthor(12, 'Jan Assmann', 'Assmann, Jan'),
                $this->createAuthor(13, 'Aleida Assmann', 'Assmann, Aleida'),
            ]),
            $this->createChapter([$this->createAuthor(14, 'Janek Assmann', 'Assmann, Janek')])
        );

        $this->assertSame(
            [
                '<span class="char-style-override-15">Aleida Assmann</span>',
                '<span class="char-style-override-15">Jan Assmann</span>',
                '<span class="char-style-override-15">Janek Assmann</span>',
                // The name at the beginning of the bio notice is removed.
                '<span class="char-style-override-15">Andrej Holm</span>,  geb. 1970 in Leipzig',
                '<span class="char-style-override-15">Richard Sennett</span>',
            ],
            $page
        );
    }
}
