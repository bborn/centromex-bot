<?php
/**
 * Plugin Name: Centromex Volunteer Picker
 * Plugin URI: https://centromex.org
 * Description: Mobile-friendly order picking app for volunteers with barcode scanning and photo confirmation
 * Version: 1.0.0
 * Author: Centromex
 * Author URI: https://centromex.org
 * Text Domain: centromex-volunteer-picker
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CENTROMEX_PICKER_VERSION', '1.0.0');
define('CENTROMEX_PICKER_PATH', plugin_dir_path(__FILE__));
define('CENTROMEX_PICKER_URL', plugin_dir_url(__FILE__));

// Include classes
require_once CENTROMEX_PICKER_PATH . 'includes/class-translation-service.php';
require_once CENTROMEX_PICKER_PATH . 'includes/class-picker-api.php';

// Activation hook
register_activation_hook(__FILE__, 'centromex_picker_activate');

function centromex_picker_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Table for tracking picked items
    $picks_table = $wpdb->prefix . 'centromex_order_picks';
    $sql_picks = "CREATE TABLE $picks_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        order_item_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        volunteer_id BIGINT UNSIGNED,
        volunteer_name VARCHAR(100),
        quantity_ordered INT NOT NULL DEFAULT 1,
        quantity_picked INT NOT NULL DEFAULT 0,
        status ENUM('pending', 'picked', 'substituted', 'unavailable') DEFAULT 'pending',
        scanned_barcode VARCHAR(50),
        photo_url VARCHAR(500),
        notes TEXT,
        picked_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY order_item_id (order_item_id),
        KEY product_id (product_id),
        KEY status (status)
    ) $charset_collate;";

    // Table for translated product names cache
    $translations_table = $wpdb->prefix . 'centromex_product_translations';
    $sql_translations = "CREATE TABLE $translations_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        original_name VARCHAR(500) NOT NULL,
        translated_name VARCHAR(500),
        source_lang VARCHAR(10) DEFAULT 'es',
        target_lang VARCHAR(10) DEFAULT 'en',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY product_id (product_id),
        KEY original_name (original_name(100))
    ) $charset_collate;";

    // Table for volunteer sessions
    $sessions_table = $wpdb->prefix . 'centromex_picker_sessions';
    $sql_sessions = "CREATE TABLE $sessions_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_token VARCHAR(64) NOT NULL,
        volunteer_name VARCHAR(100) NOT NULL,
        volunteer_phone VARCHAR(20),
        order_id BIGINT UNSIGNED,
        started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME,
        PRIMARY KEY (id),
        UNIQUE KEY session_token (session_token),
        KEY order_id (order_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_picks);
    dbDelta($sql_translations);
    dbDelta($sql_sessions);

    // Flush rewrite rules for custom endpoint
    flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'centromex_picker_deactivate');

function centromex_picker_deactivate() {
    flush_rewrite_rules();
}

/**
 * Main Plugin Class
 */
class Centromex_Volunteer_Picker {

    private static $instance = null;
    private $api;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->api = new Centromex_Picker_API();
        $this->init_hooks();
    }

    private function init_hooks() {
        // Register REST API routes
        add_action('rest_api_init', array($this->api, 'register_routes'));

        // Add rewrite rules for the SPA
        add_action('init', array($this, 'add_rewrite_rules'));

        // Handle the picker page
        add_action('template_redirect', array($this, 'handle_picker_page'));

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Enqueue admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Add settings link
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
    }

    /**
     * Add rewrite rules for /volunteer-picker endpoint
     */
    public function add_rewrite_rules() {
        add_rewrite_rule(
            '^volunteer-picker/?$',
            'index.php?centromex_picker=1',
            'top'
        );
        add_rewrite_rule(
            '^volunteer-picker/order/([0-9]+)/?$',
            'index.php?centromex_picker=1&picker_order_id=$matches[1]',
            'top'
        );

        add_rewrite_tag('%centromex_picker%', '([0-9]+)');
        add_rewrite_tag('%picker_order_id%', '([0-9]+)');
    }

    /**
     * Handle the picker page request
     */
    public function handle_picker_page() {
        global $wp_query;

        if (!isset($wp_query->query_vars['centromex_picker'])) {
            return;
        }

        // Get order ID if present
        $order_id = isset($wp_query->query_vars['picker_order_id'])
            ? intval($wp_query->query_vars['picker_order_id'])
            : 0;

        // Load the SPA template
        $this->render_spa($order_id);
        exit;
    }

    /**
     * Render the Single Page Application
     */
    private function render_spa($order_id = 0) {
        // Get site info for the SPA
        $site_url = home_url();
        $api_url = rest_url('centromex-picker/v1');
        $nonce = wp_create_nonce('wp_rest');

        include CENTROMEX_PICKER_PATH . 'templates/spa.php';
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'centromex-orders',
            __('Volunteer Picker', 'centromex-volunteer-picker'),
            __('Volunteer Picker', 'centromex-volunteer-picker'),
            'manage_woocommerce',
            'centromex-volunteer-picker',
            array($this, 'render_admin_page')
        );

        // Add standalone menu if centromex-orders doesn't exist
        add_menu_page(
            __('Volunteer Picker', 'centromex-volunteer-picker'),
            __('Volunteer Picker', 'centromex-volunteer-picker'),
            'manage_woocommerce',
            'centromex-picker-standalone',
            array($this, 'render_admin_page'),
            'dashicons-smartphone',
            57
        );
    }

    /**
     * Render admin settings page
     */
    public function render_admin_page() {
        include CENTROMEX_PICKER_PATH . 'templates/admin-settings.php';
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'centromex-picker') === false && strpos($hook, 'centromex-volunteer-picker') === false) {
            return;
        }

        wp_enqueue_style(
            'centromex-picker-admin',
            CENTROMEX_PICKER_URL . 'assets/css/admin.css',
            array(),
            CENTROMEX_PICKER_VERSION
        );
    }

    /**
     * Add settings link to plugins page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=centromex-picker-standalone') . '">' . __('Settings', 'centromex-volunteer-picker') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}

// Initialize plugin
function centromex_volunteer_picker() {
    return Centromex_Volunteer_Picker::instance();
}

add_action('plugins_loaded', 'centromex_volunteer_picker');

// Register settings
add_action('admin_init', function() {
    register_setting('centromex_picker_settings', 'centromex_picker_openai_key');
    register_setting('centromex_picker_settings', 'centromex_picker_access_code');
});
