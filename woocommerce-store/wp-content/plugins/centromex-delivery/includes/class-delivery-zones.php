<?php
/**
 * Delivery Zones Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class Centromex_Delivery_Zones {

    /**
     * Get all zones
     */
    public static function get_all() {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_zones';
        return $wpdb->get_results("SELECT * FROM $table ORDER BY sort_order ASC, name ASC");
    }

    /**
     * Get zone by ID
     */
    public static function get($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_zones';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    }

    /**
     * Get zone by name
     */
    public static function get_by_name($name) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_zones';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE name = %s", $name));
    }

    /**
     * Create a zone
     */
    public static function create($name, $description = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_zones';

        $result = $wpdb->insert($table, array(
            'name' => $name,
            'description' => $description,
        ), array('%s', '%s'));

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to create zone');
        }

        return $wpdb->insert_id;
    }

    /**
     * Update a zone
     */
    public static function update($id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_zones';

        $update_data = array();
        $format = array();

        if (isset($data['name'])) {
            $update_data['name'] = $data['name'];
            $format[] = '%s';
        }
        if (isset($data['description'])) {
            $update_data['description'] = $data['description'];
            $format[] = '%s';
        }
        if (isset($data['sort_order'])) {
            $update_data['sort_order'] = $data['sort_order'];
            $format[] = '%d';
        }

        if (empty($update_data)) {
            return true;
        }

        $result = $wpdb->update($table, $update_data, array('id' => $id), $format, array('%d'));

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update zone');
        }

        return true;
    }

    /**
     * Delete a zone
     */
    public static function delete($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_zones';

        $result = $wpdb->delete($table, array('id' => $id), array('%d'));

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to delete zone');
        }

        return true;
    }

    /**
     * Get zone options for select dropdown
     */
    public static function get_options() {
        $zones = self::get_all();
        $options = array();

        foreach ($zones as $zone) {
            $options[$zone->name] = $zone->name;
        }

        return $options;
    }
}
