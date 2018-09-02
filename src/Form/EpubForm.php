<?php

namespace Drupal\blaetter_export_epub\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\blaetter_export_epub\Blaetter\Epub\Chapter;
use Drupal\blaetter_export_epub\Blaetter\Epub\Cover;
use Drupal\blaetter_export_epub\Blaetter\Epub\Generator;
use Drupal\node\Entity\Node;

/**
 * Implements the EpubForm form controller.
 *
 * This form shows every issue on the Blätter website and lets the user
 * choose, which one should be converted into an epub.
 *
 * @see \Drupal\Core\Form\FormBase
 */
class EpubForm extends FormBase
{
    /**
     * Build the form.
     *
     * A build form method constructs an array that defines how markup and
     * other form elements are included in an HTML form.
     *
     * @param array $form
     *   Default form array structure.
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     *   Object containing current form state.
     *
     * @return array
     *   The render array defining the elements of the form.
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {

        $nids = \Drupal::entityQuery('node')
            ->condition('status', 1)
            ->condition('type', 'ausgabe')
            ->sort('nid', 'DESC')
            ->execute();
        $nodes =  \Drupal\node\Entity\Node::loadMultiple($nids);

        $options = array();
        foreach ($nodes as $node) {
            $options[$node->id()] = $node->getTitle();
        }

        $form['basics'] = [
            '#title'            => $this->t('choose edition'),
            '#type'             => 'details',
            '#description'      => 'Hier können Ausgaben ausgewählt und als ePub ' .
                                   'heruntegeladen werden. Es gibt zwei ' .
                                   'verschiedene Export-Aktionen: "Export ePub" ' .
                                   'und "Export mobi". In beiden Fällen wird eine ' .
                                   'ePub-Datei erzeugt, im Falle "Export ePub" ' .
                                   'mit, im Fall "Export mobi" ohne das ' .
                                   'Wasserzeichen für die mobi-Variante. ',
            '#open'             => true,
        ];

        $form['basics']['edition'] = [
            '#title'            => $this->t('edition'),
            '#type'             => 'select',
            '#options'          => $options,
        ];

        $form['basics']['export'] = [
            '#title'            => 'Export-Aktion',
            '#type'             => 'select',
            '#required'         => true,
            '#default_value'    => 'epub',
            '#options'          => [
                'epub' => 'Export ePub (mit Wasserzeichen)',
                'mobi' => 'Export mobi (ohne Wasserzeichen)'
            ],
        ];


        // Group submit handlers in an actions element with a key of "actions" so
        // that it gets styled correctly, and so that other modules may add actions
        // to the form. This is not required, but is convention.
        $form['actions'] = [
          '#type' => 'actions',
        ];

        // Add a submit button that handles the submission of the form.
        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Submit'),
        ];

        return $form;
    }

    /**
     * Getter method for Form ID.
     *
     * The form ID is used in implementations of hook_form_alter() to allow other
     * modules to alter the render array built by this form controller. It must be
     * unique site wide. It normally starts with the providing module's name.
     *
     * @return string
     *   The unique ID of the form defined by this class.
     */
    public function getFormId()
    {
        return 'blaetter_export_epub_epub_form';
    }

