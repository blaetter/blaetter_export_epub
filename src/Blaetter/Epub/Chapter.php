<?php

namespace Drupal\blaetter_export_epub\Blaetter\Epub;

use Drupal\Component\Utility\Html;
use Drupal\file\Entity\File;

class Chapter
{
    const TAXONOMY_AUTHOR = 'field_autoren';
    const TAXONOMY_RUBRIC = 'field_artikeltyp';
    const TAXONOMY_YEAR = 'field_jahr';
    const TAXONOMY_MONTH = 'field_ausgabe';

    /**
     * The path of the palm logo within the ePub, relative to the chapters.
     *
     * The logo is added from the module by EpubForm, so it is not fetched from the live site during the export.
     */
    const PALM_IMAGE = 'images/palme.jpeg';

    /**
     * The path of the palm logo within the module.
     */
    const PALM_IMAGE_SOURCE = 'templates/image/palme.jpeg';

    public $author;

    public $content;

    public $data;

    public $filename;

    public $footer;

    public $header;

    public $image;

    /**
     * The path of the primary picture in the file system, empty if there is none.
     *
     * @var string
     */
    public $image_file = '';

    public $rubric;

    public $subtitle;

    public $title;

    /**
     * Converts the HTML of the articles into XHTML.
     *
     * @var XhtmlSanitizer|null
     */
    private $sanitizer;

    /**
     * Creates an instance of the cover model from the incoming data.
     */
    public function __construct(object &$data, $header, $footer)
    {
        $this->data         = $data;
        $this->title        = $this->data->getTitle();
        $this->subtitle     = $this->setSubtitle();
        $this->image        = $this->setImage();
        $this->image_file   = $this->setImageFile();
        $this->header       = $header;
        $this->content      = $this->data->get('body')->value;
        $this->rubric       = $this->buildRubric();
        $this->author       = $this->buildAuthorString();
        $this->filename     = $this->data->id();
        $this->footer       = $this->buildFooter($footer);
    }

    /**
     * Sets the sanitizer, e.g. with the base url of the website and a resolver for its images.
     *
     * @param XhtmlSanitizer $sanitizer
     * @return $this
     */
    public function setSanitizer(XhtmlSanitizer $sanitizer)
    {
        $this->sanitizer = $sanitizer;
        return $this;
    }

    /**
     * Returns the sanitizer, by default links and images relative to the website are removed.
     *
     * @return XhtmlSanitizer
     */
    public function getSanitizer()
    {
        return $this->sanitizer ?? new XhtmlSanitizer();
    }

    public function setSubtitle()
    {
        if ($this->data->hasField('field_subtitle')) {
            return $this->data->get('field_subtitle')->value;
        }
        return '';
    }

    public function setImage()
    {
        if ($this->data->hasField('field_primary_picture')
            && $this->data->get('field_primary_picture')->entity instanceof File
        ) {
            // for testing we need to fake the live URLs
            // return str_replace(
            //     'web.blaetter',
            //     'www.blaetter.de',
            //     file_create_url($this->data->get('field_primary_picture')->entity->getFileUri())
            // );

            return \Drupal::service('file_url_generator')
              ->generateAbsoluteString($this->data->get('field_primary_picture')->entity->getFileUri());
        }
        return '';
    }

    /**
     * Returns the path of the primary picture in the file system, so it can be added to the epub without fetching it
     * from the website.
     *
     * @return string
     */
    public function setImageFile()
    {
        if ($this->data->hasField('field_primary_picture')
            && $this->data->get('field_primary_picture')->entity instanceof File
        ) {
            $file = \Drupal::service('file_system')
              ->realpath($this->data->get('field_primary_picture')->entity->getFileUri());
            return false !== $file ? (string) $file : '';
        }
        return '';
    }

    public function getAuthors($include_bios = false)
    {
        $authors = $this->getTaxonomyData(self::TAXONOMY_AUTHOR, $include_bios);
        return $authors;
    }

    public function buildAuthorString()
    {
        $authors = $this->getAuthors();
        return implode(', ', $authors);
    }

