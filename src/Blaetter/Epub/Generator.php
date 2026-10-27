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
     * Further directories images may be read from, e.g. the public files directory when it is a symlink.
     *
     * @var string[]
     */
    private $allowed_roots = [];

    /**
     * The images of the website, that were added to the epub, keyed by their source.
     *
     * @var array
     */
    private $local_images = [];

    /**
     * Image types that may be added to an EPUB 2: type of getimagesize() => [extension, media type].
     *
     * The type is taken from the content of the file, not from its extension: a JPEG saved as .png on the website
     * would otherwise be declared as image/png and make the ePub invalid.
     */
    const IMAGE_TYPES = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_GIF => ['gif', 'image/gif'],
    ];

    /**
     * Creates an instance of the generator
     *
     * @param string $title
     *  The title of the epub
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
     * @param string[] $allowed_roots
     *  Further directories images may be read from, e.g. the public files directory, which may be a symlink to a
     *  directory outside the Drupal installation
     */
    public function __construct(
        $title,
        $author,
        $identifier,
        $language,
        $base_url = '',
        $local_root = null,
        array $allowed_roots = []
    ) {
        $this->base_url = (string) $base_url;
        $this->local_root = $local_root;
        $this->allowed_roots = $allowed_roots;
        $this->epub = new EPub();
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
        $this->useImageFile($chapter);
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
        // The cover image is read from the file system: fetching it from the website fails, when the website is not
        // reachable from the server, e.g. behind a password like the stage server.
        if (!empty($cover->image_file)) {
            $this->epub->setCoverImage($cover->image_file);
        }
        $this->useImageFile($cover);
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
        if (array_key_exists($src, $this->local_images)) {
            return $this->local_images[$src];
        }
        $path = rawurldecode((string) parse_url($src, PHP_URL_PATH));
        $root = null !== $this->local_root ? realpath($this->local_root) : false;
        $file = false !== $root && '' !== $path ? realpath($root . '/' . ltrim($path, '/')) : false;

        return $this->local_images[$src] = false !== $file ? $this->addImageFile($file) : null;
    }

    /**
     * Adds an image file to the epub.
     *
     * Only image files within the Drupal installation or one of the allowed roots are added.
     *
     * @param string $file The path of the image in the file system
     * @return string|null The path of the image within the epub, null if it is not available
     */
    public function addImageFile($file)
    {
        $file = '' !== (string) $file ? realpath($file) : false;
        if (false === $file || !is_file($file) || !$this->isAllowedFile($file)) {
            return null;
        }
        $info = @getimagesize($file);
        if (false === $info || !isset(self::IMAGE_TYPES[$info[2]])) {
            return null;
        }
        [$extension, $media_type] = self::IMAGE_TYPES[$info[2]];

        $id = 'local-' . md5($file);
        $internal_path = 'images/' . $id . '.' . $extension;
        // the same file may be referenced several times, e.g. with or without a leading slash
        if (!in_array($internal_path, $this->local_images, true)
            && false === $this->epub->addLargeFile($internal_path, $id, $file, $media_type)
        ) {
            return null;
        }
        return $this->local_images[$file] = $internal_path;
    }

    /**
     * Checks, that a file is within the Drupal installation or one of the allowed roots.
     *
     * @param string $file The real path of the file
     * @return bool
     */
    private function isAllowedFile($file)
    {
        foreach (array_merge([$this->local_root], $this->allowed_roots) as $root) {
            $root = null !== $root ? realpath($root) : false;
            if (false !== $root && 0 === strpos($file, $root . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Uses the image file of a chapter within the epub instead of its URL.
     *
     * Images referenced by URL would be fetched from the website by PHPePub, which fails when the website is not
     * reachable from the server. Without an image file, the chapter has no image.
     *
     * @param Chapter $chapter
     */
    private function useImageFile(Chapter $chapter)
    {
        $chapter->image = (string) $this->addImageFile((string) $chapter->image_file);
    }

    public function addToc()
    {
        $this->epub->buildTOC(null, "toc", "Inhaltsverzeichnis");
    }

    public function addLargeFile($fileName, $fileId, $filePath, $mimetype)
    {
        return $this->epub->addLargeFile($fileName, $fileId, $filePath, $mimetype);
    }

    /**
     * Returns the finished ePub as zip data, for the download or the hand over to pepgen.
     *
     * @return string
     *   The ePub (zip archive) in binary form.
     */
    public function getBookData()
    {
        return $this->epub->getBook();
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
