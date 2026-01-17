<?php
/**
 * Admin Zones Template
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle actions
if (isset($_POST['action']) && wp_verify_nonce($_POST['_wpnonce'], 'centromex_zone_action')) {
    switch ($_POST['action']) {
        case 'add':
            if (!empty($_POST['zone_name'])) {
                $result = Centromex_Delivery_Zones::create(
                    sanitize_text_field($_POST['zone_name']),
                    sanitize_textarea_field($_POST['zone_description'] ?? '')
                );
                if (!is_wp_error($result)) {
                    echo '<div class="notice notice-success"><p>' . __('Zone added', 'centromex-delivery') . '</p></div>';
                }
            }
            break;

        case 'delete':
            if (!empty($_POST['zone_id'])) {
                Centromex_Delivery_Zones::delete(absint($_POST['zone_id']));
                echo '<div class="notice notice-success"><p>' . __('Zone deleted', 'centromex-delivery') . '</p></div>';
            }
            break;

        case 'update':
            if (!empty($_POST['zone_id'])) {
                Centromex_Delivery_Zones::update(absint($_POST['zone_id']), array(
                    'name' => sanitize_text_field($_POST['zone_name']),
                    'description' => sanitize_textarea_field($_POST['zone_description'] ?? ''),
                ));
                echo '<div class="notice notice-success"><p>' . __('Zone updated', 'centromex-delivery') . '</p></div>';
            }
            break;
    }
}

$zones = Centromex_Delivery_Zones::get_all();
?>

<div class="wrap centromex-zones-page">
    <h1><?php _e('Delivery Zones', 'centromex-delivery'); ?></h1>

    <div class="zones-container">
        <div class="zones-list">
            <h2><?php _e('Current Zones', 'centromex-delivery'); ?></h2>
            <?php if (empty($zones)): ?>
                <p><?php _e('No zones configured.', 'centromex-delivery'); ?></p>
            <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Zone Name', 'centromex-delivery'); ?></th>
                        <th><?php _e('Description', 'centromex-delivery'); ?></th>
                        <th width="100"><?php _e('Actions', 'centromex-delivery'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($zones as $zone): ?>
                    <tr>
                        <td><strong><?php echo esc_html($zone->name); ?></strong></td>
                        <td><?php echo esc_html($zone->description); ?></td>
                        <td>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field('centromex_zone_action'); ?>
                                <input type="hidden" name="zone_id" value="<?php echo esc_attr($zone->id); ?>">
                                <button type="submit" name="action" value="delete" class="button button-small" onclick="return confirm('Delete this zone?')"><?php _e('Delete', 'centromex-delivery'); ?></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="add-zone-form">
            <h2><?php _e('Add New Zone', 'centromex-delivery'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('centromex_zone_action'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="zone_name"><?php _e('Zone Name', 'centromex-delivery'); ?></label></th>
                        <td><input type="text" name="zone_name" id="zone_name" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="zone_description"><?php _e('Description', 'centromex-delivery'); ?></label></th>
                        <td><textarea name="zone_description" id="zone_description" class="large-text" rows="3" placeholder="<?php esc_attr_e('Describe the boundaries of this zone...', 'centromex-delivery'); ?>"></textarea></td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="action" value="add" class="button button-primary"><?php _e('Add Zone', 'centromex-delivery'); ?></button>
                </p>
            </form>
        </div>
    </div>
</div>
