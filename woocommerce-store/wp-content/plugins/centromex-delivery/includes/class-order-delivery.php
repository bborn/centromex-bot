<?php
/**
 * Order Delivery Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Centromex_Order_Delivery {

    /**
     * Get orders available for delivery (status = ready, no active claim)
     * Returns PUBLIC info only - NO addresses
     */
    public static function get_available_orders($zone = '') {
        $args = array(
            'limit' => 50,
            'orderby' => 'date',
            'order' => 'ASC',
            'meta_query' => array(
                array(
                    'key' => '_delivery_status',
                    'value' => 'ready',
                ),
            ),
        );

        if ($zone) {
            $args['meta_query'][] = array(
                'key' => '_delivery_zone',
                'value' => $zone,
            );
        }

        $orders = wc_get_orders($args);
        $available = array();

        foreach ($orders as $order) {
            // Only return PUBLIC information - NO customer details
            $available[] = array(
                'id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'zone' => get_post_meta($order->get_id(), '_delivery_zone', true),
                'bag_count' => get_post_meta($order->get_id(), '_bag_count', true) ?: 1,
                'ready_at' => get_post_meta($order->get_id(), '_ready_at', true),
                'ready_ago' => self::time_ago(get_post_meta($order->get_id(), '_ready_at', true)),
            );
        }

        return $available;
    }

    /**
     * Get orders claimed or being delivered by a driver
     * Returns PUBLIC info only - NO addresses
     */
    public static function get_driver_orders($driver_id) {
        global $wpdb;
        $claims_table = $wpdb->prefix . 'centromex_delivery_claims';

        $claims = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $claims_table WHERE driver_id = %d AND status IN ('claimed', 'picked_up') ORDER BY claimed_at DESC",
            $driver_id
        ));

        $orders = array();
        foreach ($claims as $claim) {
            $order = wc_get_order($claim->order_id);
            if (!$order) continue;

            // Only return PUBLIC information - NO customer details
            $orders[] = array(
                'id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'zone' => get_post_meta($order->get_id(), '_delivery_zone', true),
                'bag_count' => get_post_meta($order->get_id(), '_bag_count', true) ?: 1,
                'status' => $claim->status,
                'claimed_at' => $claim->claimed_at,
                'claimed_ago' => self::time_ago($claim->claimed_at),
            );
        }

        return $orders;
    }

    /**
     * Get active claim for an order
     */
    public static function get_active_claim($order_id) {
        global $wpdb;
        $claims_table = $wpdb->prefix . 'centromex_delivery_claims';
        $drivers_table = $wpdb->prefix . 'centromex_drivers';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, d.name as driver_name, d.phone as driver_phone
             FROM $claims_table c
             JOIN $drivers_table d ON c.driver_id = d.id
             WHERE c.order_id = %d AND c.status IN ('claimed', 'picked_up')
             LIMIT 1",
            $order_id
        ));
    }

    /**
     * Claim an order for a driver
     */
    public static function claim_order($order_id, $driver_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_delivery_claims';

        // Check if already claimed
        $existing = self::get_active_claim($order_id);
        if ($existing) {
            return new WP_Error('already_claimed', 'This order has already been claimed');
        }

        // Create claim
        $result = $wpdb->insert($table, array(
            'order_id' => $order_id,
            'driver_id' => $driver_id,
            'status' => 'claimed',
        ), array('%d', '%d', '%s'));

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to claim order');
        }

        // Update order meta
        update_post_meta($order_id, '_delivery_status', 'claimed');
        update_post_meta($order_id, '_claimed_by', $driver_id);
        update_post_meta($order_id, '_claimed_at', current_time('mysql'));

        return $wpdb->insert_id;
    }

    /**
     * Update delivery status
     */
    public static function update_status($order_id, $driver_id, $status) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_delivery_claims';

        // Get the claim
        $claim = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d AND driver_id = %d AND status IN ('claimed', 'picked_up')",
            $order_id,
            $driver_id
        ));

        if (!$claim) {
            return new WP_Error('not_found', 'Claim not found');
        }

        $update_data = array('status' => $status);

        switch ($status) {
            case 'picked_up':
                $update_data['picked_up_at'] = current_time('mysql');
                update_post_meta($order_id, '_delivery_status', 'out_for_delivery');
                break;

            case 'delivered':
                $update_data['delivered_at'] = current_time('mysql');
                update_post_meta($order_id, '_delivery_status', 'delivered');
                Centromex_Driver_Manager::increment_delivery_count($driver_id);
                break;

            case 'cancelled':
                update_post_meta($order_id, '_delivery_status', 'ready');
                delete_post_meta($order_id, '_claimed_by');
                delete_post_meta($order_id, '_claimed_at');
                break;
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            array('id' => $claim->id),
            null,
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update status');
        }

        return true;
    }

    /**
     * Get delivery statistics
     */
    public static function get_stats() {
        global $wpdb;

        $stats = array(
            'pending' => 0,
            'ready' => 0,
            'claimed' => 0,
            'out_for_delivery' => 0,
            'delivered_today' => 0,
        );

        // Count by delivery status
        $results = $wpdb->get_results(
            "SELECT meta_value as status, COUNT(*) as count
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_delivery_status'
             GROUP BY meta_value"
        );

        foreach ($results as $row) {
            if (isset($stats[$row->status])) {
                $stats[$row->status] = (int) $row->count;
            }
        }

        // Delivered today
        $claims_table = $wpdb->prefix . 'centromex_delivery_claims';
        $stats['delivered_today'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $claims_table
             WHERE status = 'delivered' AND DATE(delivered_at) = CURDATE()"
        );

        return $stats;
    }

    /**
     * Format time ago string
     */
    private static function time_ago($datetime) {
        if (!$datetime) return '';

        $time = strtotime($datetime);
        $diff = current_time('timestamp') - $time;

        if ($diff < 60) {
            return __('Just now', 'centromex-delivery');
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return sprintf(_n('%d min ago', '%d mins ago', $mins, 'centromex-delivery'), $mins);
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return sprintf(_n('%d hour ago', '%d hours ago', $hours, 'centromex-delivery'), $hours);
        } else {
            return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $time);
        }
    }
}