    public function buildFooter($footer)
    {
        $output = '';
        if (!empty($this->content)
            && 'story' === $this->data->getType()
            && 'Impressum' != $this->data->getTitle()
            && 'Autorinnen und Autoren' != $this->data->getTitle()
        ) {
            $output .=  '<br /><p class="Ebook-Fliesstext para-style-override-2"><span>' .
              '<img class="frame-4" src="' . self::PALM_IMAGE . '" ' .
              'alt="Eine Insel der Vernunft in einem Meer von Unsinn." /></span></p>';
            $edition = $this->buildEdition();
            $pages = $this->buildPages();
            $image_credit = $this->buildImageCredit();
            $output .= '<p class="Ebook-Fliesstext para-style-override-3"><span class="char-style-override-5">' .
              'Aus: »Blätter« ' . $edition . ', Seite ' . $pages . $image_credit . '</span>' .
              '<span class="char-style-override-5"></span></p>';
        }
        // add the global footer
        $output .= $footer;
        return $output;
    }

    public function buildEdition()
    {
        $months = [
          'Januar'      => '1',
          'Februar'     => '2',
          'März'        => '3',
          'April'       => '4',
          'Mai'         => '5',
          'Juni'        => '6',
          'Juli'        => '7',
          'August'      => '8',
          'September'   => '9',
          'Oktober'     => '10',
          'November'    => '11',
          'Dezember'    => '12'
        ];
        if (!empty($this->data->edition)
            && !empty($this->data->year)
        ) {
            return $months[$this->data->edition] .
              '/' . $this->data->year;
        }
        return '';
    }

    public function buildPages()
    {
        if (!empty($this->data->get('field_seite_bis')->value)
            && !empty($this->data->get('field_seite_von')->value)
        ) {
            return $this->data->get('field_seite_von')->value .
                   '-' . $this->data->get('field_seite_bis')->value;
        }
        return '';
    }

    public function buildImageCredit()
    {
        if (!empty($this->image)) {
            // assuming, that the primary picture thats important here is the first
            // one (there should not be more in any case)
            if (!empty($this->data->field_primary_picture->first()->get('title')->getString())) {
                //@see template.php in the theming folder...
                // Each line of the description is either plain text or "text | url" for a link.
                $image_desc_raw = $this->data->field_primary_picture->first()->get('title')->getString();
                $parts = [];
                foreach (explode("\n", $image_desc_raw) as $line) {
                    if ('' !== trim($line)) {
                        $parts[] = $this->formatImageCreditLine($line);
                    }
                }
                return '<br />Foto: ' . implode(' ', $parts);
            }
        }
        return '';
    }

    /**
     * Formats one line of an image credit as XHTML.
     *
     * The description is plain text entered by the editors, so it is escaped. A link is only created for a valid
     * URL, e.g. "IG Metall Bamberg" or "www.example.com: Foto" are no valid link targets.
     *
     * @param string $line The line, either plain text or "text | url"
     * @return string
     */
    public function formatImageCreditLine($line)
    {
        if (false === strpos($line, '|')) {
            return Html::escape(trim($line));
        }
        [$text, $url] = explode('|', $line, 2);
        $text = Html::escape(trim($text));
        $url = $this->getSanitizer()->normalizeUrl($url);
        if (null === $url) {
            return $text;
        }
        return '<span class="link"><a href="' . Html::escape($url) . '" rel="nofollow">' . $text . '</a></span>';
    }

