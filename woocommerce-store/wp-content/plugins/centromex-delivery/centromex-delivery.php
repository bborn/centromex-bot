<?php
/**
 * Plugin Name: Centromex Delivery Coordination
 * Plugin URI: https://centromex.org
 * Description: Delivery coordination system for volunteer drivers. Drivers can view and claim orders without seeing customer addresses.
 * Version: 1.0.0
 * Author: Centromex
 * Author URI: https://centromex.org
 * Text Domain: centromex-delivery
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CENTROMEX_DELIVERY_VERSION', '1.0.0');
define('CENTROMEX_DELIVERY_PATH', plugin_dir_path(__FILE__));
define('CENTROMEX_DELIVERY_URL', plugin_dir_url(__FILE__));

// Include classes
require_once CENTROMEX_DELIVERY_PATH . 'includes/class-delivery-zones.php';
require_once CENTROMEX_DELIVERY_PATH . 'includes/class-driver-manager.php';
require_once CENTROMEX_DELIVERY_PATH . 'includes/class-order-delivery.php';
require_once CENTROMEX_DELIVERY_PATH . 'includes/class-driver-portal.php';

// Activation hook
register_activation_hook(__FILE__, 'centromex_delivery_activate');

function centromex_delivery_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Drivers table
    $drivers_table = $wpdb->prefix . 'centromex_drivers';
    $sql_drivers = "CREATE TABLE $drivers_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED,
        name VARCHAR(255) NOT NULL,
        phone VARCHAR(50),
        email VARCHAR(255),
        status ENUM('pending', 'active', 'inactive') DEFAULT 'pending',
        preferred_zones TEXT,
        delivery_count INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY status (status)
    ) $charset_collate;";

    // Zones table
    $zones_table = $wpdb->prefix . 'centromex_zones';
    $sql_zones = "CREATE TABLE $zones_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        sort_order INT DEFAULT 0,
        PRIMARY KEY (id)
    ) $charset_collate;";

    // Delivery claims table
    $claims_table = $wpdb->prefix . 'centromex_delivery_claims';
    $sql_claims = "CREATE TABLE $claims_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        driver_id BIGINT UNSIGNED NOT NULL,
        claimed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        picked_up_at DATETIME,
        delivered_at DATETIME,
        status ENUM('claimed', 'picked_up', 'delivered', 'cancelled') DEFAULT 'claimed',
        notes TEXT,
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY driver_id (driver_id),
        KEY status (status)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_drivers);
    dbDelta($sql_zones);
    dbDelta($sql_claims);

    // Create driver role
    add_role('centromex_driver', __('Centromex Driver', 'centromex-delivery'), array(
        'read' => true,
    ));

    // Insert default zones
    $existing = $wpdb->get_var("SELECT COUNT(*) FROM $zones_table");
    if ($existing == 0) {
        $default_zones = array(
            array('name' => 'Northeast', 'description' => 'North of Lake St, East of 35W'),
            array('name' => 'Southeast', 'description' => 'South of Lake St, East of 35W'),
            array('name' => 'North Minneapolis', 'description' => 'North of downtown, West of 35W'),
            array('name' => 'South Minneapolis', 'description' => 'South of downtown, West of 35W'),
            array('name' => 'Downtown', 'description' => 'Within 394/94/35W loop'),
            array('name' => 'St. Paul - West', 'description' => 'West of downtown St. Paul'),
            array('name' => 'St. Paul - East', 'description' => 'East of downtown St. Paul'),
            array('name' => 'Suburbs - North', 'description' => 'Brooklyn Park, Brooklyn Center, etc.'),
            array('name' => 'Suburbs - South', 'description' => 'Bloomington, Richfield, etc.'),
        );

        foreach ($default_zones as $i => $zone) {
            $wpdb->insert($zones_table, array(
                'name' => $zone['name'],
                'description' => $zone['description'],
                'sort_order' => $i,
            ));
        }
    }

    // Create driver portal page if not exists
    $portal_page = get_page_by_path('driver-portal');
    if (!$portal_page) {
        wp_insert_post(array(
            'post_title' => __('Driver Portal', 'centromex-delivery'),
            'post_name' => 'driver-portal',
            'post_content' => '[centromex_driver_portal]',
            'post_status' => 'publish',
            'post_type' => 'page',
        ));
    }

    flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'centromex_delivery_deactivate');

function centromex_delivery_deactivate() {
    flush_rewrite_rules();
}

/**
 * Main Centromex Delivery Class
 */
