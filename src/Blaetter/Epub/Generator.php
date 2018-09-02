<?php

namespace Drupal\blaetter_export_epub\Blaetter\Epub;

use Drupal\blaetter_export_epub\Blaetter\Epub\Chapter;
use PHPePub\Core\EPub;

class Generator
{

    /**
     * Holds the epub
     */
    public $epub;

    /**
     * Holds the name of the ePub-File (for download)
     */
    public $name;

    /**
     * Holds the current rubric
     */
    public $rubric;

    /**
     * Creates an instance of the generator
     *
     * @param string $title
     *  The title of the epub
     * @param string $name
     *  The filename of the epub
     * @param string $author
     *  The author of the epub
     * @param string $identifier
     *  The unique identifier of the epub
     * @param string $language
     *  The language of the epub
     */
    public function __construct($title, $name, $author, $identifier, $language)
    {
        $this->epub = new EPub();
        $this->name = $name;
        $this->epub->setTitle($title);
        $this->epub->setAuthor($author, $author);
        $this->epub->setIdentifier($identifier . "&amp;stamp=" . time(), EPub::IDENTIFIER_URI);
        $this->epub->setLanguage($language);
    }

    public function addStyles($styles)
    {
        $this->epub->addCSSFile("styles.css", "css1", $styles);
    }

    public function addChapter(Chapter $chapter)
    {
        return $this->epub->addChapter(
            htmlspecialchars($chapter->getChapterName()),
            $chapter->getFilename().".html",
            $chapter->getContent(),
            false,
            EPub::EXTERNAL_REF_ADD
        );
    }

    public function addCover(Cover $cover)
    {
        $this->epub->setCoverImage($cover->getImage());
        return $this->epub->addChapter(
            'Titelbild',
            $cover->getFilename().".html",
            $cover->getContent(),
            false,
            EPub::EXTERNAL_REF_ADD
        );
    }

    public function addChapterWrapper($title, $filename)
    {
        return $this->epub->addChapter(
            $title,
            $filename
        );
    }

    public function addToc()
    {
        $this->epub->buildTOC(null, "toc", "Inhaltsverzeichnis");
    }

    public function addLargeFile($fileName, $fileId, $filePath, $mimetype)
    {
        return $this->epub->addLargeFile($fileName, $fileId, $filePath, $mimetype);
    }

    public function createEpub()
    {
        $this->epub->finalize();
        return $this->epub->sendBook($this->name);
    }

    public function getRubric()
    {
        return $this->rubric;
    }

    public function getRubricId()
    {
        return 'id-' . md5($this->rubric);
    }

    public function setRubric($rubric)
    {
        $this->rubric = $rubric;
        return $this;
    }

    public function subLevel()
    {
        return $this->epub->subLevel();
    }

    public function rootLevel()
    {
        return $this->epub->rootLevel();
    }
}
