<?php
/**
 * Admin Dashboard Template
 */

if (!defined('ABSPATH')) {
    exit;
}

$stats = Centromex_Order_Delivery::get_stats();
$zones = Centromex_Delivery_Zones::get_all();

// Get orders by status
$pending_orders = wc_get_orders(array(
    'limit' => 50,
    'status' => array('processing', 'on-hold'),
    'meta_query' => array(
        'relation' => 'OR',
        array(
            'key' => '_delivery_status',
            'compare' => 'NOT EXISTS',
        ),
        array(
            'key' => '_delivery_status',
            'value' => 'pending',
        ),
    ),
));

$ready_orders = wc_get_orders(array(
    'limit' => 50,
    'meta_query' => array(
        array(
            'key' => '_delivery_status',
            'value' => 'ready',
        ),
    ),
));

$claimed_orders = wc_get_orders(array(
    'limit' => 50,
    'meta_query' => array(
        array(
            'key' => '_delivery_status',
            'value' => array('claimed', 'out_for_delivery'),
            'compare' => 'IN',
        ),
    ),
));
?>

<div class="wrap centromex-delivery-dashboard">
    <h1><?php _e('Delivery Coordination', 'centromex-delivery'); ?></h1>

    <!-- Stats -->
    <div class="delivery-stats">
        <div class="stat-box">
            <span class="stat-number"><?php echo esc_html($stats['pending']); ?></span>
            <span class="stat-label"><?php _e('Pending', 'centromex-delivery'); ?></span>
        </div>
        <div class="stat-box stat-ready">
            <span class="stat-number"><?php echo esc_html($stats['ready']); ?></span>
            <span class="stat-label"><?php _e('Ready', 'centromex-delivery'); ?></span>
        </div>
        <div class="stat-box stat-claimed">
            <span class="stat-number"><?php echo esc_html($stats['claimed'] + $stats['out_for_delivery']); ?></span>
            <span class="stat-label"><?php _e('In Progress', 'centromex-delivery'); ?></span>
        </div>
        <div class="stat-box stat-delivered">
            <span class="stat-number"><?php echo esc_html($stats['delivered_today']); ?></span>
            <span class="stat-label"><?php _e('Delivered Today', 'centromex-delivery'); ?></span>
        </div>
    </div>

    <div class="delivery-columns">
        <!-- Pending Orders -->
        <div class="delivery-column">
            <h2><?php _e('Orders to Prepare', 'centromex-delivery'); ?></h2>
            <div class="order-list">
                <?php if (empty($pending_orders)): ?>
                    <p class="no-orders"><?php _e('No pending orders', 'centromex-delivery'); ?></p>
                <?php else: ?>
                    <?php foreach ($pending_orders as $order): ?>
                        <div class="order-card" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                            <div class="order-header">
                                <strong>#<?php echo esc_html($order->get_order_number()); ?></strong>
                                <span class="order-total"><?php echo $order->get_formatted_order_total(); ?></span>
                            </div>
                            <div class="order-items">
                                <?php echo esc_html($order->get_item_count()); ?> items
                            </div>
                            <div class="order-actions">
                                <select class="zone-select">
                                    <option value=""><?php _e('Select zone...', 'centromex-delivery'); ?></option>
                                    <?php foreach ($zones as $zone): ?>
                                        <option value="<?php echo esc_attr($zone->name); ?>"><?php echo esc_html($zone->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" class="bag-count" value="1" min="1" max="20" placeholder="Bags">
                                <button class="button mark-ready-btn" disabled><?php _e('Mark Ready', 'centromex-delivery'); ?></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Ready for Pickup -->
        <div class="delivery-column">
            <h2><?php _e('Ready for Pickup', 'centromex-delivery'); ?></h2>
            <div class="order-list">
                <?php if (empty($ready_orders)): ?>
                    <p class="no-orders"><?php _e('No orders ready', 'centromex-delivery'); ?></p>
                <?php else: ?>
                    <?php foreach ($ready_orders as $order): ?>
                        <?php
                        $zone = get_post_meta($order->get_id(), '_delivery_zone', true);
                        $bag_count = get_post_meta($order->get_id(), '_bag_count', true) ?: 1;
                        $ready_at = get_post_meta($order->get_id(), '_ready_at', true);
                        ?>
                        <div class="order-card ready" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                            <div class="order-header">
                                <strong>#<?php echo esc_html($order->get_order_number()); ?></strong>
                                <span class="zone-badge"><?php echo esc_html($zone); ?></span>
                            </div>
                            <div class="order-meta">
                                <span><?php echo esc_html($bag_count); ?> <?php _e('bags', 'centromex-delivery'); ?></span>
                                <?php if ($ready_at): ?>
                                    <span class="ready-time"><?php echo esc_html(human_time_diff(strtotime($ready_at), current_time('timestamp'))); ?> ago</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- In Progress -->
        <div class="delivery-column">
            <h2><?php _e('In Progress', 'centromex-delivery'); ?></h2>
            <div class="order-list">
                <?php if (empty($claimed_orders)): ?>
                    <p class="no-orders"><?php _e('No orders in progress', 'centromex-delivery'); ?></p>
                <?php else: ?>
                    <?php foreach ($claimed_orders as $order): ?>
                        <?php
                        $zone = get_post_meta($order->get_id(), '_delivery_zone', true);
                        $status = get_post_meta($order->get_id(), '_delivery_status', true);
                        $claim = Centromex_Order_Delivery::get_active_claim($order->get_id());
                        ?>
                        <div class="order-card in-progress" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                            <div class="order-header">
                                <strong>#<?php echo esc_html($order->get_order_number()); ?></strong>
                                <span class="status-badge <?php echo esc_attr($status); ?>">
                                    <?php echo $status === 'out_for_delivery' ? __('Out', 'centromex-delivery') : __('Claimed', 'centromex-delivery'); ?>
                                </span>
                            </div>
                            <div class="order-meta">
                                <span class="zone-badge"><?php echo esc_html($zone); ?></span>
                            </div>
                            <?php if ($claim): ?>
                                <div class="driver-info">
                                    <strong><?php echo esc_html($claim->driver_name); ?></strong>
                                    <span><?php echo esc_html(human_time_diff(strtotime($claim->claimed_at), current_time('timestamp'))); ?> ago</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
