<?php
/**
 * Admin orders page template
 *
 * @package Centromex_Grocery
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get orders
$args = array(
    'limit' => 50,
    'orderby' => 'date',
    'order' => 'DESC',
    'status' => array('wc-pending', 'wc-processing', 'wc-on-hold'),
);

$orders = wc_get_orders($args);
?>

<div class="wrap centromex-orders-wrap">
    <h1><?php _e('Centromex Orders - Pedidos de Abarrotes', 'centromex-grocery'); ?></h1>

    <div class="centromex-orders-stats">
        <div class="stat-box pending">
            <span class="stat-number"><?php echo count(wc_get_orders(array('status' => 'pending', 'limit' => -1))); ?></span>
            <span class="stat-label"><?php _e('Pending / Pendientes', 'centromex-grocery'); ?></span>
        </div>
        <div class="stat-box processing">
            <span class="stat-number"><?php echo count(wc_get_orders(array('status' => 'processing', 'limit' => -1))); ?></span>
            <span class="stat-label"><?php _e('Processing / En Proceso', 'centromex-grocery'); ?></span>
        </div>
        <div class="stat-box completed">
            <span class="stat-number"><?php echo count(wc_get_orders(array('status' => 'completed', 'limit' => -1, 'date_created' => '>' . date('Y-m-d', strtotime('-7 days'))))); ?></span>
            <span class="stat-label"><?php _e('Completed (7 days) / Completados', 'centromex-grocery'); ?></span>
        </div>
    </div>

    <h2><?php _e('Orders Awaiting Fulfillment / Pedidos Pendientes', 'centromex-grocery'); ?></h2>

    <?php if (empty($orders)) : ?>
        <p class="no-orders"><?php _e('No pending orders. / No hay pedidos pendientes.', 'centromex-grocery'); ?></p>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped centromex-orders-table">
            <thead>
                <tr>
                    <th><?php _e('Order / Pedido', 'centromex-grocery'); ?></th>
                    <th><?php _e('Customer / Cliente', 'centromex-grocery'); ?></th>
                    <th><?php _e('Address / Dirección', 'centromex-grocery'); ?></th>
                    <th><?php _e('Contact Phone / Teléfono', 'centromex-grocery'); ?></th>
                    <th><?php _e('Delivery Time / Horario', 'centromex-grocery'); ?></th>
                    <th><?php _e('Items / Productos', 'centromex-grocery'); ?></th>
                    <th><?php _e('Total', 'centromex-grocery'); ?></th>
                    <th><?php _e('Status / Estado', 'centromex-grocery'); ?></th>
                    <th><?php _e('Actions / Acciones', 'centromex-grocery'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order) :
                    $contact_phone = get_post_meta($order->get_id(), '_contact_phone', true);
                    $preferred_time = get_post_meta($order->get_id(), '_preferred_delivery_time', true);
                    $delivery_instructions = get_post_meta($order->get_id(), '_delivery_instructions', true);

                    $time_labels = array(
                        'morning' => __('Morning (9am-12pm)', 'centromex-grocery'),
                        'afternoon' => __('Afternoon (12pm-5pm)', 'centromex-grocery'),
                        'evening' => __('Evening (5pm-8pm)', 'centromex-grocery'),
                    );
                ?>
                    <tr>
                        <td>
                            <a href="<?php echo admin_url('post.php?post=' . $order->get_id() . '&action=edit'); ?>">
                                <strong>#<?php echo $order->get_order_number(); ?></strong>
                            </a>
                            <br>
                            <small><?php echo $order->get_date_created()->date('M j, g:ia'); ?></small>
                        </td>
                        <td>
                            <?php echo $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(); ?>
                            <br>
                            <small><?php echo $order->get_billing_email(); ?></small>
                        </td>
                        <td>
                            <?php echo $order->get_billing_address_1(); ?>
                            <?php if ($order->get_billing_address_2()) echo '<br>' . $order->get_billing_address_2(); ?>
                            <br>
                            <?php echo $order->get_billing_city() . ', ' . $order->get_billing_state() . ' ' . $order->get_billing_postcode(); ?>
                            <?php if ($delivery_instructions) : ?>
                                <br><em style="color: #666;">📝 <?php echo esc_html($delivery_instructions); ?></em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo $contact_phone ?: $order->get_billing_phone(); ?></strong>
                        </td>
                        <td>
                            <?php echo isset($time_labels[$preferred_time]) ? $time_labels[$preferred_time] : '—'; ?>
                        </td>
                        <td>
                            <ul style="margin: 0; padding-left: 15px;">
                                <?php foreach ($order->get_items() as $item) : ?>
                                    <li><?php echo $item->get_quantity(); ?>× <?php echo $item->get_name(); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </td>
                        <td>
                            <strong><?php echo $order->get_formatted_order_total(); ?></strong>
                        </td>
                        <td>
                            <?php
                            $status = $order->get_status();
                            $status_name = wc_get_order_status_name($status);
                            ?>
                            <span class="order-status status-<?php echo esc_attr($status); ?>">
                                <?php echo esc_html($status_name); ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?php echo admin_url('post.php?post=' . $order->get_id() . '&action=edit'); ?>" class="button">
                                <?php _e('View / Ver', 'centromex-grocery'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h3><?php _e('Quick Actions / Acciones Rápidas', 'centromex-grocery'); ?></h3>
    <p>
        <a href="<?php echo admin_url('edit.php?post_type=shop_order'); ?>" class="button button-primary">
            <?php _e('View All Orders / Ver Todos los Pedidos', 'centromex-grocery'); ?>
        </a>
        <a href="<?php echo admin_url('edit.php?post_type=product'); ?>" class="button">
            <?php _e('Manage Products / Administrar Productos', 'centromex-grocery'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=wc-reports'); ?>" class="button">
            <?php _e('View Reports / Ver Reportes', 'centromex-grocery'); ?>
        </a>
    </p>
</div>

<style>
.centromex-orders-wrap {
    max-width: 1400px;
}

.centromex-orders-stats {
    display: flex;
    gap: 20px;
    margin: 20px 0;
}

.stat-box {
    background: #fff;
    padding: 20px 30px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    text-align: center;
    min-width: 150px;
}

.stat-box .stat-number {
    display: block;
    font-size: 2.5rem;
    font-weight: bold;
    line-height: 1;
}

.stat-box .stat-label {
    display: block;
    margin-top: 8px;
    color: #666;
    font-size: 0.9rem;
}

.stat-box.pending .stat-number { color: #f0ad4e; }
.stat-box.processing .stat-number { color: #5bc0de; }
.stat-box.completed .stat-number { color: #5cb85c; }

.centromex-orders-table {
    margin-top: 20px;
}

.centromex-orders-table th {
    font-weight: 600;
}

.order-status {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 500;
}

.order-status.status-pending { background: #f0ad4e; color: #fff; }
.order-status.status-processing { background: #5bc0de; color: #fff; }
.order-status.status-on-hold { background: #777; color: #fff; }
.order-status.status-completed { background: #5cb85c; color: #fff; }

.no-orders {
    background: #f9f9f9;
    padding: 30px;
    text-align: center;
    color: #666;
    border-radius: 8px;
}
</style>
