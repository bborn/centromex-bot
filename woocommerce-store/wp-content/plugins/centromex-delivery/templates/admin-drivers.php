<?php
/**
 * Admin Drivers Template
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle actions
if (isset($_POST['action']) && isset($_POST['driver_id']) && wp_verify_nonce($_POST['_wpnonce'], 'centromex_driver_action')) {
    $driver_id = absint($_POST['driver_id']);

    switch ($_POST['action']) {
        case 'approve':
            Centromex_Driver_Manager::approve($driver_id);
            echo '<div class="notice notice-success"><p>' . __('Driver approved', 'centromex-delivery') . '</p></div>';
            break;
        case 'deactivate':
            Centromex_Driver_Manager::deactivate($driver_id);
            echo '<div class="notice notice-success"><p>' . __('Driver deactivated', 'centromex-delivery') . '</p></div>';
            break;
        case 'delete':
            Centromex_Driver_Manager::delete($driver_id);
            echo '<div class="notice notice-success"><p>' . __('Driver deleted', 'centromex-delivery') . '</p></div>';
            break;
    }
}

$pending_drivers = Centromex_Driver_Manager::get_all('pending');
$active_drivers = Centromex_Driver_Manager::get_all('active');
$inactive_drivers = Centromex_Driver_Manager::get_all('inactive');
?>

<div class="wrap centromex-drivers-page">
    <h1><?php _e('Drivers', 'centromex-delivery'); ?></h1>

    <?php if (!empty($pending_drivers)): ?>
    <div class="driver-section pending-section">
        <h2><?php _e('Pending Approval', 'centromex-delivery'); ?> <span class="count">(<?php echo count($pending_drivers); ?>)</span></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Name', 'centromex-delivery'); ?></th>
                    <th><?php _e('Phone', 'centromex-delivery'); ?></th>
                    <th><?php _e('Email', 'centromex-delivery'); ?></th>
                    <th><?php _e('Registered', 'centromex-delivery'); ?></th>
                    <th><?php _e('Actions', 'centromex-delivery'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_drivers as $driver): ?>
                <tr>
                    <td><strong><?php echo esc_html($driver->name); ?></strong></td>
                    <td><?php echo esc_html($driver->phone); ?></td>
                    <td><?php echo esc_html($driver->email); ?></td>
                    <td><?php echo esc_html(human_time_diff(strtotime($driver->created_at), current_time('timestamp'))); ?> ago</td>
                    <td>
                        <form method="post" style="display:inline">
                            <?php wp_nonce_field('centromex_driver_action'); ?>
                            <input type="hidden" name="driver_id" value="<?php echo esc_attr($driver->id); ?>">
                            <button type="submit" name="action" value="approve" class="button button-primary"><?php _e('Approve', 'centromex-delivery'); ?></button>
                            <button type="submit" name="action" value="delete" class="button" onclick="return confirm('Delete this driver?')"><?php _e('Delete', 'centromex-delivery'); ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="driver-section active-section">
        <h2><?php _e('Active Drivers', 'centromex-delivery'); ?> <span class="count">(<?php echo count($active_drivers); ?>)</span></h2>
        <?php if (empty($active_drivers)): ?>
            <p><?php _e('No active drivers yet.', 'centromex-delivery'); ?></p>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Name', 'centromex-delivery'); ?></th>
                    <th><?php _e('Phone', 'centromex-delivery'); ?></th>
                    <th><?php _e('Deliveries', 'centromex-delivery'); ?></th>
                    <th><?php _e('Actions', 'centromex-delivery'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($active_drivers as $driver): ?>
                <tr>
                    <td><strong><?php echo esc_html($driver->name); ?></strong></td>
                    <td><?php echo esc_html($driver->phone); ?></td>
                    <td><?php echo esc_html($driver->delivery_count); ?></td>
                    <td>
                        <form method="post" style="display:inline">
                            <?php wp_nonce_field('centromex_driver_action'); ?>
                            <input type="hidden" name="driver_id" value="<?php echo esc_attr($driver->id); ?>">
                            <button type="submit" name="action" value="deactivate" class="button"><?php _e('Deactivate', 'centromex-delivery'); ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php if (!empty($inactive_drivers)): ?>
    <div class="driver-section inactive-section">
        <h2><?php _e('Inactive Drivers', 'centromex-delivery'); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Name', 'centromex-delivery'); ?></th>
                    <th><?php _e('Phone', 'centromex-delivery'); ?></th>
                    <th><?php _e('Deliveries', 'centromex-delivery'); ?></th>
                    <th><?php _e('Actions', 'centromex-delivery'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inactive_drivers as $driver): ?>
                <tr>
                    <td><?php echo esc_html($driver->name); ?></td>
                    <td><?php echo esc_html($driver->phone); ?></td>
                    <td><?php echo esc_html($driver->delivery_count); ?></td>
                    <td>
                        <form method="post" style="display:inline">
                            <?php wp_nonce_field('centromex_driver_action'); ?>
                            <input type="hidden" name="driver_id" value="<?php echo esc_attr($driver->id); ?>">
                            <button type="submit" name="action" value="approve" class="button"><?php _e('Reactivate', 'centromex-delivery'); ?></button>
                            <button type="submit" name="action" value="delete" class="button" onclick="return confirm('Delete this driver?')"><?php _e('Delete', 'centromex-delivery'); ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="driver-portal-info">
        <h3><?php _e('Driver Portal', 'centromex-delivery'); ?></h3>
        <p><?php _e('Share this link with drivers to register and view available deliveries:', 'centromex-delivery'); ?></p>
        <code><?php echo esc_url(home_url('/driver-portal/')); ?></code>
    </div>
</div>
