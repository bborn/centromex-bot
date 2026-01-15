<?php
/**
 * Plugin Name: Centromex Grocery
 * Plugin URI: https://centromex.org
 * Description: Custom grocery ordering system for Centromex families with Spanish language support
 * Version: 1.0.0
 * Author: Centromex
 * Author URI: https://centromex.org
 * Text Domain: centromex-grocery
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CENTROMEX_GROCERY_VERSION', '1.0.0');
define('CENTROMEX_GROCERY_PATH', plugin_dir_path(__FILE__));
define('CENTROMEX_GROCERY_URL', plugin_dir_url(__FILE__));

/**
 * Main Centromex Grocery Class
 */
class Centromex_Grocery {

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
        // Load plugin textdomain
        add_action('plugins_loaded', array($this, 'load_textdomain'));

        // Enqueue styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));

        // Add custom order fields
        add_action('woocommerce_after_order_notes', array($this, 'add_delivery_fields'));
        add_action('woocommerce_checkout_process', array($this, 'validate_delivery_fields'));
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_delivery_fields'));

        // Display custom fields in admin
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_delivery_fields_admin'));

        // Add bilingual email notifications
        add_filter('woocommerce_email_subject_new_order', array($this, 'bilingual_email_subject'), 10, 2);

        // Customize checkout for simplicity
        add_filter('woocommerce_checkout_fields', array($this, 'simplify_checkout'));

        // Add welcome message
        add_action('woocommerce_before_shop_loop', array($this, 'add_welcome_message'));

