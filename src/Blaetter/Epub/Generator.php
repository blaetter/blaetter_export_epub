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
     * The scheme and host of the website, e.g. https://www.blaetter.de
     *
     * @var string
     */
    private $base_url;

    /**
     * The root directory of the Drupal installation, images of the website are read from it.
     *
     * @var string|null
     */
    private $local_root;

    /**
     * The images of the website, that were added to the epub, keyed by their source.
     *
     * @var array
     */
    private $local_images = [];

    /**
     * Image types that may be added to an EPUB 2.
     */
    const IMAGE_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
    ];

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
     * @param string $base_url
     *  The scheme and host of the website, used for links relative to the website
     * @param string|null $local_root
     *  The root directory of the Drupal installation, images of the website are read from it
     */
    public function __construct($title, $name, $author, $identifier, $language, $base_url = '', $local_root = null)
    {
        $this->base_url = (string) $base_url;
        $this->local_root = $local_root;
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
        $chapter->setSanitizer(new XhtmlSanitizer($this->base_url, [$this, 'addLocalImage']));
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
        // The chapter names are not escaped by PHPePub, see addChapter().
        return $this->epub->addChapter(
            htmlspecialchars($title),
            $filename
        );
    }

    /**
     * Adds an image of the website to the epub.
     *
     * Images within the articles are referenced relative to the website, e.g. "sites/default/files/image.jpg".
     * They are read from the file system, so the export does not depend on the website being reachable.
     *
     * @param string $src The src of the image
     * @return string|null The path of the image within the epub, null if it is not available
     */
    public function addLocalImage($src)
    {
        if (isset($this->local_images[$src])) {
            return $this->local_images[$src];
        }
        $path = rawurldecode((string) parse_url($src, PHP_URL_PATH));
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $root = null !== $this->local_root ? realpath($this->local_root) : false;
        $file = false !== $root ? realpath($root . '/' . ltrim($path, '/')) : false;

        // only image files within the Drupal installation
        if (false === $file
            || !isset(self::IMAGE_TYPES[$extension])
            || 0 !== strpos($file, $root . DIRECTORY_SEPARATOR)
            || !is_file($file)
        ) {
            return $this->local_images[$src] = null;
        }

        $id = 'local-' . md5($file);
        $internal_path = 'images/' . $id . '.' . $extension;
        // the same file may be referenced with different sources, e.g. with or without a leading slash
        if (!in_array($internal_path, $this->local_images, true)
            && false === $this->epub->addLargeFile($internal_path, $id, $file, self::IMAGE_TYPES[$extension])
        ) {
            return $this->local_images[$src] = null;
        }
        return $this->local_images[$src] = $internal_path;
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
