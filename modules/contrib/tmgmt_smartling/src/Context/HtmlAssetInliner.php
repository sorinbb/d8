<?php

namespace Drupal\tmgmt_smartling\Context;

//set_time_limit(300);

//require_once 'includes/url_to_absolute.inc'; # or implement your own function to convert relative URLs to absolute

class HtmlAssetInliner {

  # url to save complete page from
  private $url = '';
  # holds parsed html
  private $cookie = '';
  private $html = '';
  # holds DOM object
  private $dom = '';


  protected static $authError = array(
    "response" => array(
      "code" => "AUTHENTICATION_ERROR",
      "data" => array("baseUrl" => NULL, "body" => NULL, "headers" => NULL),
      "messages" => array("Authentication token is empty or invalid."),
    ),
  );

  protected static $uriMissingError = array(
    "response" => array(
      "code" => "VALIDATION_ERROR",
      "data" => array("baseUrl" => NULL, "body" => NULL, "headers" => NULL),
      "messages" => array("fileUri parameter is missing."),
    ),
  );


  /**
   *
   */
  public function __construct() {
    # suppress DOM parsing errors
    libxml_use_internal_errors(TRUE);

    $this->dom = new \DOMDocument();
    $this->dom->preserveWhiteSpace = FALSE;
    # avoid strict error checking
    $this->dom->strictErrorChecking = FALSE;
  }

  /**
   * Gets complete page data and returns generated string
   *
   * @param string $url - url to retrieve
   * @param string $cookie - cookie for authorization
   * @param bool $keepjs - whether to keep javascript
   * @param bool $compress - whether to remove extra whitespaces
   *
   * @return string|void
   * @throws \Exception - throws an exception if provided url isn't in proper format
   */
  public function getCompletePage($url, $cookie = '', $keepjs = TRUE, $compress = FALSE) {
    # validate the URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      throw new \Exception('Invalid URL. Make sure to specify http(s) part.');
    }

    if (empty($url)) {
      return self::$uriMissingError;
    }

    if (!$cookie) {
      return self::$authError;
    }

    $this->url = $url;
    $this->cookie = $cookie;
    $this->html = $this->getUrlContents($this->url);

    $this->embedLocalCss();
    $this->embedLocalJs();
    $this->embedContentImages();


    # remove useless stuff such as <link>, <meta> and <script> tags
    //$this->removeUseless($keepjs);

    # convert <img> tags to data URIs
    //$this->convertImageToDataUri();

    # convert all relative links for <a> tags to absolute
    //$this->toAbsoluteURLs();

    if (strlen($this->html) <= 300) {
      return '';
    }

