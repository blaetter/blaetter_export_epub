<?php

namespace Drupal\blaetter_export_epub\Blaetter\Epub;

use Drupal\Component\Utility\Html;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Builds the ePub of an issue.
 *
 * Used by the export form (download or hand over to pepgen) and by the drush command.
 */
class IssueExporter
{
    /**
     * The placeholder for the watermark within the imprint, pepgen replaces XXXXX with the personal watermark.
     *
     * The watermark is inline text, the line break separates it from the following sentence about the rights of use.
     */
    const IMPRINT_WATERMARK = '<span class="Bold char-style-override-12">XXXXX </span><br />';

    /**
     * The fonts of the ePub: file name in templates/font/ => id in the manifest.
     *
     * The fonts are not part of the repository (copyright), they have to be installed by hand. Missing fonts are left
     * out of the ePub, together with their @font-face rules, so the ePub stays valid.
     */
    const FONTS = [
        'CandidaStd-Bold.otf' => 'CandidaStd-Bold',
        'CandidaStd-Italic.otf' => 'CandidaStd-Italic',
        'CandidaStd-Roman.otf' => 'CandidaStd-Roman',
        'FrutigerLTStd-Bold.otf' => 'FrutigerLTStd-Bold',
        'FrutigerLTStd-Light.otf' => 'FrutigerLTStd-Light',
        'FrutigerLTStd-LightCn.otf' => 'FrutigerLTStd-LightCn',
    ];

    /**
     * The path of the module, relative to the Drupal root or absolute.
     *
     * @var string
     */
    private $module_path;

    /**
     * The fonts of the last export that were not found in templates/font/.
     *
     * @var string[]
     */
    private $missing_fonts = [];

    /**
     * @param string $module_path
     *   The path of the module, relative to the Drupal root or absolute.
     */
    public function __construct(string $module_path)
    {
        $this->module_path = $module_path;
    }

