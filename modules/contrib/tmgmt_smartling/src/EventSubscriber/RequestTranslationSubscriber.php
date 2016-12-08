<?php

namespace Drupal\tmgmt_smartling\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use \Drupal\tmgmt\JobInterface;
use Drupal\tmgmt_smartling\Context\TranslationJobToUrl;
use Drupal\tmgmt_smartling\Event\RequestTranslationEvent;
use Drupal\Core\Queue\QueueFactory;

class RequestTranslationSubscriber implements EventSubscriberInterface {

  const WAIT_BEFORE_CONTEXT_UPLOAD = 600;

  protected $contextUploadQueue;


  public function __construct(QueueFactory $queue, TranslationJobToUrl $url_converter) {
    $this->contextUploadQueue = $queue->get('smartling_context_upload', TRUE);
    $this->urlConverter = $url_converter;
  }

  /**
   * Code that should be triggered on event specified
   */
  public function onUploadRequest(RequestTranslationEvent $event) {
    /** @var JobInterface $job */
    $job = $event->getJob();
    if ($job->getTranslator()->getPluginId() !== 'smartling') {
      return;
    }

    $job_items = $job->getItems();
    if (empty($job_items)) {
      return;
    }

    $filename = $job->getTranslatorPlugin()->getFileName($job);
    foreach ($job_items as $item) {
      $url = $this->urlConverter->convert($item);
      $this->contextUploadQueue->createItem(['url' => $url, 'filename' => $filename, 'upload_date' => time() + self::WAIT_BEFORE_CONTEXT_UPLOAD]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // For this example I am using KernelEvents constants (see below a full list).
    $events = [];
    $events[RequestTranslationEvent::REQUEST_TRANSLATION_EVENT][] = ['onUploadRequest'];
    return $events;
  }

}