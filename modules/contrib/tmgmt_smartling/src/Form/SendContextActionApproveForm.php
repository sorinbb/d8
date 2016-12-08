<?php
//SendContextActionApproveForm


namespace Drupal\tmgmt_smartling\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Url;
use Drupal\tmgmt_smartling\Exceptions\SmartlingBaseException;
use Drupal\user\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\tmgmt_smartling\Context\ContextUploader;

/**
 * Provides a confirmation form for sending multiple content entities.
 */
class SendContextActionApproveForm extends ConfirmFormBase {

  /**
   * The temp store factory.
   *
   * @var \Drupal\user\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The upload queue.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * The array of entities to send.
   *
   * @var string[]
   */
  protected $entityIds = array();

  /**
   * Temp storage name we are saving entity_ids to.
   *
   * @var string
   */
  protected $tempStorageName = 'tmgmt_smartling_send_context';



  /**
   * Constructs a new UserMultipleCancelConfirm.
   *
   * @param \Drupal\user\PrivateTempStoreFactory $temp_store_factory
   *   The temp store factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   Language manager service.
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The upload queue.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   Immutable config instance that contains smartling settings.
   */
  public function __construct(PrivateTempStoreFactory $temp_store_factory, EntityTypeManagerInterface $entity_type_manager, LanguageManagerInterface $language_manager, QueueInterface $queue, ImmutableConfig $config) {
    $this->tempStoreFactory = $temp_store_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->languageManager = $language_manager;
    $this->queue = $queue;
    $this->config = $config;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user.private_tempstore'),
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('queue')->get('tmgmt_extension_suit_upload'),
      $container->get('config.factory')->get('tmgmt_extension_suit.settings'),
      $container->get('logger.channel.smartling')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'tmgmt_smartling_send_context_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to send context for these items to Smartling?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Sending can take some time, do not close the browser');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('system.admin_content');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Send Context to Smartling');
  }

  public function getTempStorageName() {
    return $this->tempStorageName;
  }


  protected function getEntities(array $entityIds) {
    $entities = [];
    $number_of_entity_types = count(array_unique(array_values($entityIds)));
    if ($number_of_entity_types == 1) {
      $entity_type = array_values($entityIds)[0];
      $ids = array_keys($entityIds);
      /** @var \Drupal\node\NodeInterface[] $nodes */
      $entities = $this->entityTypeManager->getStorage($entity_type)
        ->loadMultiple($ids);
    } else {
      foreach($entityIds as $id => $ent_type) {
        $entities[] = $this->entityTypeManager->getStorage($ent_type)->load($id);
      }
    }
    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $temp_storage_name = $this->getTempStorageName();
    // Retrieve the content to be sent from the temp store.
    $this->entityIds = $this->tempStoreFactory
      ->get($temp_storage_name)
      ->get($this->currentUser()->id());
    if (!$this->entityIds) {
      return new RedirectResponse($this->getCancelUrl()
        ->setAbsolute()
        ->toString());
    }

    $items = [];
    $entities = $this->getEntities($this->entityIds);
    foreach ($entities as $entity) {
      $items[$entity->id()] = $entity->label();
    }
    $form['items'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Entities to contextualize'),
      '#items' => $items,
    ];

    return ConfirmFormBase::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $current_user_id = $this->currentUser()->id();
    $temp_storage_name = $this->getTempStorageName();

    // Clear out the accounts from the temp store.
    $this->tempStoreFactory->get($temp_storage_name)
      ->delete($current_user_id);
    if (!$form_state->getValue('confirm')) {
      return;
    }

    //@todo:change this when we add the support for queues.
    $is_batch = TRUE;
    $operations = [];

    foreach ($this->entityIds as $id => $entity_type) {
      // Make sure all submissions exists.
      $item_data = [
        'entity_type' => $entity_type,
        'entity_id' => $id,
      ];

      if ($is_batch) {
        $operations[] = [
          [get_class($this), 'processBatch'],
          [$item_data],
        ];
      }
      else {
        $this->queue->createItem($item_data);
      }
    }

    $username = \Drupal::config('tmgmt.translator.smartling')->get('settings.contextUsername');
    \Drupal::getContainer()->get('tmgmt_smartling.utils.context.user_auth')->switchUser($username);

    if ($is_batch && $operations) {
      $batch = [
        'title' => t('Uploading to Smartling'),
        'operations' => $operations,
        'finished' => [get_class($this), 'finishBatch'],
      ];
      batch_set($batch);
    }
    else {
      $form_state->setRedirect('system.admin_content');
    }
  }


  /**
   * Processes the sending batch.
   *
   * @param array $data
   *   Keyed array of data to send.
   * @param array $context
   *   The batch context.
   */
  public static function processBatch($data, &$context) {
    if (!isset($context['results']['errors'])) {
      $context['results']['errors'] = [];
      $context['results']['count'] = 0;
    }

    $entity_type_id = $data['entity_type'];
    $entity_id = $data['entity_id'];

    try {
      $job_item = \Drupal::entityTypeManager()->getStorage($entity_type_id)
        ->loadMultiple([$entity_id]);
      $job_item = reset($job_item);

      if ($job_item->getTranslator()->getPluginId() !== 'smartling') {
        return;
      }

      $job = $job_item->getJob();
      $filename = $job->getTranslatorPlugin()->getFileName($job);


      /** @var ContextUploader $context_uploader */
      $context_uploader = \Drupal::getContainer()->get('tmgmt_smartling.utils.context.uploader');
      $url = $context_uploader->jobItemToUrl($job_item);
      $username = \Drupal::config('tmgmt.translator.smartling')->get('settings.contextUsername');

      if ($context_uploader->isReadyAcceptContext($filename)) {
        $result = $context_uploader->upload($url, $filename, $username);
      }
      else {
        $result = [];
      }
    }
    catch(SmartlingBaseException $e) {
      \Drupal::logger('smartling')->error($e->getMessage());
    }


    if (empty($result)) {
      $context['results']['errors'][] = t('Context wasn\'t uploaded. Please see logs for more info.');
    } elseif (isset($result['updatedStringsCount'])) {
      $context['message'] = new FormattableMarkup('Context for "@name" was successfully sent. @updatedStringsCount strings updated.', [
        '@name' => $job_item->label(),
        '@updatedStringsCount' => $result['updatedStringsCount']
      ]);
    }
  }
}