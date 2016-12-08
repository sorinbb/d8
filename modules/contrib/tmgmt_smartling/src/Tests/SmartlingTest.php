<?php
/**
 * @file
 * Contains \Drupal\tmgmt_smartling\Tests\SmartlingTest.
 */

namespace Drupal\tmgmt_smartling\Tests;
use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\Tests\TMGMTTestBase;
use Drupal\Core\Url;

/**
 * Basic tests for the Smartling translator.
 *
 * @group tmgmt_smartling
 */
class SmartlingTest extends TMGMTTestBase {

  /**
   * A tmgmt_translator with a server mock.
   *
   * @var Translator
   */
  protected $translator;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('tmgmt_smartling', 'tmgmt_smartling_test');

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $this->addLanguage('nl');
    $this->addLanguage('es');
    $this->translator = $this->createTranslator([
      'plugin' => 'smartling',
      'settings' => [
        'api_url' => URL::fromUri('base://tmgmt-smartling/v1', array('absolute' => TRUE))->toString(),
        'project_id' => $this->randomString(),
        'key' => $this->randomString(),
        'callback_url_use' => TRUE,
      ],
    ]);
  }

  /**
   * Tests basic API methods of the plugin.
   */
  protected function testSmartling() {
    $job = $this->createJob('en', 'nl');
    $job->translator = $this->translator->id();
    $item = $job->addItem('test_source', 'test', '1');
    $item->save();
    $job->save();

    $this->assertFalse($job->isTranslatable(), 'Check if the translator is not available at this point because we did not define the API parameters.');

//    // Save a wrong client ID key.
//    $this->translator->setSetting('project_id', 'wrong client_id');
//    $this->translator->setSetting('key', 'wrong client_secret');
//    $this->translator->save();

//    // Save a correct client ID.
//    $translator->setSetting('client_id', 'correct client_id');
//    $translator->setSetting('client_secret', 'correct client_secret');
//    $translator->save();
    $translator = $job->getTranslator();
    // Make sure the translator returns the correct supported target languages.
    $translator->clearLanguageCache();
    $languages = $translator->getSupportedTargetLanguages('en');
    $this->assertTrue(isset($languages['es']));
    $this->assertTrue(isset($languages['nl']));

    $this->assertTrue($job->canRequestTranslation()->getSuccess());

    $job->requestTranslation();

    tmgmt_smartling_download_file($job);

    // Now it should be needs review.
    foreach ($job->getItems() as $item) {
      $this->assertTrue($item->isNeedsReview());
    }

    $items = $job->getItems();
    $item = end($items);
    $data = $item->getData();
    $this->assertEqual('Text for job item with type test and id 1. nl-NL', $data['dummy']['deep_nesting']['#translation']['#text']);
  }

  /**
   * Tests the UI of the plugin.
   */
  protected function _testSmartlingUi() {
    $this->loginAsAdmin();
    $edit = [
      'settings[client_id]' => 'wrong client_id',
      'settings[client_secret]' => 'wrong client_secret',
    ];
    $this->drupalPostForm('admin/config/regional/tmgmt_translator/manage/' . $this->translator->id(), $edit, t('Save'));
    $this->assertText(t('The "Client ID", the "Client secret" or both are not correct.'));
    $edit = [
      'settings[client_id]' => 'correct client_id',
      'settings[client_secret]' => 'correct client_secret',
    ];
    $this->drupalPostForm('admin/config/regional/tmgmt_translator/manage/' . $this->translator->id(), $edit, t('Save'));
    $this->assertText(t('@label configuration has been updated.', ['@label' => $this->translator->label()]));
  }

}
