<?php
/**
 * @file
 * Contains \Drupal\tmgmt_smartling\Plugin\Block\SmartlingContextBlock.
 */

namespace Drupal\tmgmt_smartling\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'Smartling context' block.
 *
 * @Block(
 *   id = "smartling_context",
 *   admin_label = @Translation("Smartling context"),
 * )
 */
class SmartlingContextBlock extends BlockBase {
    /**
     * {@inheritdoc}
     */
    public function build() {
      $org_id = \Drupal::config('tmgmt.translator.smartling')->get('settings.orgID');
      return array(
        '#theme' => 'smartling_clean_block',
        '#org_id' => $org_id
      );
    }
}