    /**
     * Builds the ePub of the given issue.
     *
     * @param \Drupal\node\NodeInterface $edition
     *   The issue (node of type ausgabe).
     *
     * @return \Drupal\blaetter_export_epub\Blaetter\Epub\Generator
     *   The generator with the complete ePub, ready to be sent or saved.
     */
    public function build(NodeInterface $edition)
    {
        // Since September 2022 only epub is supported.
        $export_type = 'epub';

        // prepare an array for all the authors of this edition
        // we need that later for the author overview page.
        $authors = [];

        // Create an instance of the PHPEpub class. Its wrapped with a custom class to
        // outsource some logic.
        $epub = new Generator(
            $edition->getTitle(),
            "Blätter für deutsche und internationale Politik",
            "https://www.blaetter.de/node/" . $edition->id(),
            "de",
            // links and images relative to the website
            \Drupal::request()->getSchemeAndHttpHost(),
            \Drupal::root(),
            // the public files may be a symlink to a directory outside the Drupal installation
            array_filter([\Drupal::service('file_system')->realpath('public://')])
        );

        // get some template data
        //  - $header_raw: The Markup for the header
        //  - $footer_raw: The Markup for the footer
        //  - $imprint_raw: The Markup for the imprint (it changes not that often so its stored statically)
        //  - $authors_raw: The Markup for the author overview page
        //  - $css_raw: The css that needs to be injected into the book
        $header_raw = file_get_contents(
            $this->module_path . '/templates/head.tmpl'
        );
        $footer_raw = file_get_contents(
            $this->module_path . '/templates/foot.tmpl'
        );
        $imprint_raw = file_get_contents(
            $this->module_path . '/templates/imprint.tmpl'
        );
        $authors_raw = file_get_contents(
            $this->module_path . '/templates/authors.tmpl'
        );
        $css_raw = file_get_contents(
            $this->module_path . '/templates/css/styles.css'
        );

        // Create an instance of Cover to hold the cover data of the epub
        // and add the cover to the epub
        $cover = new Cover(
            $edition,
            str_replace('___TITLE___', Html::escape($edition->getTitle()), $header_raw),
            $footer_raw
        );
        $cover->setExportType($export_type);
        $epub->addCover($cover);

        // Create a table of contents for the epub
        $epub->addToc();

        // Add the styles, without the fonts that are not installed
        $font_path = $this->module_path . '/templates/font/';
        $this->missing_fonts = array_values(array_filter(
            array_keys(self::FONTS),
            fn ($file) => !is_file($font_path . $file)
        ));
        $epub->addStyles(self::removeFontFaces($css_raw, $this->missing_fonts));

        // Get all book pages in the correct order (e.g. weight)
        // Books and its contents are managed via Drupal
        $book_manager = \Drupal::service('book.manager');

        $outline = $book_manager->getTableOfContents($edition->id(), 2);
        $nodes = Node::loadMultiple(array_keys($outline));

        // Loop through all aricles for the current edition, prepare the nodes and
        // create the epub chapters.
        foreach ($nodes as $node) {
            // if we're on the book cover, continue
            if ($node->id() == $edition->id()) {
                continue;
            }
            // store the edition within the node
            $node->edition = $cover->getEdition();
            // store the year within the node
            $node->year = $cover->getYear();
            // store the current rubric within the node
            $node->rubric = $epub->getRubric();
            // create an instance of Chapter from the node.
            $chapter = new Chapter(
                $node,
                str_replace(
                    '___TITLE___',
                    Html::escape($node->getTitle()),
                    $header_raw
                ),
                $footer_raw
            );
            // Check for the rubric of the current node and compare that with the active rubric
            if ($chapter->getRubric() !== $epub->getRubric()) {
                // Change ePub TOC level back to root
                $epub->rootLevel();
                // Set new Rubric as current active rubric
                $epub->setRubric($chapter->getRubric());
                $epub->addChapterWrapper(
                    $epub->getRubric(),
                    $chapter->getFilename() . '.html#' . $epub->getRubricId()
                );
                // Add current sublevel to TOC
                $epub->subLevel();
            }
            $authors = array_merge($authors, $chapter->getAuthors(true));
            // Add the chapter
            $epub->addChapter($chapter);
        }

        // sort the authors by key
        // @see Chapter::getTaxonomyData() for details
        ksort($authors, SORT_LOCALE_STRING);
        // create the markup for the author page
        $authors_string = '<p class="Ebook-Fliesstext-ohneEinzug">' .
                          '' .
                          implode('</p><p class="Ebook-Fliesstext-ohneEinzug">&#160;</p><p class="Ebook-Fliesstext-ohneEinzug">', $authors) .
                          '</p>' .
                          '<p class="Ebook-Fliesstext-ohneEinzug">&#160;</p>';

        // create a fake node object to use it within the Chapter class
        // for the author page
        $data = [];
        $data['nid'] = ['x-default' => '999998'];
        $data['title'] = ['x-default' => 'Autorinnen und Autoren'];
        $data['body'] = [
            'x-default' => [
                0 => [
                    'value' => str_replace('___AUTOREN___', $authors_string, $authors_raw)
                ]
            ]
        ];
        $data['field_subtitle'] = [];
        $authors_node = new Node($data, 'node', 'story');
        $authors_node->rubric = 'Extras';
        $chapter = new Chapter(
            $authors_node,
            str_replace('___TITLE___', Html::escape($authors_node->getTitle()), $header_raw),
            $footer_raw
        );
        $epub->addChapter($chapter);

        // create a fake node object to use it within the Chapter class
        // for the imprint, with the placeholder for the watermark of pepgen
        $data = [];
        $data['nid'] = ['x-default' => '999999'];
        $data['title'] = ['x-default' => 'Impressum'];
        $data['body'] = [
            'x-default' => [
                0 => [
                    'value' => str_replace(
                        '___WATERMARK___',
                        self::IMPRINT_WATERMARK,
                        $imprint_raw
                    )
                ]
            ]
        ];
        $data['field_subtitle'] = [];
        $imprint_node = new Node($data, 'node', 'story');
        $imprint_node->rubric = 'Extras';
        $chapter = new Chapter(
            $imprint_node,
            str_replace('___TITLE___', Html::escape($imprint_node->getTitle()), $header_raw),
            $footer_raw
        );
        $epub->addChapter($chapter);

        // add the palm logo below the articles from the module, so it is not fetched from the live site.
        $epub->addLargeFile(
            Chapter::PALM_IMAGE,
            'palme',
            $this->module_path . '/' . Chapter::PALM_IMAGE_SOURCE,
            'image/jpeg'
        );

        // add the installed fonts to the archive as they're not added automatically for
        // whatever reason. A missing file would still end up in the manifest.
        foreach (self::FONTS as $file => $id) {
            if (!in_array($file, $this->missing_fonts, true)) {
                $epub->addLargeFile('font/' . $file, $id, $font_path . $file, 'font/opentype');
            }
        }

        return $epub;
    }

