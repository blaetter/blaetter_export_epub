<?php

namespace Drupal\blaetter_export_epub\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxy;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\node\Entity\Node;
use Drupal\nodeshop\NodeShop;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class DefaultController.
 *
 * @package Drupal\nodeshop\Controller
 */
class DownloadController extends ControllerBase
{
    private $config;
    private $current_user;

    public function __construct()
    {
        $this->current_user = $this->getCurrentUserObject();
        $this->config = $this->config('blaetter_export_epub.settings');
    }

    /**
    * Checks access for the epub and mobi downloads
    *
    * @param \Drupal\Core\Session\AccountInterface $account
    *   Run access checks for this account.
    */
    public function access(AccountInterface $account, $node = null)
    {
        // if there is no node or the node is too old, return 404
        if (null == $node || 25756 > $node) {
            throw new NotFoundHttpException();
        }

        $node = \Drupal::entityTypeManager()->getStorage('node')->load($node);

        // if there was no load after loading the node id, return 404, too.
        if (!$node instanceof Node) {
            throw new NotFoundHttpException();
        }

        // if there is a node, check, if the user has access to it via NodeShop::grantUserAccess()
        return AccessResult::allowedIf(
            NodeShop::grantUserAccess(
                $node,
                $this->current_user
            )
        );
    }

    /**
     * Controller for the download epub action
     *
     * @param Node $node
     * @return void
     */
    public function downloadEpub($node = null)
    {
        $watermark = htmlspecialchars(
            $this->current_user->get('field_first_name')->value .
            ' ' .
            $this->current_user->get('field_last_name')->value .
            ', E-Mail: ' .
            $this->current_user->get('mail')->value
        );

        $token = md5(
            $node->id() .
            $this->config->get('pepgen.salt', '') .
            $watermark .
            strftime("%d.%m.%Y")
        );

        // Get the Drupal http client
        $client = \Drupal::httpClient();
        // try to make the paypal create payment request and catch any exception that is thrown.
        // If an exception is thrown, we display a message to the end user and log the error message in
        // the drupal watchdog.
        try {
            $request = $client->post(
                $this->config->get('pepgen.base_url') . '/',
                [
                    'headers' => [
                        'Content-type' => 'application/x-www-form-urlencoded',
                    ],
                    'form_params' => [
                        'watermark' => $watermark,
                        'id' => $node->id(),
                        'token' => $token,
                    ],
                ]
            );
            $response = json_decode($request->getBody());
        } catch (\Exception $e) {
            \Drupal::logger('blaetter_export_epub')->error(
                '[EPUB Export][Download epub]: Error during pepgen request for node @nid with message %message',
                [
                    '@uuid' => $node->id(),
                    '%message' => $e->getMessage(),
                ]
            );
            $this->messenger()->addError(
                'There was an error generating your epub. Please try again.'
            );
            return new RedirectResponse(
                Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString()
            );
        }

        if (200 == $request->getStatusCode() && 'Success' === $response) {
            $handle = $this->config->get('pepgen.download_url') .
            '/' .
            $token .
            '.' .
            $node->id() .
            '.epub';

            header('Content-Description: File Transfer');
            header('Content-Type: application/epub+zip');
            header('Content-Disposition: attachment; filename=Ebook_Blaetter.'.$node->id().'.epub');
            ob_clean();
            flush();
            readfile($handle);
            exit;
        } else {
            \Drupal::logger('blaetter_export_epub')->error(
                '[EPUB Export][Download epub]: ePub Error reveived: @handle for node @nid with watermark @watermark ' .
                'and response %response',
                [
                    '@handle' => $handle,
                    '@nid' => $node->id(),
                    '@watermark' => $watermark,
                    '%response' => $response,
                ]
            );
            $this->messenger()->addError(
                'Your epub could not be downloaded, please contact us and provide the following error message: %error_message',
                [
                    '%error_message' => $response
                ]
            );
        }

        return new RedirectResponse(
            Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString()
        );
    }