    public function buildRubric()
    {
        $rubrics = $this->getTaxonomyData(self::TAXONOMY_RUBRIC);
        if (empty($rubrics) || in_array('Chronik des Zeitgeschehens', $rubrics)) {
            $rubrics = ['Extras'];
        }
        return implode(', ', $rubrics);
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function getContent()
    {
        // Title, subtitle, rubric and authors are plain text, so they are escaped. The body is HTML.
        $output = $this->header;
        if (empty($this->rubric) || $this->rubric !== $this->data->rubric) {
            $output .= '<p class="Ebook-Rubriken" id="id-' . md5($this->rubric) . '">' .
              Html::escape($this->rubric) . '<br /><br /></p>';
        }
        $output .= '<p class="Ebook-Titel">' . Html::escape($this->title);
        if (!empty($this->subtitle)) {
            $output .= '<br /><span class="Light">' . Html::escape($this->subtitle) . '</span>';
        }
        // Close the paragraph of the title only once, the line break follows it, if there are authors.
        if (!empty($this->author)) {
            $output .= '<br /><span class="Light">von </span>' . Html::escape($this->author) . '</p><br />';
        } else {
            $output .= '<br /></p>';
        }
        if (!empty($this->image)) {
            $output .= '<div class="Foto-Ebook frame-3">' .
              '<img class="frame-2" src="' . $this->image . '" alt="" /><br /></div>';
        }
        // remove the inserts of blocks, contents and media that the theme renders into the body on the website
        // (e.g. [content:13]), with their paragraph if they stand alone; then wired footnote markup,
        // e.g. <a href="#_ftn5" name="_ftnref5" title=""><span><span><span><span>
        $content = preg_replace(
            [
                '/<hr.*\/>/i',
                '#<p[^>]*>(?:\s|&nbsp;)*\[(?:block|media|content):[^\]]+\](?:\s|&nbsp;)*</p>#i',
                '/\[(?:block|media|content):[^\]]+\]/i',
            ],
            [
                '<hr />',
                '',
                '',
            ],
            $this->content
        );
        $content = str_replace(
            [
                '<p>',
                '<div>',
                '</div>',
                '<h4><strong>',
                '<h4>',
                '</strong></h4>',
                '</h4>',
                'name="_ft',
                '<span><span><span><span>',
                '<span><span><span>',
                '<span><span>',
                '</span></span></span></span>',
                '</span></span></span>',
                '</span></span>',
                '<sup><sup><span>',
                '</sup></sup></span>'
            ],
            [
                '<p class="Ebook-Fliesstext">',
                '<p class="Ebook-Fliesstext">',
                '</p>',
                '<p class="Ebook-ZU">',
                '<p class="Ebook-ZU">',
                '</p>',
                '</p>',
                'id="_ft',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
            ],
            $content
        );
        // convert the HTML of the website into valid XHTML 1.1, as required by EPUB 2
        $output .= $this->getSanitizer()->sanitize($content);
        $output .= $this->footer;
        return $output;
    }

    public function getImage()
    {
        return $this->image;
    }

    public function getRubric()
    {
        return $this->rubric;
    }

    public function getAuthor()
    {
        return $this->author;
    }

    public function getChapterName()
    {
        $output = '';
        if (!empty($this->author)) {
            $output .= $this->author . ': ';
        }
        $output .= $this->title;
        if (!empty($this->subtitle)) {
            $output .= ' ' . $this->subtitle;
        }
        return $output;
    }

    public function getFilename()
    {
        return $this->filename;
    }

    /**
     * get Taxonomy data from field content
     *
     * @param string $field_name The field name to get
     * @param boolean $include_bio Flag to indicate if the bio notice of the author taxonomy should be included or not.
     * @return array $output
     */
    protected function getTaxonomyData($field_name, $include_bio = false)
    {
        $output = [];
        if ($this->data->hasField($field_name)) {
            $field_values = $this->data->get($field_name)->referencedEntities();
            foreach ($field_values as $field_value) {
                if (self::TAXONOMY_AUTHOR === $field_name) {
                    $bio_notice = '';
                    // get sort field if set
                    if ($field_value->hasField('field_sort')) {
                        $last_name = $field_value->get('field_sort')->value;
                    } else {
                        //special handling for authors here, as we need the sorting later
                        $parts = explode(' ', $field_value->getName());
                        $last_name = array_pop($parts);
                    }
                    // The key is used for sorting the authors, the term id keeps authors with the same last name or
                    // sort value apart, while the same author of several articles is still listed only once.
                    $last_name .= "\0" . $field_value->id();

                    // get the bio notice content depending on the availability of the short bio notice or the full
                    // bio notice.
                    if ($field_value->hasField('field_short_bio')
                        && !empty($field_value->get('field_short_bio')->value)
                        && true === $include_bio
                    ) {
                        $bio_notice = ', ' . $field_value->get('field_short_bio')->value;
                    } elseif ($field_value->hasField('field_bio')
                        && !empty($field_value->get('field_bio')->value)
                        && true === $include_bio
                    ) {
                        $bio_notice = ', ' . strip_tags($field_value->get('field_bio')->value);
                    }

                    if (true === $include_bio) {
                        // get the author name and bio notice, remove the author name if it is also provided within the
                        // bio notice
                        $output[$last_name] = '<span class="char-style-override-15">' .
                          Html::escape($field_value->getName()) . '</span>' . str_replace(
                              $field_value->getName() . ',',
                              '',
                              $bio_notice
                          );
                    } else {
                        $output[$last_name] = $field_value->getName();
                    }
                } else {
                    $output[] = $field_value->getName();
                }
            }
        }
        return $output;
    }
}
