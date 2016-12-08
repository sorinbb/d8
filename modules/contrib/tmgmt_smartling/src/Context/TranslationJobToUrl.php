<?php
namespace Drupal\tmgmt_smartling\Context;

use Drupal\tmgmt\Entity\JobItem;
use \Drupal\Core\Entity\EntityTypeManagerInterface;

class TranslationJobToUrl {

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Converts TMGMTJobItem into Url where that item can be found on the page.
   *
   * @var JobItem $job_item
   *   translation job item
   * @return string
   */
  public function convert(JobItem $job_item) {
    if (!$job_item->hasTranslator() || $job_item->getTranslator()->getPluginId() !== 'smartling') {
      return '';
    }


    try {
      $entity_type = $job_item->getItemType();
      $id = $job_item->getItemId();
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($id);

      if (isset($entity->smartling_context_url) && !empty($entity->smartling_context_url)) {
        $url = $entity->smartling_context_url;
      }
      else {
        $url = $job_item->getSourceUrl()->setAbsolute()->toString();
      }


    } catch(\Exception $e) {
      $url = '';
    }

    return $url;
  }
}
