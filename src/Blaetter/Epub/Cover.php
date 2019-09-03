<?php

namespace Drupal\blaetter_export_epub\Blaetter\Epub;

use Drupal\blaetter_export_epub\Blaetter\Epub\Chapter;

class Cover extends Chapter
{
    /**
     * Export Type, e.g. mobi or epub.
     */
    private $export_type;

    /**
     * Extends the Constructor from the Chapter class to fill the right content
     * for the cover
     */
    public function __construct(object $data, $header, $footer)
    {
        parent::__construct($data, $header, $footer);
    }

    public function getContent()
    {
        $output = $this->header;
        $output .= '<p id="toc_marker-1" class="Ebook-Rubriken para-style-override-1">' .
          'Titelbild ' . $this->title . '</p>';
        if (!empty($this->image)) {
            $output .= '<p class="Ebook-Titel2 para-style-override-1">' .
              '<span><img class="frame-1" src="' . $this->image . '" alt="Titelbild der Ausgabe" /></span></p>';
        }
        if ('epub' == $this->export_type) {
            $output .= '<p class="Ebook-Fliesstext para-style-override-1">' .
              '<span class="Bold char-style-override-12">XXXXX </span><br />' .
              '<span class="char-style-override-3">' .
              'Mit dem Erwerb des E-Books erhalten Sie das einfache, nicht übertragbare Recht zur allein privaten, ' .
              'nichtgewerblichen Nutzung.</span></p>';
        } else {
            $output .= '<p class="Ebook-Fliesstext para-style-override-1">' .
              '<span class="char-style-override-3">Mit dem Erwerb des E-Books erhalten Sie das einfache, nicht ' .
              'übertragbare Recht zur allein privaten, nichtgewerblichen Nutzung.</span></p>';
        }
        $output .= $this->footer;
        return $output;
    }

    public function getEdition()
    {
        $edition = $this->getTaxonomyData(self::TAXONOMY_MONTH);
        return implode('', $edition);
    }

    public function getYear()
    {
        $year = $this->getTaxonomyData(self::TAXONOMY_YEAR);
        return implode('', $year);
    }

    public function setExportType($type)
    {
        $this->export_type = $type;
        return $this;
    }
}
