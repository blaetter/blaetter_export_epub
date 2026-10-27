<?php

namespace Drupal\blaetter_export_epub\Form;

use Drupal\Component\Utility\Environment;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Tableselect;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\blaetter_export_epub\Blaetter\Epub\IssueExporter;
use Drupal\blaetter_export_epub\PepgenPublisher;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Implements the EpubForm form controller.
 *
 * This form lists the issues of the Blätter website (newest first, 20 per page) and lets the user choose, which ones
 * are exported as ePub: handed over to pepgen when its original directory is set, or offered as download.
 *
 * @see \Drupal\Core\Form\FormBase
 */
class EpubForm extends FormBase implements TrustedCallbackInterface
{
    /**
     * The number of issues per page.
     */
    const ISSUES_PER_PAGE = 20;

    /**
     * The number of issues that can be downloaded at once; they are built within one request.
     */
    const MAX_DOWNLOAD = 10;

    /**
     * The entity type manager.
     *
     * @var EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * The date formatter.
     *
     * @var DateFormatterInterface
     */
    protected $dateFormatter;

    /**
     * The extension path resolver, for the templates of the module.
     *
     * @var ExtensionPathResolver
     */
    protected $pathResolver;

    public function __construct(
        EntityTypeManagerInterface $entity_type_manager,
        DateFormatterInterface $date_formatter,
        ExtensionPathResolver $path_resolver
    ) {
        $this->entityTypeManager = $entity_type_manager;
        $this->dateFormatter = $date_formatter;
        $this->pathResolver = $path_resolver;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('entity_type.manager'),
            $container->get('date.formatter'),
            $container->get('extension.path.resolver')
        );
    }

    /**
     * {@inheritdoc}
     */
    public static function trustedCallbacks()
    {
        return ['preRenderBulkFormCells'];
    }

    /**
     * Adds the class of a bulk form to the checkbox cell of each row, Claro's tableselect script looks for it.
     *
     * @param array $element
     *   The tableselect element, after its default pre render callback.
     *
     * @return array
     */
    public static function preRenderBulkFormCells($element)
    {
        foreach ($element['#rows'] ?? [] as $index => $row) {
            if (isset($row['data'][0]) && !is_array($row['data'][0])) {
                $element['#rows'][$index]['data'][0] = [
                    'data'  => $row['data'][0],
                    'class' => ['blaetter-export-epub-bulk-form'],
                ];
            }
        }
        return $element;
    }

    /**
     * Getter method for Form ID.
     *
     * @return string
     *   The unique ID of the form defined by this class.
     */
    public function getFormId()
    {
        return 'blaetter_export_epub_epub_form';
    }

    /**
     * Build the form.
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
        $directory = $this->getPepgenDirectory();
        $storage = $this->entityTypeManager->getStorage('node');

        $nids = $storage->getQuery()
            ->accessCheck(true)
            ->condition('status', 1)
            ->condition('type', 'ausgabe')
            ->sort('nid', 'DESC')
            ->pager(self::ISSUES_PER_PAGE)
            ->execute();

        $header = ['title' => $this->t('Issue', [], ['context' => 'Blaetter issue'])];
        if ('' !== $directory) {
            $header['pepgen'] = $this->t('At pepgen since');
        }
        $header['operations'] = $this->t('Operations');

        $options = [];
        foreach ($storage->loadMultiple($nids) as $node) {
            $row = ['title' => ['data' => $node->toLink()->toRenderable()]];
            if ('' !== $directory) {
                $mimetype = $directory . '/' . $node->id() . '.epub/mimetype';
                $row['pepgen'] = is_file($mimetype) ? $this->dateFormatter->format(filemtime($mimetype), 'short') : '–';
            }
            $row['operations'] = ['data' => ['#type' => 'operations', '#links' => $this->getOperations($node)]];
            $options[$node->id()] = $row;
        }

        $settings = Url::fromRoute('blaetter_export_epub.settings');
        $form['help'] = [
            '#type'             => 'container',
            'bundle'            => [
                '#type'             => 'html_tag',
                '#tag'              => 'p',
                'text'              => [
                    '#markup'           => $this->t(
                        'Here the issues can be downloaded bundled as ePub or copied directly into the download directory for the users. To do so, simply select one or more issues and choose the action "@action" at the end of the table.',
                        ['@action' => $this->t('Hand over to pepgen')]
                    ),
                ],
                'settings'          => [
                    '#prefix'           => ' ',
                    '#markup'           => $settings->access()
                        ? $this->t(
                            'If the action is missing, the destination directory for the export still has to be set <a href=":settings">in the settings</a>.',
                            [':settings' => $settings->toString()]
                        )
                        : $this->t('If the action is missing, the destination directory for the export still has to be set in the settings.'),
                ],
            ],
            'download'          => [
                '#type'             => 'html_tag',
                '#tag'              => 'p',
                '#value'            => $this->t('Alternatively, several issues can also be downloaded as ePub.'),
            ],
        ];

        $form['issues'] = [
            '#type'             => 'tableselect',
            '#header'           => $header,
            '#options'          => $options,
            '#empty'            => $this->t('No issues found.'),
            // the default pre render callback of tableselect, then the class of the checkbox cells
            '#pre_render'       => [
                [Tableselect::class, 'preRenderTableselect'],
                [static::class, 'preRenderBulkFormCells'],
            ],
        ];

        // The bulk actions below the table, built like Claro builds them for the bulk form of Views (see
        // ClaroFormHooks::formAlter()): Claro's tableselect script counts the selected rows and keeps the actions
        // sticky at the bottom while rows are selected.
        $form['bulk_actions'] = [
            '#type'             => 'container',
            '#attributes'       => [
                'class'                         => ['views-bulk-actions'],
                'data-drupal-views-bulk-actions' => '',
                'role'                          => 'group',
                'aria-labelledby'               => 'blaetter-export-epub-bulk-label',
            ],
        ];
        $form['bulk_actions']['group_label'] = [
            '#type'             => 'container',
            '#markup'           => $this->t('Perform actions on the selected issues'),
            '#attributes'       => ['id' => 'blaetter-export-epub-bulk-label', 'class' => ['visually-hidden']],
        ];
        $form['bulk_actions']['status'] = [
            '#type'             => 'container',
            '#markup'           => $this->t('No items selected'),
            '#attributes'       => [
                'class'                         => [
                    'js-views-bulk-actions-status',
                    'views-bulk-actions__item',
                    'views-bulk-actions__item--status',
                    'js-show',
                ],
                'data-drupal-views-bulk-actions-status' => '',
            ],
        ];
        $form['bulk_actions']['action'] = [
            '#title'            => $this->t('Action'),
            '#type'             => 'select',
            '#options'          => $this->getActions(),
            '#default_value'    => '' !== $directory ? 'pepgen' : 'download',
            '#wrapper_attributes' => [
                'class'             => ['views-bulk-actions__item', 'views-bulk-actions__item--preceding-actions'],
            ],
        ];
        $form['bulk_actions']['actions'] = [
            '#type'             => 'actions',
            '#attributes'       => ['class' => ['views-bulk-actions__item']],
        ];
        $form['bulk_actions']['actions']['submit'] = [
            '#type'             => 'submit',
            '#value'            => $this->t('Apply to selected items'),
            '#button_type'      => 'primary',
            '#attributes'       => ['class' => ['button--small']],
        ];

        $form['pager'] = ['#type' => 'pager'];

        return $form;
    }

    /**
     * Implements form validation.
     *
     * @param array $form
     *   The render array of the currently built form.
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     *   Object describing the current state of the form.
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        $nids = $this->getSelectedIssues($form_state);
        if (!$nids) {
            $form_state->setErrorByName('issues', $this->t('Please select at least one issue.'));
        }
        $storage = $this->entityTypeManager->getStorage('node');
        foreach ($nids as $nid) {
            $edition = $storage->load($nid);
            if (!$edition instanceof NodeInterface) {
                $form_state->setErrorByName(
                    'issues',
                    $this->t('The selected edition could not be found.')
                );
            } elseif ('ausgabe' != $edition->getType()) {
                $form_state->setErrorByName(
                    'issues',
                    $this->t('Something is wrong with the selected node: Its no edition.')
                );
            }
        }

        $action = $form_state->getValue('action');
        if (!isset($this->getActions()[$action])) {
            $form_state->setErrorByName('action', $this->t('The action is not valid.'));
        } elseif ('download' === $action && count($nids) > self::MAX_DOWNLOAD) {
            $form_state->setErrorByName('issues', $this->t(
                'Please download at most @max issues at once, or hand them over to pepgen.',
                ['@max' => self::MAX_DOWNLOAD]
            ));
        } elseif ('pepgen' === $action) {
            $problem = PepgenPublisher::checkDirectory($this->getPepgenDirectory());
            if (null !== $problem) {
                $form_state->setErrorByName('action', $this->t('pepgen: @problem', ['@problem' => $problem]));
            }
        }
    }

    /**
     * Implements a form submit handler.
     *
     * The hand over to pepgen runs as batch, one issue per request. The download builds the ePubs at once and sends
     * one ePub, or several as zip archive.
     *
     * @param array $form
     *   The render array of the currently built form.
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     *   Object describing the current state of the form.
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $nids = $this->getSelectedIssues($form_state);

        if ('pepgen' === $form_state->getValue('action')) {
            $batch = (new BatchBuilder())
                ->setTitle($this->t('Handing the ePubs over to pepgen'))
                ->setFinishCallback([static::class, 'handOverFinished']);
            foreach ($nids as $nid) {
                $batch->addOperation([static::class, 'handOverBatch'], [$nid, $this->getPepgenDirectory()]);
            }
            batch_set($batch->toArray());
            return;
        }

        // the ePubs are built within this request, one after the other
        Environment::setTimeLimit(300);
        $exporter = new IssueExporter($this->pathResolver->getPath('module', 'blaetter_export_epub'));
        $editions = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
        try {
            $response = IssueExporter::downloadResponse($exporter->buildFiles($editions));
        } catch (\Throwable $e) {
            $this->logger('blaetter_export_epub')->error('Download of ePubs failed: @message', ['@message' => $e->getMessage()]);
            $this->messenger()->addError($this->t('The ePubs could not be created: @message', ['@message' => $e->getMessage()]));
            return;
        }
        if ($exporter->getMissingFonts()) {
            $titles = array_map(fn ($edition) => $edition->getTitle(), array_values($editions));
            $this->messenger()->addWarning(self::missingFontsMessage($exporter->getMissingFonts(), $titles));
        }
        $form_state->setResponse($response);
    }

    /**
     * Batch operation: exports one issue and hands it over to pepgen.
     *
     * @param int $nid
     *   The node id of the issue.
     * @param string $directory
     *   The original directory of pepgen.
     * @param array|\ArrayAccess $context
     *   The batch context.
     */
    public static function handOverBatch($nid, $directory, &$context)
    {
        $edition = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
        if (!$edition instanceof NodeInterface || 'ausgabe' !== $edition->getType()) {
            $context['results']['failed'][] = new TranslatableMarkup('@nid: no issue', ['@nid' => $nid]);
            return;
        }
        $exporter = new IssueExporter(
            \Drupal::service('extension.path.resolver')->getPath('module', 'blaetter_export_epub')
        );
        try {
            (new PepgenPublisher($directory, \Drupal::lock()))->publish($exporter->build($edition)->getBookData(), $nid);
            $context['results']['done'][] = $edition->getTitle();
            \Drupal::logger('blaetter_export_epub')->notice(
                'ePub of node @nid handed over to pepgen.',
                ['@nid' => $nid]
            );
        } catch (\Throwable $e) {
            \Drupal::logger('blaetter_export_epub')->error(
                'Hand over of the ePub of node @nid to pepgen failed: @message',
                ['@nid' => $nid, '@message' => $e->getMessage()]
            );
            $context['results']['failed'][] = new TranslatableMarkup(
                '@title: @message',
                ['@title' => $edition->getTitle(), '@message' => $e->getMessage()]
            );
        }
        if ($exporter->getMissingFonts()) {
            foreach ($exporter->getMissingFonts() as $font) {
                $context['results']['missing_fonts'][$font] = $font;
            }
            $context['results']['without_fonts'][] = $edition->getTitle();
        }
        $context['message'] = $edition->getTitle();
    }

    /**
     * Batch finished callback: reports the result of the hand over.
     *
     * @param bool $success
     *   Whether the batch ran through.
     * @param array $results
     *   The results of the operations.
     */
    public static function handOverFinished($success, array $results)
    {
        $messenger = \Drupal::messenger();
        if (!empty($results['done'])) {
            $messenger->addStatus(new PluralTranslatableMarkup(
                count($results['done']),
                'Handed the ePub over to pepgen: @titles',
                'Handed @count ePubs over to pepgen: @titles',
                ['@titles' => implode(', ', $results['done'])]
            ));
        }
        foreach ($results['failed'] ?? [] as $failure) {
            $messenger->addError(new TranslatableMarkup('Handing over to pepgen failed: @failure', ['@failure' => $failure]));
        }
        if (!empty($results['missing_fonts'])) {
            $messenger->addWarning(self::missingFontsMessage($results['missing_fonts'], $results['without_fonts']));
        }
        if (!$success) {
            $messenger->addError(new TranslatableMarkup('The hand over was aborted.'));
        }
    }

    /**
     * Returns one warning about the fonts that are missing in the module, with the issues exported without them.
     *
     * @param string[] $fonts
     *   File names of the missing fonts.
     * @param string[] $issues
     *   Titles of the issues exported without these fonts.
     *
     * @return \Drupal\Core\StringTranslation\TranslatableMarkup
     */
    public static function missingFontsMessage(array $fonts, array $issues)
    {
        return new TranslatableMarkup(
            'Fonts are missing in templates/font/ of the module, so they are not part of the ePubs of @issues: @fonts',
            ['@issues' => implode(', ', $issues), '@fonts' => implode(', ', $fonts)]
        );
    }

    /**
     * Returns the actions for the selected issues: the hand over to pepgen first, if its directory is set.
     *
     * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
     */
    protected function getActions()
    {
        $actions = [];
        if ('' !== $this->getPepgenDirectory()) {
            $actions['pepgen'] = $this->t('Hand over to pepgen');
        }
        $actions['download'] = $this->t('Download as ePub (several as zip archive)');
        return $actions;
    }

    /**
     * Returns the links of the operations column for an issue.
     *
     * @param \Drupal\node\NodeInterface $node
     *   The issue.
     *
     * @return array
     */
    protected function getOperations(NodeInterface $node)
    {
        $operations = [
            'download' => [
                'title'     => $this->t('Download ePub'),
                'url'       => Url::fromRoute('blaetter_export_epub.download_issue', ['node' => $node->id()]),
                'weight'    => 0,
            ],
        ];
        if ($node->access('update')) {
            $operations['edit'] = [
                'title'     => $this->t('Edit'),
                'url'       => $node->toUrl('edit-form'),
                'weight'    => 10,
            ];
        }
        return $operations;
    }

    /**
     * Returns the node ids of the selected issues.
     *
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     *   Object describing the current state of the form.
     *
     * @return int[]
     */
    protected function getSelectedIssues(FormStateInterface $form_state)
    {
        return array_values(array_map('intval', array_filter((array) $form_state->getValue('issues'))));
    }

    /**
     * Returns the original directory of pepgen from the settings, or an empty string if it is not set.
     */
    protected function getPepgenDirectory()
    {
        return (string) $this->config('blaetter_export_epub.settings')->get('pepgen.original_dir');
    }
}