    /**
     * Implements form validation.
     *
     * The validateForm method is the default method called to validate input on
     * a form.
     *
     * @param array $form
     *   The render array of the currently built form.
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     *   Object describing the current state of the form.
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        $edition = \Drupal\node\Entity\Node::load($form_state->getValue('edition'));
        drupal_set_message($edition->getType());
        if ($edition && 'ausgabe' != $edition->getType()) {
            $form_state->setErrorByName(
                'edition',
                $this->t('Something is wrong with the selected node: Its no edition.')
            );
        }
        if (null === ($form_state->getValue('export'))
            || !in_array(
                $form_state->getValue('export'),
                ['epub', 'mobi']
            )
        ) {
            $form_state->setErrorByName(
                'export',
                $this->t('Something is wrong with the export action.')
            );
        }
    }

    /**
     * Implements a form submit handler.
     *
     * The submitForm method is the default method called for any submit elements.
     *
     * @param array $form
     *   The render array of the currently built form.
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     *   Object describing the current state of the form.
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        // Get the Edition from the form state value.
        $edition = \Drupal\node\Entity\Node::load($form_state->getValue('edition'));

        // Get the export type, e.g. epub or mobi
        $export_type = $form_state->getValue('export');

        // // prepate an array for all the authors of this edition
        // // we need that later for the author overview page.
        $authors = [];

        // Create an instance of the PHPEpub class. Its wrapped with a custom class to
        // outsource some logic.
        $epub = new Generator(
            $edition->getTitle(),
            $edition->id(),
            "Blätter für deutsche und internationale Politik",
            "https://www.blaetter.de/node/" . $edition->id(),
            "de"
        );

        // get some template data
        //  - $header_raw: The Markup for the header
        //  - $footer_raw: The Markup for the footer
        //  - $imprint_raw: The Markup for the imprint (it changes not that often so its stored statically)
        //  - $authors_raw: The Markup for the author overview page
        //  - $css_raw: The css that needs to be injected into the book
        $header_raw = file_get_contents(
            drupal_get_path('module', 'blaetter_export_epub') .
            '/templates/head.tmpl'
        );
        $footer_raw = file_get_contents(
            drupal_get_path('module', 'blaetter_export_epub') .
            '/templates/foot.tmpl'
        );
        $imprint_raw = file_get_contents(
            drupal_get_path('module', 'blaetter_export_epub') .
            '/templates/imprint.tmpl'
        );
        $authors_raw = file_get_contents(
            drupal_get_path('module', 'blaetter_export_epub') .
            '/templates/authors.tmpl'
        );
        $css_raw = file_get_contents(
            drupal_get_path('module', 'blaetter_export_epub') .
            '/templates/css/styles.css'
        );

        // Create an instance of Cover to hold the cover data of the epub
        // and add the cover to the epub
        $cover = new Cover(
            $edition,
            str_replace('___TITLE___', $edition->getTitle(), $header_raw),
            $footer_raw
        );
        $cover->setExportType($export_type);
        $epub->addCover($cover);

        // Create a table of contents for the epub
        $epub->addToc();

        // Add the styles
        $epub->addStyles($css_raw);

        // Get all book pages in the correct order (e.g. weight)
        // Books and its contents are managed via Drupal
        $book_manager = $date = \Drupal::service('book.manager');

        $outline = $book_manager->getTableOfContents($edition->id(), 2);
        $nodes = \Drupal\node\Entity\Node::loadMultiple(array_keys($outline));

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
                    $node->getTitle(),
                    $header_raw
                ),
                $footer_raw
            );
            // Check for the rubric of the current node and compare that with the active rubric
            if ($chapter->getRubric() !== $epub->getRubric()) {
                //error_log($node->nid . ': Active: ' . $epub->rubric . '; Current: ' . $chapter->getRubric());
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
            $authors = array_merge($authors, $chapter->getAuthors());
            // Add the chapter
            $epub->addChapter($chapter);
        }

        // sort the authors by key
        // @see Chapter::getTaxonomyData() for details
        ksort($authors, SORT_LOCALE_STRING);
        // create the markup for the author page
        $authors_string = '<p class="Ebook-Fliesstext-ohneEinzug">' .
                          '<span class="char-style-override-15">' .
                          implode('</span>, Kurzbiographie</p><p class="Ebook-Fliesstext-ohneEinzug">&#160;</p><p class="Ebook-Fliesstext-ohneEinzug"><span class="char-style-override-15">', $authors) .
                          '</span>, Kurzbiographie</p>' .
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
            str_replace('___TITLE___', $authors_node->getTitle(), $header_raw),
            $footer_raw
        );
        $epub->addChapter($chapter);

        // create a fake node object to use it within the Chapter class
        // for the imprint
        $data = [];
        $data['nid'] = ['x-default' => '999999'];
        $data['title'] = ['x-default' => 'Impressum'];
        if ('epub' == $export_type) {
            $data['body'] = [
                'x-default' => [
                    0 => [
                        'value' => str_replace(
                            '___WATERMARK___',
                            '<span class="Bold char-style-override-12">XXXXX </span>',
                            $imprint_raw
                        )
                    ]
                ]
            ];
        } else {
            $data['body'] = [
                'x-default' => [
                    0 => [
                        'value' => str_replace(
                            '___WATERMARK___',
                            '',
                            $imprint_raw
                        )
                    ]
                ]
              ];
        }
        $data['field_subtitle'] = [];
        $imprint_node = new Node($data, 'node', 'story');
        $imprint_node->rubric = 'Extras';
        $chapter = new Chapter(
            $imprint_node,
            str_replace('___TITLE___', $imprint_node->getTitle(), $header_raw),
            $footer_raw
        );
        $epub->addChapter($chapter);

        // add some fonts to the archive as they're not added automatically for
        // whatever reason.
        $font_path = drupal_get_path('module', 'blaetter_export_epub') .
            '/templates/font/';

        $epub->addLargeFile(
            'font/CandidaStd-Bold.otf',
            'CandidaStd-Bold',
            $font_path . 'CandidaStd-Bold.otf',
            'font/opentype'
        );
        $epub->addLargeFile(
            'font/CandidaStd-Italic.otf',
            'CandidaStd-Italic',
            $font_path . 'CandidaStd-Italic.otf',
            'font/opentype'
        );
        $epub->addLargeFile(
            'font/CandidaStd-Roman.otf',
            'CandidaStd-Roman',
            $font_path . 'CandidaStd-Roman.otf',
            'font/opentype'
        );
        $epub->addLargeFile(
            'font/FrutigerLTStd-Bold.otf',
            'FrutigerLTStd-Bold',
            $font_path . 'FrutigerLTStd-Bold.otf',
            'font/opentype'
        );
        $epub->addLargeFile(
            'font/FrutigerLTStd-Light.otf',
            'FrutigerLTStd-Light',
            $font_path . 'FrutigerLTStd-Light.otf',
            'font/opentype'
        );
        $epub->addLargeFile(
            'font/FrutigerLTStd-LightCn.otf',
            'FrutigerLTStd-LightCn',
            $font_path . 'FrutigerLTStd-LightCn.otf',
            'font/opentype'
        );

        // // create the epub and deliver it to the admin user.
        $epub->createEpub();
    }
}
