<?php

namespace Drupal\tmgmt_smartling\Context;

use Drupal\tmgmt_smartling\Exceptions\EmptyContextParameterException;
use Drupal\Core\Config\ConfigFactory;
use Drupal\tmgmt_smartling\Smartling\SmartlingApi;
use Drupal\tmgmt_smartling\Smartling\SmartlingApiException;
use Psr\Log\LoggerInterface;
use Drupal\tmgmt_smartling\Exceptions\SmartlingBaseException;

class ContextUploader {

  /**
   * @var TranslationJobToUrl
   */
  protected $urlConverter;

  /**
   * @var ContextCurrentUserAuth
   */
  protected $authenticator;

  /**
   * @var HtmlAssetInliner
   */
  protected $assetInliner;

  /**
   * @var \Drupal\Core\Config\Config | \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * @var SmartlingApi
   */
  protected $api;

  /**
   * @var LoggerInterface
   */
  protected $logger;

  public function __construct(TranslationJobToUrl $url_converter, ContextUserAuth $auth, HtmlAssetInliner $html_asset_inliner,
                              ConfigFactory $config_factory, LoggerInterface $logger) {
    $this->urlConverter = $url_converter;
    $this->authenticator = $auth;
    $this->assetInliner = $html_asset_inliner;

    $this->config = $config_factory->get('tmgmt.translator.smartling');
    $this->api = $this->getApi(TRUE);
    $this->logger = $logger;
  }

  public function jobItemToUrl($job_item) {
    return $this->urlConverter->convert($job_item);
  }

  /**
   * @param $url
   * @param string $username
   * @return mixed|string|void
   * @throws EmptyContextParameterException
   */
  public function getContextualizedPage($url, $username = '') {
    if (empty($url)) {
      throw new EmptyContextParameterException('Context url must be a non-empty string.');
    }

    if (empty($username)) {
      $username = $this->authenticator->getCurrentAccount()->getAccountName();
    }

    $cookies = $this->authenticator->getCookies($username);
    $html = $this->assetInliner->getCompletePage($url, $cookies);

    $html = str_replace('<html ', '<html class="sl-override-context" ', $html);
    $html = str_replace('<p></p>', "\n", $html);

    return $html;
  }

  /**
   * @param string $url
   * @param string $filename
   * @param string $username
   * @return bool
   * @throws EmptyContextParameterException
   */
  public function upload($url, $filename = '', $username = '') {
    if (empty($url)) {
      throw new EmptyContextParameterException('Context url must be a non-empty field.');
    }

    try {
      $html = $this->getContextualizedPage($url, $username);

      $response = $this->uploadContextBody($url, $html, $filename);

      if (!empty($response['items'])) {
        foreach($response['items'] as $resource) {
          $this->uploadAsset($resource['url']);
        }
      }
    } catch (SmartlingApiException $e) {
      $this->logger->error($e->getMessage());
      return [];

    } catch (SmartlingBaseException $e) {
      $this->logger->error($e->getMessage());
      return [];
    }
    $this->logger->info('Context upload for file @filename completed successfully.', ['@filename' => $filename]);
    return $response;
  }

  /**
   * @param bool $override_service_url
   * @return SmartlingApi
   */
  protected function getApi($override_service_url = FALSE) {
    $config = $this->config;

    $key = $config->get('settings.key');
    $project_id = $config->get('settings.project_id');
    //$api_url = $config->get('settings.api_url');
    $client = \Drupal::getContainer()->get('http_client');

    if ($override_service_url) {
      $smartlingApi = new SmartlingApi($key, $project_id, $client, 'https://api.smartling.com/context-api/v2');
    } else {
      $smartlingApi = new SmartlingApi($key, $project_id, $client);
    }

    return $smartlingApi;
  }

  /**
   * @param $url
   * @param $html
   * @param $filename
   * @return array
   * @throws EmptyContextParameterException
   */
  protected function uploadContextBody($url, $html, $filename) {
    $orgId = $this->config->get('settings.orgID');
    if (empty($orgId)) {
      throw new EmptyContextParameterException('OrgId is a mandatory field. Please, fill it in.');
    }

    if (!empty($filename)) {
      $response = $this->api->uploadContext(array('url' => $url, 'html' => $html, 'fileUri' => $filename,
        'orgId' => $orgId), ['html' => ['name' => 'context.html', 'content_type' => 'text/html']]);
    }
    $response2 = $this->api->uploadContext(array('url' => $url, 'html' => $html,
      'orgId' => $orgId), ['html' => ['name' => 'context.html', 'content_type' => 'text/html']]);

    if (empty($response)) {
      $response = $response2;
    }

    return $response;
  }

  /**
   * @param string $url
   */
  protected function uploadAsset($url) {
    $orgId = $this->config->get('settings.orgID');

    $resource['resource'] = file_get_contents($url);
    $contet_type = get_headers($resource['url'], 1)["Content-Type"];
    //$resource['resource'] = @fopen($resource['url'], 'r');//$content;
    $resource['orgId'] = $orgId;

    $res_fil = basename($resource['url']);
    $res_fil = (strpos($res_fil, '?') === FALSE) ? $res_fil : strstr($res_fil, '?', TRUE);
    $res = $this->api->putResource($resource, ['resource' => ['name' => $res_fil, 'content_type' => $contet_type]]);
  }

  /**
   * @param $filename
   * @return bool
   */
  public function isReadyAcceptContext($filename) {
    $api = $this->getApi(FALSE);

    try {
      $res = $api->getAuthorizedLocales($filename);
      $res = isset($res['locales']) && !empty($res['locales']);

      if (!$res) {
        $this->logger->warning('File "@filename" is not ready to accept context. Most likely it is being processed by Smartling right now.',
          ['@filename' => $filename]);
      }

      return $res;
    }
    catch (\Exception $e) {
      $this->logger->warning($e->getMessage());
      return FALSE;
    }
    return FALSE;
  }
}