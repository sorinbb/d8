<?php

namespace Drupal\tmgmt_smartling\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\tmgmt_smartling\Context\ContextUploader;
use Psr\Log\LoggerInterface;
use Drupal\Core\Config\ConfigFactory;

/**
 * Executes interface translation queue tasks.
 *
 * @QueueWorker(
 *   id = "smartling_context_upload",
 *   title = @Translation("Upload context"),
 *   cron = {"time" = 1}
 * )
 */
class ContextUpload extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $contextUploader;

  /**
   * The queue object.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * @var LoggerInterface
   */
  protected $logger;

  /**
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * Constructs a new LocaleTranslation object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\tmgmt_smartling\Context\ContextUploader $context_uploader
   *   The module handler.
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The queue object.
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, ContextUploader $context_uploader, QueueInterface $queue, LoggerInterface $logger, ConfigFactory $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->contextUploader = $context_uploader;
    $this->queue = $queue;
    $this->logger = $logger;
    $this->config = $config_factory->get('tmgmt.translator.smartling');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('tmgmt_smartling.utils.context.uploader'),
      $container->get('queue')->get('smartling_context_upload', TRUE),
      $container->get('logger.channel.smartling'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $url = $data['url'];
    $filename = $data['filename'];
    //$date = $data['upload_date'];


    if (!$this->contextUploader->isReadyAcceptContext($filename)) {
      $data['counter'] = (isset($data['counter'])) ? $data['counter'] + 1 : 1;

      $this->queue->createItem($data);
      return;
    }


    $username = $this->config->get('settings.contextUsername');

    try {
      $this->contextUploader->upload($url, $filename, $username);
    } catch (\Exception $e) {
      $this->logger->error($e->getMessage());
      return [];
    }
  }
}
