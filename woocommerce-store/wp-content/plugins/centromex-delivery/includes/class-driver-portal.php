<?php
/**
 * Driver Portal Helper
 */

if (!defined('ABSPATH')) {
    exit;
}

class Centromex_Driver_Portal {

    /**
     * Check if current request is authenticated driver
     */
    public static function get_current_driver_id() {
        // Check session/cookie for driver ID
        if (isset($_COOKIE['centromex_driver_id'])) {
            $driver_id = absint($_COOKIE['centromex_driver_id']);
            $driver = Centromex_Driver_Manager::get($driver_id);
            if ($driver && $driver->status === 'active') {
                return $driver_id;
            }
        }
        return 0;
    }

    /**
     * Set driver session
     */
    public static function set_driver_session($driver_id) {
        setcookie('centromex_driver_id', $driver_id, time() + (86400 * 30), '/'); // 30 days
    }

    /**
     * Clear driver session
     */
    public static function clear_driver_session() {
        setcookie('centromex_driver_id', '', time() - 3600, '/');
    }
}
