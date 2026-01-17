<?php
/**
 * Driver Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class Centromex_Driver_Manager {

    /**
     * Get all drivers
     */
    public static function get_all($status = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_drivers';

        $sql = "SELECT * FROM $table";
        if ($status) {
            $sql .= $wpdb->prepare(" WHERE status = %s", $status);
        }
        $sql .= " ORDER BY name ASC";

        return $wpdb->get_results($sql);
    }

    /**
     * Get driver by ID
     */
    public static function get($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_drivers';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    }

    /**
     * Get driver by phone
     */
    public static function get_by_phone($phone) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_drivers';

        // Normalize phone number (remove non-digits)
        $normalized = preg_replace('/[^0-9]/', '', $phone);

        // Try exact match first
        $driver = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE REPLACE(REPLACE(REPLACE(phone, '-', ''), ' ', ''), '(', '') LIKE %s",
            '%' . $normalized . '%'
        ));

        return $driver;
    }

    /**
     * Get driver by email
     */
    public static function get_by_email($email) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_drivers';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE email = %s", $email));
    }

    /**
     * Register a new driver
     */
    public static function register($name, $phone, $email = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_drivers';

        // Check if phone already exists
        $existing = self::get_by_phone($phone);
        if ($existing) {
            return new WP_Error('duplicate', 'A driver with this phone number already exists');
        }

        $result = $wpdb->insert($table, array(
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'status' => 'pending',
        ), array('%s', '%s', '%s', '%s'));

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to register driver');
        }

        return $wpdb->insert_id;
    }

    /**
     * Approve a driver
     */
    public static function approve($id) {
        return self::update_status($id, 'active');
    }

    /**
     * Deactivate a driver
     */
    public static function deactivate($id) {
        return self::update_status($id, 'inactive');
    }

    /**
     * Update driver status
     */
    public static function update_status($id, $status) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_drivers';

        $result = $wpdb->update(
            $table,
            array('status' => $status),
            array('id' => $id),
            array('%s'),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update driver status');
        }

        return true;
    }

    /**
     * Update driver info
     */
    public static function update($id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_drivers';

        $update_data = array();
        $format = array();

        if (isset($data['name'])) {
            $update_data['name'] = $data['name'];
            $format[] = '%s';
        }
        if (isset($data['phone'])) {
            $update_data['phone'] = $data['phone'];
            $format[] = '%s';
        }
        if (isset($data['email'])) {
            $update_data['email'] = $data['email'];
            $format[] = '%s';
        }
        if (isset($data['status'])) {
            $update_data['status'] = $data['status'];
            $format[] = '%s';
        }
        if (isset($data['preferred_zones'])) {
            $update_data['preferred_zones'] = is_array($data['preferred_zones'])
                ? implode(',', $data['preferred_zones'])
                : $data['preferred_zones'];
            $format[] = '%s';
        }

        if (empty($update_data)) {
            return true;
        }

        $result = $wpdb->update($table, $update_data, array('id' => $id), $format, array('%d'));

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update driver');
        }

        return true;
    }

    /**
     * Increment delivery count
     */
    public static function increment_delivery_count($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_drivers';

        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET delivery_count = delivery_count + 1 WHERE id = %d",
            $id
        ));
    }

    /**
     * Delete a driver
     */
    public static function delete($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_drivers';

        $result = $wpdb->delete($table, array('id' => $id), array('%d'));

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to delete driver');
        }

        return true;
    }

    /**
     * Get pending drivers count
     */
    public static function get_pending_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_drivers';
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");
    }
}
