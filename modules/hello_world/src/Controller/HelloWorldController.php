<?php

namespace Drupal\hello_world\Controller;
class HelloWorldController {
  public function hello($earth) {
    return array(
      '#title' => 'Hello World! '.$earth,
      '#markup' => 'Here is some content.',
    );
  }
}