    return ($compress) ? $this->compress($this->html) : $this->html;
  }

  /**
   * Converts images to data URIs
   */
  private function convertImageToDataUri() {
    $tags = $this->getTags('//img');
    $tagsLength = $tags->length;

    # loop over all <img> tags and convert them to data uri
    for ($i = 0; $i < $tagsLength; $i++) {
      $tag = $tags->item($i);
      $src = $this->getFullUrl($tag->getAttribute('src'));

      if ($this->remote_file_exists($src)) {
        $dataUri = $this->imageToDataUri($src);
        $tag->setAttribute('src', $dataUri);
      }
    }

    # now save html with converted images
    $this->html = $this->dom->saveHTML();
  }

  /**
   * Returns tags list for specified selector
   *
   * @param $selector - xpath selector expression
   *
   * @return DOMNodeList
   */
  private function getTags($selector) {
    $this->dom->loadHTML($this->html);
    $xpath = new DOMXpath($this->dom);
    $tags = $xpath->query($selector);

    # free memory
    libxml_use_internal_errors(FALSE);
    libxml_use_internal_errors(TRUE);
    libxml_clear_errors();
    unset($xpath);
    $xpath = NULL;

    return $tags;
  }

  /**
   * Checks whether or not remote file exists
   *
   * @param $url
   *
   * @return bool
   */
  private function remote_file_exists($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    # don't download content
    curl_setopt($ch, CURLOPT_NOBODY, 1);
    curl_setopt($ch, CURLOPT_FAILONERROR, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    if (curl_exec($ch) !== FALSE) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Converts images from <img> tags to data URIs
   *
   * @param $path - image path eg src value
   *
   * @return string - generated data uri
   */
  private function imageToDataUri($path) {
    $fileType = trim(strtolower(pathinfo($path, PATHINFO_EXTENSION)));
    $mimType = $fileType;

    # since jpg/jpeg images have image/jpeg mime-type
    if (!$fileType || $fileType === 'jpg') {
      $mimType = 'jpeg';
    }
    else {
      if ($fileType === 'ico') {
        $mimType = 'x-icon';
      }
    }

    # make sure that it is an image and convert to data uri
    if (preg_match('#^(gif|png|jp[e]?g|bmp)$#i', $fileType) || $this->isImage($path)) {
      # in case of images from gravatar, etc
      if ($mimType === 'php' || stripos($mimType, 'php') !== FALSE) {
        $mimType = 'jpeg';
      }

      $data = $this->getContents($path);
      $base64 = 'data:image/' . $mimType . ';base64,' . base64_encode($data);

      return $base64;
    }
  }

  /**
   * Removes <link>, <meta> and <script> tags from generated page
   */
  private function removeUseless($keepjs = TRUE) {
    # fix showing up of garbage characters
    $this->html = mb_convert_encoding($this->html, 'HTML-ENTITIES', 'UTF-8');

    $tags = $this->getTags('//meta | //link | //script');

    $tagsLength = $tags->length;

    # get all <link>, <meta> and <script> tags and remove them
    for ($i = 0; $i < $tagsLength; $i++) {
      $tag = $tags->item($i);

      # delete only external scripts
      if (strtolower($tag->nodeName) === 'script') {
        if ($keepjs) {
          if ($tag->getAttribute('src') !== '') {
            $tag->parentNode->removeChild($tag);
          }
        }
        else {
          $tag->parentNode->removeChild($tag);
        }
      }
      elseif (strtolower($tag->nodeName) === 'meta') {
        # keep the charset meta
        if (stripos($tag->getAttribute('content'), 'charset') === FALSE) {
          $tag->parentNode->removeChild($tag);
        }
      }
      else {
        $tag->parentNode->removeChild($tag);
      }
    }

    $this->html = $this->dom->saveHTML();
  }

  /**
   * Converts relative <a> tag paths to absolute paths
   */
  private function toAbsoluteURLs() {
    $links = $this->getTags('//a');

    foreach ($links as $link) {
      $link->setAttribute('href', $this->getFullUrl($link->getAttribute('href')));
    }

    $this->html = $this->dom->saveHTML();
  }

  /**
   * Compresses generated page by removing extra whitespace
   */
  private function compress($string) {
    # remove whitespace
    return str_replace(array(
      "\r\n",
      "\r",
      "\n",
      "\t",
      '  ',
      '    ',
      '    '
    ), ' ', $string);
  }

  /**
   * Gets content for given url
   *
   * @param $url
   *
   * @return string
   */
  private function getContents($url) {
    $data = @file_get_contents($url);

    if ($data) {
      return $data;
    }

    return @file_get_contents(trim($url));
  }


  /**
   * Gets content for given url using curl and optionally using user agent
   *
   * @param $url
   * @param int $timeout
   * @param string $userAgent
   *
   * @return int|mixed
   */
  private function getUrlContents(
    $url,
    $timeout = 0,
    $userAgent = 'Mozilla/5.0 (Macintosh; U; Intel Mac OS X 10_5_8; en-US) AppleWebKit/534.10 (KHTML, like Gecko) Chrome/8.0.552.215 Safari/534.10'
  ) {
    $crl = curl_init();
    curl_setopt($crl, CURLOPT_URL, $url);
    curl_setopt($crl, CURLOPT_RETURNTRANSFER, 1); # return result as string rather than direct output
    curl_setopt($crl, CURLOPT_CONNECTTIMEOUT, $timeout); # set the timeout
    curl_setopt($crl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($crl, CURLOPT_COOKIE, $this->cookie);
    curl_setopt($crl, CURLOPT_USERAGENT, $userAgent); # set our 'user agent'

    curl_setopt($crl, CURLOPT_SSL_VERIFYPEER, FALSE);

    $output = curl_exec($crl);
    curl_close($crl);

    if (!$output) {
      return -1;
    }

    return $output;
  }

  /**
   * Converts relative URLs to absolute URLs
   *
   * @param $url
   *
   * @return bool|string
   */
  private function getFullUrl($url) {
    if (strpos($url, '//') === FALSE) {
      return url_to_absolute($this->url, $url);
    }

    return $url;
  }

  /**
   * Checks if provided path is an image
   *
   * @param $path
   *
   * @return bool
   */
  private function isImage($path) {
    list($width) = @getimagesize($path);

    if (isset($width) && $width) {
      return TRUE;
    }

    return FALSE;
  }

  private function embedLocalCss() {

    //<link rel="stylesheet" href="/sites/default/files/css/css_DImuuvc9S8V88m4n2WP6xWYIYqktcP21urgDq7ksjK8.css?odb4fo" media="all" />
    //<link rel="stylesheet" href="/sites/default/files/css/css_Va4zLdYXDM0x79wYfYIi_RSorpNS_xtrTcNUqq0psQA.css?odb4fo" media="screen" />
    $css = [];
    preg_match_all('/<link rel="stylesheet" href="([^"]+)\?.*" media="([a-zA-Z0-9]*)" \/>/iU', $this->html, $css);

    foreach ($css[1] as $id => $filename) {
      if (strpos($filename, '?') !== FALSE) {
        $fil_splt = explode('?', $filename);
        $filename = reset($fil_splt);
      }

      $path = DRUPAL_ROOT . $filename;
      if (!file_exists($path)) {
        continue;
      }
      $file_content = file_get_contents($path);

      $file_content = $this->embedCssImages($file_content, $path);

      $this->html = str_replace($css[0][$id], "<style media='{$css[2][$id]}'>\n $file_content \n</style>", $this->html);
    }


    //@import url("/core/assets/vendor/normalize-css/normalize.css?odb4fo");
    //@import url("/core/themes/stable/css/toolbar/toolbar.icons.theme.css?odb4fo");
    $css = [];
    preg_match_all('/@import url\("([^"]+)"\);/iU', $this->html, $css);

    foreach ($css[1] as $id => $filename) {
      if (strpos($filename, '?') !== FALSE) {
        $fil_splt = explode('?', $filename);
        $filename = reset($fil_splt);
      }

      $path = DRUPAL_ROOT . $filename;
      if (!file_exists($path)) {
        continue;
      }
      $file_content = file_get_contents($path);

      $file_content = $this->embedCssImages($file_content, $path);

      $this->html = str_replace($css[0][$id], "\n\n $file_content \n\n", $this->html);
    }
  }

  private function embedCssImages($css_content, $path) {
    $matches = array();
    preg_match_all('/url\(([\d\D^)]+)\)/iU', $css_content, $matches);

    foreach ($matches[1] as $k => $img_url) {
      $img_url = trim($img_url, '\'"');
      # make sure that it is an image and convert to data uri
      $fileType = trim(strtolower(pathinfo($img_url, PATHINFO_EXTENSION)));
      if (!preg_match('#^(gif|png|jp[e]?g|bmp|svg)$#i', $fileType)) {
        continue;
      }

      $src = ($img_url[0] === '/') ? DRUPAL_ROOT . $img_url : pathinfo($path, PATHINFO_DIRNAME) . '/' . $img_url;

      if (!file_exists($src) || !($dataUri = file_get_contents($src))) {
        continue;
      }

      $mimType = ($fileType === 'svg') ? 'svg+xml' : 'png';
      $dataUri = 'url("data:image/' . $mimType . ';base64,' . base64_encode($dataUri) . '")';
      $css_content = str_replace($matches[0][$k], $dataUri, $css_content);
    }
    return $css_content;
  }

  private function embedLocalJs() {

    //<script src="/sites/default/files/js/js_BKcMdIbOMdbTdLn9dkUq3KCJfIKKo2SvKoQ1AnB8D-g.js"></script>
    //<script src="/core/assets/vendor/modernizr/modernizr.min.js?v=3.3.1"></script>
    $js = [];
    preg_match_all('/<script src="([^"]+)"><\/script>/iU', $this->html, $js);

    foreach ($js[1] as $id => $filename) {
      if (strpos($filename, '?') !== FALSE) {
        $fil_splt = explode('?', $filename);
        $filename = reset($fil_splt);
      }

      $path = DRUPAL_ROOT . $filename;
      if (!file_exists($path)) {
        continue;
      }
      $file_content = file_get_contents($path);

      $this->html = str_replace($js[0][$id], "<script>\n $file_content \n</script>", $this->html);
    }
  }

  private function embedContentImages() {
    $matches = array();
    preg_match_all('/<img.*src="([^"]+)".*>/iU', $this->html, $matches);

    foreach ($matches[1] as $k => $img_url) {
      $img_url = trim($img_url, '\'"');
      $img_url = str_replace($this->getBaseUrl(), '', $img_url);

      # make sure that it is an image and convert to data uri
      $fileType = trim(strtolower(pathinfo($img_url, PATHINFO_EXTENSION)));
      if (!preg_match('#^(gif|png|jp[e]?g|bmp|svg)$#i', $fileType)) {
        continue;
      }

      $src = DRUPAL_ROOT . $img_url;

      if (!file_exists($src) || !($dataUri = file_get_contents($src))) {
        continue;
      }

      $mimType = ($fileType === 'svg') ? 'svg+xml' : 'png';
      $dataUri = '<img src="data:image/' . $mimType . ';base64,' . base64_encode($dataUri) . '" />';
      $this->html = str_replace($matches[0][$k], $dataUri, $this->html);
    }
    //return $css_content;
  }

  private function getBaseUrl() {
    global $base_url;
    return $base_url;
  }
}