class Centromex_Delivery {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Enqueue scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));

        // Order meta box for delivery info
        add_action('add_meta_boxes', array($this, 'add_order_meta_box'));
        add_action('woocommerce_process_shop_order_meta', array($this, 'save_order_delivery_meta'));

        // Add delivery status column to orders list
        add_filter('manage_edit-shop_order_columns', array($this, 'add_delivery_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_delivery_column'), 10, 2);

        // HPOS compatibility
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_delivery_column'));
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'render_delivery_column_hpos'), 10, 2);

        // AJAX handlers
        add_action('wp_ajax_centromex_mark_ready', array($this, 'ajax_mark_ready'));
        add_action('wp_ajax_centromex_claim_order', array($this, 'ajax_claim_order'));
        add_action('wp_ajax_nopriv_centromex_claim_order', array($this, 'ajax_claim_order'));
        add_action('wp_ajax_centromex_update_delivery_status', array($this, 'ajax_update_delivery_status'));
        add_action('wp_ajax_nopriv_centromex_update_delivery_status', array($this, 'ajax_update_delivery_status'));
        add_action('wp_ajax_centromex_get_available_orders', array($this, 'ajax_get_available_orders'));
        add_action('wp_ajax_nopriv_centromex_get_available_orders', array($this, 'ajax_get_available_orders'));

        // Shortcode for driver portal
        add_shortcode('centromex_driver_portal', array($this, 'render_driver_portal_shortcode'));

        // Driver registration
        add_action('wp_ajax_nopriv_centromex_driver_register', array($this, 'ajax_driver_register'));
        add_action('wp_ajax_centromex_driver_register', array($this, 'ajax_driver_register'));

        // Driver login check
        add_action('wp_ajax_centromex_driver_login', array($this, 'ajax_driver_login'));
        add_action('wp_ajax_nopriv_centromex_driver_login', array($this, 'ajax_driver_login'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Delivery', 'centromex-delivery'),
            __('Delivery', 'centromex-delivery'),
            'manage_woocommerce',
            'centromex-delivery',
            array($this, 'render_delivery_dashboard'),
            'dashicons-car',
            57
        );

        add_submenu_page(
            'centromex-delivery',
            __('Ready for Delivery', 'centromex-delivery'),
            __('Ready for Delivery', 'centromex-delivery'),
            'manage_woocommerce',
            'centromex-delivery',
            array($this, 'render_delivery_dashboard')
        );

        add_submenu_page(
            'centromex-delivery',
            __('Drivers', 'centromex-delivery'),
            __('Drivers', 'centromex-delivery'),
            'manage_woocommerce',
            'centromex-drivers',
            array($this, 'render_drivers_page')
        );

        add_submenu_page(
            'centromex-delivery',
            __('Zones', 'centromex-delivery'),
            __('Zones', 'centromex-delivery'),
            'manage_woocommerce',
            'centromex-zones',
            array($this, 'render_zones_page')
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'centromex-delivery') === false && strpos($hook, 'centromex-drivers') === false && strpos($hook, 'centromex-zones') === false) {
            // Also load on order edit pages
            $screen = get_current_screen();
            if (!$screen || ($screen->id !== 'shop_order' && $screen->id !== 'woocommerce_page_wc-orders')) {
                return;
            }
        }

        wp_enqueue_style(
            'centromex-delivery-admin',
            CENTROMEX_DELIVERY_URL . 'assets/css/admin.css',
            array(),
            CENTROMEX_DELIVERY_VERSION
        );

        wp_enqueue_script(
            'centromex-delivery-admin',
            CENTROMEX_DELIVERY_URL . 'assets/js/admin.js',
            array('jquery'),
            CENTROMEX_DELIVERY_VERSION,
            true
        );

        wp_localize_script('centromex-delivery-admin', 'centromexDelivery', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('centromex-delivery'),
        ));
    }

    /**
     * Enqueue frontend scripts
     */
    public function enqueue_frontend_scripts() {
        if (!is_page('driver-portal')) {
            return;
        }

        wp_enqueue_style(
            'centromex-delivery-portal',
            CENTROMEX_DELIVERY_URL . 'assets/css/portal.css',
            array(),
            CENTROMEX_DELIVERY_VERSION
        );

        wp_enqueue_script(
            'centromex-delivery-portal',
            CENTROMEX_DELIVERY_URL . 'assets/js/portal.js',
            array('jquery'),
            CENTROMEX_DELIVERY_VERSION,
            true
        );

        wp_localize_script('centromex-delivery-portal', 'centromexPortal', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('centromex-delivery-portal'),
        ));
    }

    /**
     * Add meta box to order edit page
     */
    public function add_order_meta_box() {
        $screen = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'centromex_delivery_meta_box',
            __('Delivery Coordination', 'centromex-delivery'),
            array($this, 'render_order_meta_box'),
            $screen,
            'side',
            'high'
        );
    }

    /**
     * Render order meta box
     */
    public function render_order_meta_box($post_or_order) {
        $order = ($post_or_order instanceof WC_Order) ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order) return;

        $order_id = $order->get_id();
        $delivery_status = get_post_meta($order_id, '_delivery_status', true) ?: 'pending';
        $delivery_zone = get_post_meta($order_id, '_delivery_zone', true);
        $bag_count = get_post_meta($order_id, '_bag_count', true) ?: 1;

        $zones = Centromex_Delivery_Zones::get_all();
        $claim = Centromex_Order_Delivery::get_active_claim($order_id);

        wp_nonce_field('centromex_delivery_meta', 'centromex_delivery_nonce');
        ?>
        <div class="centromex-delivery-meta">
            <p>
                <label for="delivery_status"><strong><?php _e('Delivery Status:', 'centromex-delivery'); ?></strong></label>
                <select name="delivery_status" id="delivery_status" class="widefat">
                    <option value="pending" <?php selected($delivery_status, 'pending'); ?>><?php _e('Pending (not ready)', 'centromex-delivery'); ?></option>
                    <option value="ready" <?php selected($delivery_status, 'ready'); ?>><?php _e('Ready for Pickup', 'centromex-delivery'); ?></option>
                    <option value="claimed" <?php selected($delivery_status, 'claimed'); ?>><?php _e('Claimed by Driver', 'centromex-delivery'); ?></option>
                    <option value="out_for_delivery" <?php selected($delivery_status, 'out_for_delivery'); ?>><?php _e('Out for Delivery', 'centromex-delivery'); ?></option>
                    <option value="delivered" <?php selected($delivery_status, 'delivered'); ?>><?php _e('Delivered', 'centromex-delivery'); ?></option>
                </select>
            </p>

            <p>
                <label for="delivery_zone"><strong><?php _e('Delivery Zone:', 'centromex-delivery'); ?></strong></label>
                <select name="delivery_zone" id="delivery_zone" class="widefat">
                    <option value=""><?php _e('Select zone...', 'centromex-delivery'); ?></option>
                    <?php foreach ($zones as $zone): ?>
                        <option value="<?php echo esc_attr($zone->name); ?>" <?php selected($delivery_zone, $zone->name); ?>>
                            <?php echo esc_html($zone->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label for="bag_count"><strong><?php _e('Bag Count:', 'centromex-delivery'); ?></strong></label>
                <input type="number" name="bag_count" id="bag_count" class="widefat" value="<?php echo esc_attr($bag_count); ?>" min="1">
            </p>

            <?php if ($claim): ?>
                <div class="delivery-claim-info">
                    <h4><?php _e('Claimed By:', 'centromex-delivery'); ?></h4>
                    <p>
                        <strong><?php echo esc_html($claim->driver_name); ?></strong><br>
                        <?php echo esc_html(human_time_diff(strtotime($claim->claimed_at), current_time('timestamp'))); ?> ago
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Save order delivery meta
     */
    public function save_order_delivery_meta($order_id) {
        if (!isset($_POST['centromex_delivery_nonce']) || !wp_verify_nonce($_POST['centromex_delivery_nonce'], 'centromex_delivery_meta')) {
            return;
        }

        if (isset($_POST['delivery_status'])) {
            update_post_meta($order_id, '_delivery_status', sanitize_text_field($_POST['delivery_status']));
        }
        if (isset($_POST['delivery_zone'])) {
            update_post_meta($order_id, '_delivery_zone', sanitize_text_field($_POST['delivery_zone']));
        }
        if (isset($_POST['bag_count'])) {
            update_post_meta($order_id, '_bag_count', absint($_POST['bag_count']));
        }
    }

    /**
     * Add delivery column to orders list
     */
    public function add_delivery_column($columns) {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'order_status') {
                $new_columns['delivery_status'] = __('Delivery', 'centromex-delivery');
            }
        }
        return $new_columns;
    }

    /**
     * Render delivery column
     */
    public function render_delivery_column($column, $post_id) {
        if ($column !== 'delivery_status') return;

        $status = get_post_meta($post_id, '_delivery_status', true) ?: 'pending';
        $zone = get_post_meta($post_id, '_delivery_zone', true);

        $status_labels = array(
            'pending' => __('Pending', 'centromex-delivery'),
            'ready' => __('Ready', 'centromex-delivery'),
            'claimed' => __('Claimed', 'centromex-delivery'),
            'out_for_delivery' => __('Out', 'centromex-delivery'),
            'delivered' => __('Delivered', 'centromex-delivery'),
        );

        $status_colors = array(
            'pending' => '#999',
            'ready' => '#0073aa',
            'claimed' => '#ffb900',
            'out_for_delivery' => '#00a32a',
            'delivered' => '#2271b1',
        );

        echo '<span class="delivery-status-badge" style="background:' . esc_attr($status_colors[$status] ?? '#999') . '">';
        echo esc_html($status_labels[$status] ?? $status);
        echo '</span>';

        if ($zone) {
            echo '<br><small>' . esc_html($zone) . '</small>';
        }
    }

    /**
     * Render delivery column for HPOS
     */
    public function render_delivery_column_hpos($column, $order) {
        if ($column !== 'delivery_status') return;
        $this->render_delivery_column($column, $order->get_id());
    }

    /**
     * Render delivery dashboard
     */
    public function render_delivery_dashboard() {
        include CENTROMEX_DELIVERY_PATH . 'templates/admin-dashboard.php';
    }

    /**
     * Render drivers page
     */
    public function render_drivers_page() {
        include CENTROMEX_DELIVERY_PATH . 'templates/admin-drivers.php';
    }

    /**
     * Render zones page
     */
    public function render_zones_page() {
        include CENTROMEX_DELIVERY_PATH . 'templates/admin-zones.php';
    }

    /**
     * Render driver portal shortcode
     */
    public function render_driver_portal_shortcode($atts) {
        ob_start();
        include CENTROMEX_DELIVERY_PATH . 'templates/driver-portal.php';
        return ob_get_clean();
    }

    /**
     * AJAX: Mark order as ready for delivery
     */
    public function ajax_mark_ready() {
        check_ajax_referer('centromex-delivery', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'), 403);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $zone = isset($_POST['zone']) ? sanitize_text_field($_POST['zone']) : '';
        $bag_count = isset($_POST['bag_count']) ? absint($_POST['bag_count']) : 1;

        if (!$order_id || !$zone) {
            wp_send_json_error(array('message' => 'Missing required fields'), 400);
        }

        update_post_meta($order_id, '_delivery_status', 'ready');
        update_post_meta($order_id, '_delivery_zone', $zone);
        update_post_meta($order_id, '_bag_count', $bag_count);
        update_post_meta($order_id, '_ready_at', current_time('mysql'));

        wp_send_json_success(array(
            'message' => sprintf(__('Order #%d marked as ready for delivery', 'centromex-delivery'), $order_id),
        ));
    }

    /**
     * AJAX: Claim order for delivery
     */
    public function ajax_claim_order() {
        check_ajax_referer('centromex-delivery-portal', 'nonce');

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $driver_id = isset($_POST['driver_id']) ? absint($_POST['driver_id']) : 0;

        if (!$order_id || !$driver_id) {
            wp_send_json_error(array('message' => 'Missing required fields'), 400);
        }

        // Verify driver is approved
        $driver = Centromex_Driver_Manager::get($driver_id);
        if (!$driver || $driver->status !== 'active') {
            wp_send_json_error(array('message' => 'Driver not approved'), 403);
        }

        // Check order is available
        $status = get_post_meta($order_id, '_delivery_status', true);
        if ($status !== 'ready') {
            wp_send_json_error(array('message' => 'Order is not available for claiming'), 400);
        }

        // Create claim
        $result = Centromex_Order_Delivery::claim_order($order_id, $driver_id);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }

        wp_send_json_success(array(
            'message' => __('Order claimed! Pick up at Centromex to get the delivery address.', 'centromex-delivery'),
        ));
    }

    /**
     * AJAX: Update delivery status
     */
    public function ajax_update_delivery_status() {
        check_ajax_referer('centromex-delivery-portal', 'nonce');

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $driver_id = isset($_POST['driver_id']) ? absint($_POST['driver_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';

        if (!$order_id || !$driver_id || !$status) {
            wp_send_json_error(array('message' => 'Missing required fields'), 400);
        }

        $result = Centromex_Order_Delivery::update_status($order_id, $driver_id, $status);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }

        $messages = array(
            'picked_up' => __('Order marked as picked up. Safe travels!', 'centromex-delivery'),
            'delivered' => __('Order marked as delivered. Thank you!', 'centromex-delivery'),
            'cancelled' => __('Claim cancelled. Order is available again.', 'centromex-delivery'),
        );

        wp_send_json_success(array(
            'message' => $messages[$status] ?? __('Status updated', 'centromex-delivery'),
        ));
    }

    /**
     * AJAX: Get available orders for driver portal
     */
    public function ajax_get_available_orders() {
        check_ajax_referer('centromex-delivery-portal', 'nonce');

        $zone = isset($_GET['zone']) ? sanitize_text_field($_GET['zone']) : '';
        $driver_id = isset($_GET['driver_id']) ? absint($_GET['driver_id']) : 0;

        $orders = Centromex_Order_Delivery::get_available_orders($zone);
        $my_orders = array();

        if ($driver_id) {
            $my_orders = Centromex_Order_Delivery::get_driver_orders($driver_id);
        }

        wp_send_json_success(array(
            'available' => $orders,
            'my_orders' => $my_orders,
        ));
    }

    /**
     * AJAX: Driver registration
     */
    public function ajax_driver_register() {
        check_ajax_referer('centromex-delivery-portal', 'nonce');

        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

        if (!$name || !$phone) {
            wp_send_json_error(array('message' => 'Name and phone are required'), 400);
        }

        $driver_id = Centromex_Driver_Manager::register($name, $phone, $email);
        if (is_wp_error($driver_id)) {
            wp_send_json_error(array('message' => $driver_id->get_error_message()), 400);
        }

        wp_send_json_success(array(
            'message' => __('Registration submitted! A coordinator will approve your account.', 'centromex-delivery'),
            'driver_id' => $driver_id,
        ));
    }

    /**
     * AJAX: Driver login
     */
    public function ajax_driver_login() {
        check_ajax_referer('centromex-delivery-portal', 'nonce');

        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';

        if (!$phone) {
            wp_send_json_error(array('message' => 'Phone number required'), 400);
        }

        $driver = Centromex_Driver_Manager::get_by_phone($phone);
        if (!$driver) {
            wp_send_json_error(array('message' => 'Phone number not found. Please register first.'), 404);
        }

        if ($driver->status === 'pending') {
            wp_send_json_error(array('message' => 'Your account is pending approval.'), 403);
        }

        if ($driver->status === 'inactive') {
            wp_send_json_error(array('message' => 'Your account is inactive. Contact a coordinator.'), 403);
        }

        wp_send_json_success(array(
            'driver_id' => $driver->id,
            'name' => $driver->name,
        ));
    }
}

// Initialize
function centromex_delivery() {
    return Centromex_Delivery::instance();
}

add_action('plugins_loaded', 'centromex_delivery');