        // Add admin menu for order management
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Add Stripe configuration notice
        add_action('admin_notices', array($this, 'stripe_config_notice'));
    }

    /**
     * Load plugin textdomain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'centromex-grocery',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }

    /**
     * Enqueue custom styles for mobile-friendly experience
     */
    public function enqueue_styles() {
        wp_enqueue_style(
            'centromex-grocery-style',
            CENTROMEX_GROCERY_URL . 'assets/css/style.css',
            array(),
            CENTROMEX_GROCERY_VERSION
        );
    }

    /**
     * Add delivery instruction fields to checkout
     */
    public function add_delivery_fields($checkout) {
        echo '<div id="centromex_delivery_fields">';
        echo '<h3>' . __('Instrucciones de Entrega / Delivery Instructions', 'centromex-grocery') . '</h3>';

        woocommerce_form_field('delivery_instructions', array(
            'type' => 'textarea',
            'class' => array('form-row-wide'),
            'label' => __('Instrucciones especiales para la entrega / Special delivery instructions', 'centromex-grocery'),
            'placeholder' => __('Ejemplo: Tocar el timbre 2 veces / Example: Ring doorbell 2 times', 'centromex-grocery'),
            'required' => false,
        ), $checkout->get_value('delivery_instructions'));

        woocommerce_form_field('preferred_delivery_time', array(
            'type' => 'select',
            'class' => array('form-row-wide'),
            'label' => __('Horario preferido de entrega / Preferred delivery time', 'centromex-grocery'),
            'required' => false,
            'options' => array(
                '' => __('Seleccione... / Select...', 'centromex-grocery'),
                'morning' => __('Mañana (9am-12pm) / Morning (9am-12pm)', 'centromex-grocery'),
                'afternoon' => __('Tarde (12pm-5pm) / Afternoon (12pm-5pm)', 'centromex-grocery'),
                'evening' => __('Noche (5pm-8pm) / Evening (5pm-8pm)', 'centromex-grocery'),
            ),
        ), $checkout->get_value('preferred_delivery_time'));

        woocommerce_form_field('contact_phone', array(
            'type' => 'tel',
            'class' => array('form-row-wide'),
            'label' => __('Teléfono de contacto / Contact phone', 'centromex-grocery'),
            'placeholder' => __('Para coordinar la entrega / For delivery coordination', 'centromex-grocery'),
            'required' => true,
        ), $checkout->get_value('contact_phone'));

        echo '</div>';
    }

    /**
     * Validate delivery fields
     */
    public function validate_delivery_fields() {
        if (empty($_POST['contact_phone'])) {
            wc_add_notice(
                __('Por favor ingrese un teléfono de contacto. / Please enter a contact phone number.', 'centromex-grocery'),
                'error'
            );
        }
    }

    /**
     * Save delivery fields to order meta
     */
    public function save_delivery_fields($order_id) {
        if (!empty($_POST['delivery_instructions'])) {
            update_post_meta($order_id, '_delivery_instructions', sanitize_textarea_field($_POST['delivery_instructions']));
        }
        if (!empty($_POST['preferred_delivery_time'])) {
            update_post_meta($order_id, '_preferred_delivery_time', sanitize_text_field($_POST['preferred_delivery_time']));
        }
        if (!empty($_POST['contact_phone'])) {
            update_post_meta($order_id, '_contact_phone', sanitize_text_field($_POST['contact_phone']));
        }
    }

    /**
     * Display delivery fields in admin order view
     */
    public function display_delivery_fields_admin($order) {
        $delivery_instructions = get_post_meta($order->get_id(), '_delivery_instructions', true);
        $preferred_time = get_post_meta($order->get_id(), '_preferred_delivery_time', true);
        $contact_phone = get_post_meta($order->get_id(), '_contact_phone', true);

        echo '<div class="centromex-delivery-info">';
        echo '<h3>' . __('Delivery Information', 'centromex-grocery') . '</h3>';

        if ($contact_phone) {
            echo '<p><strong>' . __('Contact Phone:', 'centromex-grocery') . '</strong> ' . esc_html($contact_phone) . '</p>';
        }

        if ($preferred_time) {
            $time_labels = array(
                'morning' => __('Morning (9am-12pm)', 'centromex-grocery'),
                'afternoon' => __('Afternoon (12pm-5pm)', 'centromex-grocery'),
                'evening' => __('Evening (5pm-8pm)', 'centromex-grocery'),
            );
            echo '<p><strong>' . __('Preferred Time:', 'centromex-grocery') . '</strong> ' . esc_html($time_labels[$preferred_time] ?? $preferred_time) . '</p>';
        }

        if ($delivery_instructions) {
            echo '<p><strong>' . __('Delivery Instructions:', 'centromex-grocery') . '</strong><br>' . esc_html($delivery_instructions) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Make email subjects bilingual
     */
    public function bilingual_email_subject($subject, $order) {
        return sprintf(
            __('Nuevo Pedido / New Order #%s', 'centromex-grocery'),
            $order->get_order_number()
        );
    }

    /**
     * Simplify checkout for easier use
     */
    public function simplify_checkout($fields) {
        // Make some fields optional for easier checkout
        $fields['billing']['billing_company']['required'] = false;
        $fields['billing']['billing_address_2']['required'] = false;

        // Add bilingual labels
        $fields['billing']['billing_first_name']['label'] = __('Nombre / First Name', 'centromex-grocery');
        $fields['billing']['billing_last_name']['label'] = __('Apellido / Last Name', 'centromex-grocery');
        $fields['billing']['billing_address_1']['label'] = __('Dirección / Address', 'centromex-grocery');
        $fields['billing']['billing_city']['label'] = __('Ciudad / City', 'centromex-grocery');
        $fields['billing']['billing_postcode']['label'] = __('Código Postal / Zip Code', 'centromex-grocery');
        $fields['billing']['billing_phone']['label'] = __('Teléfono / Phone', 'centromex-grocery');
        $fields['billing']['billing_email']['label'] = __('Correo Electrónico / Email', 'centromex-grocery');

        return $fields;
    }

    /**
     * Add welcome message to shop page
     */
    public function add_welcome_message() {
        if (is_shop()) {
            echo '<div class="centromex-welcome">';
            echo '<h2>' . __('Bienvenidos a la Tienda de Abarrotes Centromex', 'centromex-grocery') . '</h2>';
            echo '<p>' . __('Welcome to the Centromex Grocery Store', 'centromex-grocery') . '</p>';
            echo '<p class="centromex-description">' . __('Ordene sus compras en línea y nosotros se las llevaremos a su hogar.', 'centromex-grocery') . '</p>';
            echo '<p class="centromex-description-en">' . __('Order your groceries online and we will deliver them to your home.', 'centromex-grocery') . '</p>';
            echo '</div>';
        }
    }

    /**
     * Add admin menu for staff order management
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Centromex Orders', 'centromex-grocery'),
            __('Centromex Orders', 'centromex-grocery'),
            'manage_woocommerce',
            'centromex-orders',
            array($this, 'render_orders_page'),
            'dashicons-cart',
            56
        );
    }

    /**
     * Render custom orders management page
     */
    public function render_orders_page() {
        include CENTROMEX_GROCERY_PATH . 'templates/admin-orders.php';
    }

    /**
     * Show Stripe configuration notice
     */
    public function stripe_config_notice() {
        if (!is_plugin_active('woocommerce-gateway-stripe/woocommerce-gateway-stripe.php')) {
            return;
        }

        $stripe_settings = get_option('woocommerce_stripe_settings', array());

        if (empty($stripe_settings['publishable_key']) || empty($stripe_settings['secret_key'])) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>Centromex Grocery:</strong> ';
            echo __('Please configure your Stripe API keys to accept payments. Go to WooCommerce > Settings > Payments > Stripe.', 'centromex-grocery');
            echo '</p></div>';
        }
    }
}

// Initialize the plugin
function centromex_grocery() {
    return Centromex_Grocery::instance();
}

// Start the plugin
add_action('plugins_loaded', 'centromex_grocery');