    /**
     * Builds the ePubs of the given issues one after the other and writes each into a temporary file.
     *
     * Only one ePub is held in memory at a time, so many issues can be downloaded together.
     *
     * @param \Drupal\node\NodeInterface[] $editions
     *   The issues.
     *
     * @return string[]
     *   The temporary files keyed by the file name of the ePub, e.g. "33093.epub".
     *
     * @throws \RuntimeException
     *   If a temporary file could not be written; the files written so far are removed.
     */
    public function buildFiles(array $editions)
    {
        $files = [];
        try {
            foreach ($editions as $edition) {
                $files[$edition->id() . '.epub'] = self::writeTemporaryFile($this->build($edition)->getBookData());
            }
        } catch (\Throwable $e) {
            array_map('unlink', $files);
            throw $e;
        }
        return $files;
    }

    /**
     * Returns the response that offers the given ePubs as download: one ePub as it is, several as zip archive.
     *
     * The temporary files are removed after sending, or right away if the response could not be built.
     *
     * @param string[] $files
     *   Temporary files with the ePubs keyed by their file name, e.g. "33093.epub" (see buildFiles()).
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     *
     * @throws \RuntimeException
     *   If the zip archive could not be written.
     */
    public static function downloadResponse(array $files)
    {
        if (1 === count($files)) {
            return self::fileResponse(reset($files), (string) key($files), 'application/epub+zip');
        }

        $zip_file = self::writeTemporaryFile('');
        try {
            $zip = new \ZipArchive();
            if (true !== $zip->open($zip_file, \ZipArchive::OVERWRITE)) {
                throw new \RuntimeException('The zip archive for the download could not be created.');
            }
            foreach ($files as $name => $path) {
                // the ePubs are zip archives already, they are stored as they are; addFile() reads them on close()
                $zip->addFile($path, $name);
                $zip->setCompressionName($name, \ZipArchive::CM_STORE);
            }
            if (!$zip->close()) {
                throw new \RuntimeException('The zip archive for the download could not be written.');
            }
        } catch (\Throwable $e) {
            @unlink($zip_file);
            throw $e;
        } finally {
            array_map('unlink', $files);
        }
        return self::fileResponse($zip_file, 'blaetter-epubs-' . date('Y-m-d') . '.zip', 'application/zip');
    }

    /**
     * Writes the data into a new temporary file and returns its path.
     *
     * @throws \RuntimeException
     */
    public static function writeTemporaryFile(string $data)
    {
        $file = tempnam(sys_get_temp_dir(), 'blaetter-epub-');
        if (false === $file) {
            throw new \RuntimeException('No temporary file could be created.');
        }
        if (false === file_put_contents($file, $data)) {
            @unlink($file);
            throw new \RuntimeException('The temporary file could not be written.');
        }
        return $file;
    }

    /**
     * Returns the download of a temporary file, which is removed after sending.
     */
    private static function fileResponse(string $path, string $name, string $content_type)
    {
        $response = new BinaryFileResponse($path, 200, ['Cache-Control' => 'private, no-store'], false);
        $response->headers->set('Content-Type', $content_type);
        $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name);
        $response->deleteFileAfterSend(true);
        return $response;
    }

    /**
     * Returns the fonts of the last export that were not found and are therefore not part of the ePub.
     *
     * @return string[]
     *   File names, e.g. CandidaStd-Bold.otf.
     */
    public function getMissingFonts()
    {
        return $this->missing_fonts;
    }

    /**
     * Removes the @font-face rules that refer to one of the given font files.
     *
     * The font families stay in the other rules, readers fall back to their own fonts.
     *
     * @param string $css
     *   The styles of the ePub.
     * @param string[] $font_files
     *   File names of the fonts, e.g. CandidaStd-Bold.otf.
     *
     * @return string
     *   The styles without these @font-face rules.
     */
    public static function removeFontFaces($css, array $font_files)
    {
        if (!$font_files) {
            return $css;
        }
        return preg_replace_callback(
            '/@font-face\s*\{[^}]*\}\s*/i',
            function ($match) use ($font_files) {
                foreach ($font_files as $file) {
                    if (false !== stripos($match[0], $file)) {
                        return '';
                    }
                }
                return $match[0];
            },
            $css
        );
    }
}
