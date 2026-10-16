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
        // The salt is a secret shared with pepgen, so it is never shown in the form.
        if ($this->isSaltOverridden()) {
            $form['pepgen']['salt'] = [
                '#type'             => 'item',
                '#title'            => $this->t('pepgen salt'),
                '#markup'           => $this->t('The salt is set in settings.php and can not be changed here.'),
            ];
        } else {
            $form['pepgen']['salt'] = [
                '#type'             => 'password',
                '#title'            => $this->t('pepgen salt'),
                '#description'      => $this->t(
                    'Please enter the salt that is used in the pepgen installation to generate the hashed filename.'
                ) . ' ' . ('' !== (string) $config->get('pepgen.salt')
                    ? $this->t('A salt is stored. Leave the field empty to keep it.')
                    : $this->t('There is no salt stored yet.')),
                '#maxlength'        => 128,
                '#size'             => 64,
                // Prevent the browser from filling in a saved password.
                '#attributes'       => ['autocomplete' => 'new-password'],
            ];
        }
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
            ->set('pepgen.salt', $this->getSaltToStore($form_state->getValue('salt')))
            ->set('pepgen.base_url', $form_state->getValue('base_url'))
            ->set('pepgen.download_url', $form_state->getValue('download_url'));

        $this->config('blaetter_export_epub.settings')->save();
    }

    /**
     * Checks, if the salt is set via settings.php.
     *
     * The salt should not be stored in the configuration, as the configuration is exported into the config sync
     * directory and committed. Instead it can be set per environment in settings.php, e.g.:
     * $config['blaetter_export_epub.settings']['pepgen']['salt'] = getenv('PEPGEN_SALT');
     *
     * @return bool
     */
    protected function isSaltOverridden()
    {
        return (bool) $this->configFactory()->get('blaetter_export_epub.settings')->hasOverrides('pepgen.salt');
    }

    /**
     * Returns the salt to store in the configuration.
     *
     * An empty field keeps the stored salt. If the salt is set via settings.php, the stored one is not used anymore,
     * so it is removed from the configuration (and with the next config export from the config sync directory).
     *
     * @param string|null $form_value The salt entered in the form.
     * @return string
     */
    protected function getSaltToStore($form_value)
    {
        if ($this->isSaltOverridden()) {
            return '';
        }
        $form_value = trim((string) $form_value);
        return '' !== $form_value
            ? $form_value
            : (string) $this->config('blaetter_export_epub.settings')->get('pepgen.salt');
    }
}
