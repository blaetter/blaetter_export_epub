<?php

namespace Drupal\blaetter_export_epub\Blaetter\Epub;

use Drupal\file\Entity\File;

class Chapter
{
    const TAXONOMY_AUTHOR = 'field_autoren';
    const TAXONOMY_RUBRIC = 'field_artikeltyp';
    const TAXONOMY_YEAR = 'field_jahr';
    const TAXONOMY_MONTH = 'field_ausgabe';
    const MODULE_PATH_PREFIX = 'https://www.blaetter.de/modules/contrib/blaetter_export_epub';

    public $title;

    public $content;

    public $rubric;

    public $image;

    public $author;

    public $filename;

    public $header;

    public $footer;

    /**
     * Creates an instance of the cover model from the incoming data.
     */
    public function __construct(object &$data, $header, $footer)
    {
        $this->data         = $data;
        $this->title        = $this->data->getTitle();
        $this->subtitle     = $this->setSubtitle();
        $this->image        = $this->setImage();
        $this->header       = $header;
        $this->content      = $this->data->get('body')->value;
        $this->rubric       = $this->buildRubric();
        $this->author       = $this->buildAuthorString();
        $this->filename     = $this->data->id();
        $this->footer       = $this->buildFooter($footer);
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
            return file_create_url($this->data->get('field_primary_picture')->entity->getFileUri());
        }
        return '';
    }

    public function getAuthors()
    {
        $authors = $this->getTaxonomyData(self::TAXONOMY_AUTHOR);
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
              '<img class="frame-4" src="' . self::MODULE_PATH_PREFIX . '/templates/image/palme.jpeg" ' .
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
                $image_desc_raw = $this->data->field_primary_picture->first()->get('title')->getString();
                if (!empty($image_desc_raw)) {
                    //initiate image description string
                    $image_desc = '';
                    if (false !== strpos($image_desc_raw, "\n")) {
                        $data = explode("\n", $image_desc_raw);
                        foreach ($data as $line) {
                            if (false !== strpos($line, "|")) {
                                $data = explode("|", $line);
                                $image_desc .= '<span class="link">' .
                                  '<a href="'.trim($data[1]).'" target="_blank" rel="nofollow">'.trim($data[0]).'</a>' .
                                  '</span>';
                            } else {
                                $image_desc .= trim($line);
                            }
                        }
                    } elseif (false !== strpos($image_desc_raw, "|")) {
                        // here we have a pipe that indicates links within the descrption
                        $data = explode("|", $image_desc_raw);
                        $image_desc = '<span class="link">' .
                          '<a href="'.trim($data[1]).'" target="_blank" rel="nofollow">'.trim($data[0]).'</a></span>';
                    } else {
                        $image_desc = trim($image_desc_raw);
                    }
                }
                return '<br />Foto: ' . $image_desc;
            }
        }
        return '';
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
        $output = $this->header;
        if (empty($this->rubric) || $this->rubric !== $this->data->rubric) {
            $output .= '<p class="Ebook-Rubriken" id="id-' . md5($this->rubric) . '">' .
              $this->rubric . '<br /><br /></p>';
        }
        $output .= '<p class="Ebook-Titel">' . $this->title;
        if (!empty($this->subtitle)) {
            $output .= '<br /><span class="Light">' . $this->subtitle . '</span>';
        }
        if (!empty($this->author)) {
            $output .= '<br /><span class="Light">von </span>' . $this->author .'</p>';
        }
        $output .= '<br /></p>';
        if (!empty($this->image)) {
            $output .= '<div class="Foto-Ebook frame-3">' .
              '<img class="frame-2" src="' . $this->image . '" alt="" /><br /></div>';
        }
        // remove wired footnote markup first
        // e.g. <a href="#_ftn5" name="_ftnref5" title=""><span><span><span><span>
        $content = preg_replace(
            [
                '/<hr.*\/>/i',
                '/\[block:[^\]+]/i',
                '/\[media:[^\]+]/i'
            ],
            [
                '<hr />',
                ''
            ],
            $this->content
        );
        $output .= str_replace(
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

    protected function getTaxonomyData($field_name)
    {
        $output = [];
        if ($this->data->hasField($field_name)) {
            $field_values = $this->data->get($field_name)->referencedEntities();
            foreach ($field_values as $field_value) {
                if (self::TAXONOMY_AUTHOR === $field_name) {
                    //special handling for authors here, as we need the sorting later
                    $parts = explode(' ', $field_value->getName());
                    $last_name = array_pop($parts);
                    $output[$last_name] = $field_value->getName();
                } else {
                    $output[] = $field_value->getName();
                }
            }
        }
        return $output;
    }
}
