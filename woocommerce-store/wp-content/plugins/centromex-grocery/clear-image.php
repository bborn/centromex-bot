<?php
/**
 * Clear specific processed image hash
 * Access via: /wp-content/plugins/centromex-grocery/clear-image.php?hash=HASH
 */

define('WP_USE_THEMES', false);
require('../../../../wp-load.php');

if (!current_user_can('manage_options')) {
    die('Unauthorized');
}

$hash = isset($_GET['hash']) ? $_GET['hash'] : '';

if (empty($hash)) {
    die('Usage: clear-image.php?hash=IMAGE_HASH');
}

global $wpdb;
$table = $wpdb->prefix . 'centromex_processed_images';

$deleted = $wpdb->delete($table, ['image_hash' => $hash], ['%s']);

if ($deleted) {
    echo "Successfully deleted processed image record for hash: $hash\n";
    echo "The image can now be reprocessed.\n";
} else {
    echo "No record found or delete failed for hash: $hash\n";
}
