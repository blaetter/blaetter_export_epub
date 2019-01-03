<?php

namespace Drupal\blaetter_export_epub\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\NodeType;
use Drupal\Component\Utility\Html;

/**
 * Class ProductSettingsForm.
 *
 * @package Drupal\nodeshop\Form
 */
class EpubSettingsForm extends ConfigFormBase
{

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames()
    {
        return [
            'blaetter_export_epub.settings',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'blaetter_export_epub_settings_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $config = $this->config('blaetter_export_epub.settings');

        $form['pepgen'] = [
            '#type'             => 'details',
            '#title'            => $this->t('Pepgen settings'),
            '#description'      => $this->t('Theese are the settings for the pepgen integration.'),
            '#group'            => 'basic',
            '#open'             => true,
        ];
        $form['pepgen']['salt'] = [
            '#type'             => 'textfield',
            '#title'            => $this->t('pepgen salt'),
            '#description'      => $this->t(
                'Please enter the salt that is used in the pepgen installation to generate the hashed filename.'
            ),
            '#maxlength'        => 128,
            '#size'             => 64,
            '#default_value'    => $config->get('pepgen.salt'),
        ];
        $form['pepgen']['base_url'] = [
            '#type'             => 'textfield',
            '#title'            => $this->t('pepgen base url'),
            '#description'      => $this->t(
                'Please enter the url of your pepgen installation <strong>without</strong> the trailing "/".'
            ),
            '#maxlength'        => 128,
            '#size'             => 64,
            '#default_value'    => $config->get('pepgen.base_url'),
        ];
        $form['pepgen']['download_url'] = [
            '#type'             => 'textfield',
            '#title'            => $this->t('pepgen download url'),
            '#description'      => $this->t(
                'Please enter the download url of your pepgen installation <strong>without</strong> the trailing "/".'
            ),
            '#maxlength'        => 128,
            '#size'             => 64,
            '#default_value'    => $config->get('pepgen.download_url'),
        ];
        $form['mobi'] = [
            '#type'             => 'details',
            '#title'            => $this->t('Mobi settings'),
            '#description'      => $this->t('Theese are the settings for the mobi integration.'),
            '#group'            => 'basic',
            '#open'             => true,
        ];
        $form['mobi']['base_path'] = [
            '#type'             => 'textfield',
            '#title'            => $this->t('archive base path'),
            '#description'      => $this->t(
                'Please enter the base path to your archive.'
            ),
            '#maxlength'        => 128,
            '#size'             => 64,
            '#default_value'    => $config->get('mobi.base_path'),
        ];
        $form['mobi']['from_mail'] = [
            '#type'             => 'textfield',
            '#title'            => $this->t('mobi from mail'),
            '#description'      => $this->t(
                'Please enter the mail address the mobi is sended from.'
            ),
            '#maxlength'        => 128,
            '#size'             => 64,
            '#default_value'    => $config->get('mobi.from_mail'),
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        parent::validateForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        parent::submitForm($form, $form_state);

        $this->config('blaetter_export_epub.settings')
            ->set('pepgen.salt', $form_state->getValue('salt'))
            ->set('pepgen.base_url', $form_state->getValue('base_url'))
            ->set('pepgen.download_url', $form_state->getValue('download_url'))
            ->set('mobi.base_path', $form_state->getValue('base_path'))
            ->set('mobi.from_mail', $form_state->getValue('from_mail'));

        $this->config('blaetter_export_epub.settings')->save();
    }
}
