<?php
/**
 * @file
 * Contains \Drupal\tmgmt_smartling_test\Controller\SmartlingTranslatorTestController.
 */

namespace Drupal\tmgmt_smartling_test\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mock services for Smartling translator.
 */
class SmartlingTranslatorTestController {

  /**
   * Page callback for getting the supported languages.
   */
  public function locales_list(Request $request) {
    $language_es = new \stdClass();
    $language_es->name = 'Spanish';
    $language_es->locale = 'es';
    $language_es->translated = 'Español';

    $language_nl = new \stdClass();
    $language_nl->name = 'Dutch';
    $language_nl->locale = 'nl';
    $language_nl->translated = 'Dutch';

    return JsonResponse::create($this->formatResponseData(['locales' => [$language_es, $language_nl]]));
  }

  public function get_status(Request $request) {
    $status = [
      "fileUri" => $request->get('fileUri'),
      "stringCount" => 100,
      "wordCount" => 100,
      "approvedStringCount" => 50,
      "completedStringCount" => 25,
      "lastUploaded" => date('Y-m-d\Thh:mm:ss'),
      "fileType" => 'xliff',
    ];

    return JsonResponse::create($this->formatResponseData($status));
  }

  public function upload_file(Request $request) {
    $upload_status = [
      'overWritten' => 'true',
      'stringCount' => 100,
      'wordCount' => 20,
    ];

    return JsonResponse::create($this->formatResponseData($upload_status));
  }

  public function download_file(Request $request) {
    $xliff_string = '<?xml version="1.0" encoding="UTF-8"?> <xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:oasis:names:tc:xliff:document:1.2 xliff-core-1.2-strict.xsd"> <file original="xliff-core-1.2-strict.xsd" source-language="en-EN" target-language="nl-NL" datatype="plaintext" date="2015-12-07T02:12:22Z"> <header> <phase-group> <phase tool-id="tmgmt" phase-name="extraction" process-name="extraction" job-id="1"/> </phase-group> <tool tool-id="tmgmt" tool-name="Drupal Translation Management Tools"/> </header> <body> <group id="1"> <note>test_source:test:1</note> <trans-unit id="1][dummy][deep_nesting" resname="1][dummy][deep_nesting"> <source xml:lang="en-EN">Text for job item with type test and id 1.</source> <target xml:lang="nl-NL">Text for job item with type test and id 1. nl-NL</target> <note>Label for job item with type @type and id @id.</note> </trans-unit> </group> </body> </file> </xliff>';
    return new Response($xliff_string);
  }

  protected function authorize(Request $request) {
    // @todo implement authorization here via validating project_id and key.
  }

  protected function formatResponseData($data, $code = 'SUCCESS', $messages = []) {
    $root = new \stdClass();
    $root->code = $code;
    $root->messages = $messages;
    $root->data = $data;

    $response = new \stdClass();
    $response->response = $root;

    return $response;
  }

}