    /**
     * Controller for the download mobi action
     *
     * The mobi file is uploaded into the pdf archive according to its edition.
     * In order to send it to the customer, we need a kindle mail address.
     * If we have all information and the file exists, its sended as an attachment to the given mail address.
     *
     * @param Node $node
     * @return void
     */
    public function downloadMobi($node = null)
    {
        // first we check if the user already has his kindle settings provided.
        // if not, we redirect him to the profile page with a hint to fill out the required fields.
        if (empty($this->current_user->get('field_kindle_email')->value)) {
            $this->messenger()->addMessage(
                'In order to get your mobi sended to your Kindle reader, you need to provide an Kindle mail address. ' .
                'Please fill out the fields for Kindle mail and domain settings at this page. ' .
                'After submitting the form you will be redirected to the mobi download page again.'
            );
            return new RedirectResponse(
                Url::fromRoute(
                    'entity.user.edit_form',
                    [
                        'user' => $this->current_user->id()
                    ],
                    [
                        'query' => [
                            'destination' => Url::fromRoute(
                                'blaetter_export_epub.mobi.download',
                                [
                                    'node' => $node->id()
                                ]
                            )->toString()
                        ]
                    ]
                )->toString()
            );
        } else {
            // generate the mailto address from the users kindle settings.
            $mailto = $this->current_user->get('field_kindle_email')->value .
            $this->current_user->get('field_kindle_domain')->value;

            // get the relevant path components. They are calculated from the information
            // of the given edition and the given year. These information are needed to calclulate
            // the file uri and the file name.
            // In order to do so, we need to convert the given edition - wich is the string of a month - to
            // the nummber of the month and extract the last two digits of the year (so 2019 would becomde 19) and
            // december would become 12.
            if ($node->hasField('field_jahr') && $node->hasField('field_ausgabe')) {
                $year = $node->get('field_jahr')->entity->getName();
                $edition = $node->get('field_ausgabe')->entity->getName();
                $edition_path = strftime("%g", strtotime($year)) . strftime("%m", strtotime($edition));

                $mobi_filename = 'ausgabe' . $edition_path . '.mobi';
                $mobi_file = $this->config->get('mobi.base_path') .
                '/' .
                $year .
                '/' .
                $edition_path .
                '/' .
                $mobi_filename;

                // if the file exists, proceed
                if (file_exists($mobi_file)) {
                    // The attachments needs to be provided as stdClass, so we build up the
                    // object. As we know, that we only send mobi files through this method,
                    // we can hard code the mime type
                    $attachments = new \stdClass();
                    $attachments->uri = $mobi_file;
                    $attachments->filename = $mobi_filename;
                    $attachments->filemime = 'application/x-mobipocket-ebook';

                    // now we prepare the mail and invoke the drupal mail service here
                    $mailManager = \Drupal::service('plugin.manager.mail');
                    $from = $this->config->get('mobi.from_mail');
                    $language_code = \Drupal::languageManager()->getDefaultLanguage()->getId();
                    $params = [
                        'subject' => $this->t(
                            'Your mobi download for Blätter edition @edition @year',
                            [
                                '@edition' => ucfirst($edition),
                                '@year' => $year
                            ]
                        ),
                        'body' => $this->t('You can find the mobi as an attachment of this message.'),
                        'files' => [
                            $attachments
                        ]
                    ];

                    // everything is ready to be sended, so hand out the message to the mail service
                    $message = $mailManager->mail(
                        'blaetter_export_epub',
                        'mobi_mail',
                        $mailto,
                        $language_code,
                        $params,
                        $from,
                        true
                    );

                    // if the mail was successfully sended, tell the user,
                    // if not, too. In this case, also log a message for the admin.
                    if (true !== $message['result']) {
                        \Drupal::logger('blaetter_export_epub')->error(
                            '[Mobi Download]: Could not send message.'
                        );
                        $this->messenger()->addError(
                            'Mobi could not be send to your Kindle mail address.'
                        );
                    } else {
                        $this->messenger()->addMessage(
                            'Mobi was successfully send to your Kindle mail address.'
                        );
                    }
                } else {
                    \Drupal::logger('blaetter_export_epub')->error(
                        '[EPUB Export][Download mobi]: mobi not found for path %path ',
                        [
                            '%path' => $mobi_filename,
                        ]
                    );
                    $this->messenger()->addMessage(
                        'The system could not find the mobi file for the requested edition. Please try again later.'
                    );
                }
            }
        }
        // Lead the user back to the page from where he came.
        return new RedirectResponse(
            Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString()
        );
    }

    /**
     * Returns the user object of the current acting user
     *
     * @return User The user object
     */
    public function getCurrentUserObject()
    {
        return User::load(
            $this->currentUser()->id()
        );
    }
}