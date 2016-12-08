<?php
/**
 * @file
 * Contains \Drupal\tmgmt_smartling\SmartlingTranslatorUi.
 */

namespace Drupal\tmgmt_smartling;

use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\TranslatorPluginUiBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Smartling translator UI.
 */
class SmartlingTranslatorUi extends TranslatorPluginUiBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();

    $form['api_url'] = [
      '#type' => 'textfield',
      '#title' => t('API URL'),
      '#default_value' => $translator->getSetting('api_url'),
      '#size' => 25,
      '#maxlength' => 255,
      '#required' => TRUE,
      '#description' => t('Set api url. Default: @api_url', ['@api_url' => $translator->getSetting('api_url')]),
    ];

    $form['project_id'] = [
      '#type' => 'textfield',
      '#title' => t('Project Id'),
      '#default_value' => $translator->getSetting('project_id'),
      '#size' => 25,
      '#maxlength' => 25,
      '#required' => TRUE,
    ];

    $form['key'] = [
      '#type' => 'textfield',
      '#title' => t('Key'),
      '#default_value' => $translator->getSetting('key'),
      '#size' => 40,
      '#maxlength' => 40,
      '#required' => TRUE,
    ];

    $form['orgID'] = [
      '#type' => 'textfield',
      '#title' => t('orgID'),
      '#size' => 40,
      '#maxlength' => 40,
      '#default_value' => $translator->getSetting('orgID'),
      '#required' => FALSE,
    ];

    $form['contextUsername'] = [
      '#type' => 'textfield',
      '#title' => t('Username for context retrieval'),
      '#size' => 40,
      '#maxlength' => 40,
      '#default_value' => $translator->getSetting('contextUsername'),
      '#required' => FALSE,
    ];

    $form['retrieval_type'] = [
      '#type' => 'select',
      '#title' => t('The desired format for download'),
      '#default_value' => $translator->getSetting('retrieval_type'),
      '#options' => [
        'pending' => t('Smartling returns any translations (including non-published translations)'),
        'published' => t('Smartling returns only published/pre-published translations'),
        'pseudo' => t('Smartling returns a modified version of the original text'),
      ],
      '#required' => FALSE,
    ];

    $form['auto_authorize_locales'] = [
      '#type' => 'checkbox',
      '#title' => t('Automatically authorize content for translation in Smartling'),
      // @todo Add description to display full URL.
      '#default_value' => $translator->getSetting('auto_authorize_locales'),
      '#required' => FALSE,
    ];

    $form['callback_url_use'] = [
      '#type' => 'checkbox',
      '#title' => t('Use Smartling callback: /smartling/callback/%cron_key'),
      // @todo Add description to display full URL.
      '#default_value' => $translator->getSetting('callback_url_use'),
      '#required' => FALSE,
    ];


    // Any visible, writeable wrapper can potentially be used for the files
    // directory, including a remote file system that integrates with a CDN.
    foreach (\Drupal::service('stream_wrapper_manager')->getDescriptions(StreamWrapperInterface::WRITE_VISIBLE) as $scheme => $description) {
      $options[$scheme] = $description;
    }

    if (!empty($options)) {
      $form['scheme'] = [
        '#type' => 'radios',
        '#title' => t('Download method'),
        '#default_value' => $translator->getSetting('scheme'),
        '#options' => $options,
        '#description' => t('Choose the location where exported files should be stored. The usage of a protected location (e.g. private://) is recommended to prevent unauthorized access.'),
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();
    $supported_remote_languages = $translator->getPlugin()->getSupportedRemoteLanguages($translator);
    if (empty($supported_remote_languages)) {
      $form_state->setErrorByName('settings][project_id', t('The "Project ID", the "Client key" or both are not correct.'));
      $form_state->setErrorByName('settings][key', t('The "Project ID", the "Client key" or both are not correct.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutInfo(JobInterface $job) {
    // If the job is finished, it's not possible to import translations anymore.
    if ($job->isFinished()) {
      return parent::checkoutInfo($job);
    }
    $output = [];

    try {
      $output = array(
        '#type' => 'fieldset',
        '#title' => t('Import translated file'),
      );

      $output['submit'] = array(
        '#type' => 'submit',
        '#value' => t('Download'),
        '#submit' => ['tmgmt_smartling_download_file_submit'],
      );

      $output = $this->checkoutInfoWrapper($job, $output);
    }
    catch (\Exception $e) {

    }

    return $output;
  }

}
