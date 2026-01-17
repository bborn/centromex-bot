<?php
/**
 * REST API for Volunteer Picker
 *
 * @package Centromex_Volunteer_Picker
 */

if (!defined('ABSPATH')) {
    exit;
}

class Centromex_Picker_API {

    private $namespace = 'centromex-picker/v1';
    private $translation_service;

    public function __construct() {
        $this->translation_service = new Centromex_Translation_Service();
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Session management
        register_rest_route($this->namespace, '/session/start', array(
            'methods' => 'POST',
            'callback' => array($this, 'start_session'),
            'permission_callback' => array($this, 'check_access_code'),
        ));

        register_rest_route($this->namespace, '/session/validate', array(
            'methods' => 'POST',
            'callback' => array($this, 'validate_session'),
            'permission_callback' => '__return_true',
        ));

        // Orders
        register_rest_route($this->namespace, '/orders', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_orders'),
            'permission_callback' => array($this, 'check_session'),
        ));

        register_rest_route($this->namespace, '/orders/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_order'),
            'permission_callback' => array($this, 'check_session'),
        ));

        register_rest_route($this->namespace, '/orders/(?P<id>\d+)/claim', array(
            'methods' => 'POST',
            'callback' => array($this, 'claim_order'),
            'permission_callback' => array($this, 'check_session'),
        ));

        register_rest_route($this->namespace, '/orders/(?P<id>\d+)/complete', array(
            'methods' => 'POST',
            'callback' => array($this, 'complete_order'),
            'permission_callback' => array($this, 'check_session'),
        ));

        // Pick items
        register_rest_route($this->namespace, '/pick/(?P<pick_id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_pick'),
            'permission_callback' => array($this, 'check_session'),
        ));

        register_rest_route($this->namespace, '/pick/(?P<pick_id>\d+)/photo', array(
            'methods' => 'POST',
            'callback' => array($this, 'upload_pick_photo'),
            'permission_callback' => array($this, 'check_session'),
        ));

        // Barcode lookup
        register_rest_route($this->namespace, '/barcode/(?P<code>[a-zA-Z0-9]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'lookup_barcode'),
            'permission_callback' => array($this, 'check_session'),
        ));

        // Translate
        register_rest_route($this->namespace, '/translate', array(
            'methods' => 'POST',
            'callback' => array($this, 'translate_text'),
            'permission_callback' => array($this, 'check_session'),
        ));
    }

    /**
     * Check access code permission
     */
    public function check_access_code($request) {
        $access_code = get_option('centromex_picker_access_code', '');

        // If no access code is set, allow access
        if (empty($access_code)) {
            return true;
        }

        $provided_code = $request->get_param('access_code');
        return $provided_code === $access_code;
    }

    /**
     * Check session permission
     */
    public function check_session($request) {
        $token = $request->get_header('X-Picker-Session');

        if (empty($token)) {
            return new WP_Error('no_session', 'Session token required', array('status' => 401));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'centromex_picker_sessions';

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE session_token = %s AND completed_at IS NULL",
            $token
        ));

        if (!$session) {
            return new WP_Error('invalid_session', 'Invalid or expired session', array('status' => 401));
        }

        // Update last activity
        $wpdb->update(
            $table,
            array('last_activity' => current_time('mysql')),
            array('session_token' => $token)
        );

        // Store session in request for later use
        $request->set_param('_session', $session);

        return true;
    }

    /**
     * Start a new picking session
     */
    public function start_session($request) {
        global $wpdb;

        $volunteer_name = sanitize_text_field($request->get_param('volunteer_name'));
        $volunteer_phone = sanitize_text_field($request->get_param('volunteer_phone'));

        if (empty($volunteer_name)) {
            return new WP_Error('missing_name', 'Volunteer name is required', array('status' => 400));
        }

        // Generate session token
        $token = bin2hex(random_bytes(32));

        $table = $wpdb->prefix . 'centromex_picker_sessions';

        $wpdb->insert($table, array(
            'session_token' => $token,
            'volunteer_name' => $volunteer_name,
            'volunteer_phone' => $volunteer_phone,
            'started_at' => current_time('mysql'),
            'last_activity' => current_time('mysql'),
        ));

        return rest_ensure_response(array(
            'success' => true,
            'session_token' => $token,
            'volunteer_name' => $volunteer_name,
        ));
    }

    /**
     * Validate existing session
     */
    public function validate_session($request) {
        global $wpdb;

        $token = $request->get_param('session_token');

        if (empty($token)) {
            return rest_ensure_response(array('valid' => false));
        }

        $table = $wpdb->prefix . 'centromex_picker_sessions';

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE session_token = %s AND completed_at IS NULL",
            $token
        ));

        if (!$session) {
            return rest_ensure_response(array('valid' => false));
        }

        return rest_ensure_response(array(
            'valid' => true,
            'volunteer_name' => $session->volunteer_name,
            'order_id' => $session->order_id,
        ));
    }

    /**
     * Get orders ready for picking
     */
    public function get_orders($request) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_missing', 'WooCommerce is required', array('status' => 500));
        }

        // Get orders that are processing (paid, ready to pick)
        $args = array(
            'status' => array('processing', 'on-hold'),
            'limit' => 50,
            'orderby' => 'date',
            'order' => 'ASC',
        );

        $orders = wc_get_orders($args);
        $result = array();

        foreach ($orders as $order) {
            $pick_status = $this->get_order_pick_status($order->get_id());

            $result[] = array(
                'id' => $order->get_id(),
                'number' => $order->get_order_number(),
                'date' => $order->get_date_created()->format('Y-m-d H:i'),
                'status' => $order->get_status(),
                'total' => $order->get_total(),
                'item_count' => $order->get_item_count(),
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'pick_status' => $pick_status,
                'claimed_by' => $this->get_order_claimer($order->get_id()),
            );
        }

        return rest_ensure_response($result);
    }

    /**
     * Get single order with pick list
     */
    public function get_order($request) {
        $order_id = intval($request->get_param('id'));
        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found', array('status' => 404));
        }

        // Initialize pick records if not exist
        $this->initialize_picks($order);

        // Get pick list with translations
        $picks = $this->get_order_picks($order_id);

        // Get delivery info
        $delivery_time = get_post_meta($order_id, '_preferred_delivery_time', true);
        $delivery_instructions = get_post_meta($order_id, '_delivery_instructions', true);
        $contact_phone = get_post_meta($order_id, '_contact_phone', true);

        $time_labels = array(
            'morning' => 'Mañana (9am-12pm) / Morning',
            'afternoon' => 'Tarde (12pm-5pm) / Afternoon',
            'evening' => 'Noche (5pm-8pm) / Evening',
        );

        return rest_ensure_response(array(
            'id' => $order->get_id(),
            'number' => $order->get_order_number(),
            'date' => $order->get_date_created()->format('Y-m-d H:i'),
            'status' => $order->get_status(),
            'total' => $order->get_total(),
            'customer' => array(
                'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'phone' => $contact_phone ?: $order->get_billing_phone(),
                'address' => $order->get_formatted_billing_address(),
            ),
            'delivery' => array(
                'time' => $delivery_time ? ($time_labels[$delivery_time] ?? $delivery_time) : null,
                'instructions' => $delivery_instructions,
            ),
            'items' => $picks,
            'pick_status' => $this->get_order_pick_status($order_id),
            'claimed_by' => $this->get_order_claimer($order_id),
        ));
    }

    /**
     * Initialize pick records for an order
     */
    private function initialize_picks($order) {
        global $wpdb;

        $table = $wpdb->prefix . 'centromex_order_picks';
        $order_id = $order->get_id();

        // Check if picks already initialized
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE order_id = %d",
            $order_id
        ));

        if ($existing > 0) {
            return;
        }

        // Create pick records for each item
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();

            if (!$product) {
                continue;
            }

            $wpdb->insert($table, array(
                'order_id' => $order_id,
                'order_item_id' => $item_id,
                'product_id' => $product->get_id(),
                'quantity_ordered' => $item->get_quantity(),
                'quantity_picked' => 0,
                'status' => 'pending',
                'created_at' => current_time('mysql'),
            ));
        }
    }

    /**
     * Get picks for an order with product details and translations
     */
    private function get_order_picks($order_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'centromex_order_picks';

        $picks = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d ORDER BY id ASC",
            $order_id
        ));

        $result = array();

        foreach ($picks as $pick) {
            $product = wc_get_product($pick->product_id);

            if (!$product) {
                continue;
            }

            $product_name = $product->get_name();
            $sku = $product->get_sku();
            $upc = get_post_meta($pick->product_id, '_centromex_upc', true);

            // Get translation
            $translation = $this->translation_service->get_translation($pick->product_id, $product_name);

            $result[] = array(
                'pick_id' => intval($pick->id),
                'product_id' => intval($pick->product_id),
                'order_item_id' => intval($pick->order_item_id),
                'name' => $product_name,
                'name_translated' => $translation,
                'sku' => $sku,
                'upc' => $upc,
                'image' => wp_get_attachment_url($product->get_image_id()),
                'quantity_ordered' => intval($pick->quantity_ordered),
                'quantity_picked' => intval($pick->quantity_picked),
                'status' => $pick->status,
                'scanned_barcode' => $pick->scanned_barcode,
                'photo_url' => $pick->photo_url,
                'notes' => $pick->notes,
                'picked_at' => $pick->picked_at,
            );
        }

        return $result;
    }

    /**
     * Get order pick status summary
     */
    private function get_order_pick_status($order_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'centromex_order_picks';

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'picked' THEN 1 ELSE 0 END) as picked,
                SUM(CASE WHEN status = 'substituted' THEN 1 ELSE 0 END) as substituted,
                SUM(CASE WHEN status = 'unavailable' THEN 1 ELSE 0 END) as unavailable,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            FROM $table WHERE order_id = %d",
            $order_id
        ));

        if (!$stats || $stats->total == 0) {
            return array(
                'total' => 0,
                'picked' => 0,
                'pending' => 0,
                'complete' => false,
                'progress' => 0,
            );
        }

        $completed = $stats->picked + $stats->substituted + $stats->unavailable;

        return array(
            'total' => intval($stats->total),
            'picked' => intval($stats->picked),
            'substituted' => intval($stats->substituted),
            'unavailable' => intval($stats->unavailable),
            'pending' => intval($stats->pending),
            'complete' => $completed == $stats->total,
            'progress' => round(($completed / $stats->total) * 100),
        );
    }

    /**
     * Get who claimed the order
     */
    private function get_order_claimer($order_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'centromex_picker_sessions';

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT volunteer_name FROM $table WHERE order_id = %d AND completed_at IS NULL ORDER BY started_at DESC LIMIT 1",
            $order_id
        ));

        return $session ? $session->volunteer_name : null;
    }

    /**
     * Claim an order for picking
     */
    public function claim_order($request) {
        global $wpdb;

        $order_id = intval($request->get_param('id'));
        $session = $request->get_param('_session');

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found', array('status' => 404));
        }

        // Check if already claimed by someone else
        $current_claimer = $this->get_order_claimer($order_id);
        if ($current_claimer && $current_claimer !== $session->volunteer_name) {
            return new WP_Error('already_claimed', 'Order already claimed by ' . $current_claimer, array('status' => 409));
        }

        // Update session with order
        $table = $wpdb->prefix . 'centromex_picker_sessions';
        $wpdb->update(
            $table,
            array('order_id' => $order_id),
            array('session_token' => $session->session_token)
        );

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Order claimed successfully',
        ));
    }

    /**
     * Mark order as complete
     */
    public function complete_order($request) {
        global $wpdb;

        $order_id = intval($request->get_param('id'));
        $session = $request->get_param('_session');

        // Check pick status
        $pick_status = $this->get_order_pick_status($order_id);

        if (!$pick_status['complete']) {
            return new WP_Error('incomplete', 'Not all items have been picked', array('status' => 400));
        }

        // Update session as completed
        $table = $wpdb->prefix . 'centromex_picker_sessions';
        $wpdb->update(
            $table,
            array('completed_at' => current_time('mysql')),
            array('session_token' => $session->session_token)
        );

        // Add order note
        $order = wc_get_order($order_id);
        if ($order) {
            $order->add_order_note(sprintf(
                'Order picked by volunteer: %s. %d items picked, %d substituted, %d unavailable.',
                $session->volunteer_name,
                $pick_status['picked'],
                $pick_status['substituted'],
                $pick_status['unavailable']
            ));

            // Optionally update order status
            // $order->update_status('ready-for-delivery', 'Picking complete');
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Order marked as picked',
        ));
    }

    /**
     * Update a pick item
     */
    public function update_pick($request) {
        global $wpdb;

        $pick_id = intval($request->get_param('pick_id'));
        $session = $request->get_param('_session');

        $table = $wpdb->prefix . 'centromex_order_picks';

        // Verify pick exists
        $pick = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $pick_id
        ));

        if (!$pick) {
            return new WP_Error('pick_not_found', 'Pick item not found', array('status' => 404));
        }

        // Build update data
        $update = array(
            'volunteer_id' => $session->id,
            'volunteer_name' => $session->volunteer_name,
            'updated_at' => current_time('mysql'),
        );

        $status = $request->get_param('status');
        if ($status && in_array($status, array('pending', 'picked', 'substituted', 'unavailable'))) {
            $update['status'] = $status;

            if ($status !== 'pending') {
                $update['picked_at'] = current_time('mysql');
            }
        }

        $quantity = $request->get_param('quantity_picked');
        if ($quantity !== null) {
            $update['quantity_picked'] = intval($quantity);
        }

        $barcode = $request->get_param('scanned_barcode');
        if ($barcode) {
            $update['scanned_barcode'] = sanitize_text_field($barcode);
        }

        $notes = $request->get_param('notes');
        if ($notes !== null) {
            $update['notes'] = sanitize_textarea_field($notes);
        }

        $wpdb->update($table, $update, array('id' => $pick_id));

        // Return updated pick
        $updated = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $pick_id
        ));

        return rest_ensure_response(array(
            'success' => true,
            'pick' => array(
                'pick_id' => intval($updated->id),
                'status' => $updated->status,
                'quantity_picked' => intval($updated->quantity_picked),
                'scanned_barcode' => $updated->scanned_barcode,
                'photo_url' => $updated->photo_url,
                'notes' => $updated->notes,
                'picked_at' => $updated->picked_at,
            ),
        ));
    }

    /**
     * Upload photo for a pick
     */
    public function upload_pick_photo($request) {
        global $wpdb;

        $pick_id = intval($request->get_param('pick_id'));

        $table = $wpdb->prefix . 'centromex_order_picks';

        // Verify pick exists
        $pick = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $pick_id
        ));

        if (!$pick) {
            return new WP_Error('pick_not_found', 'Pick item not found', array('status' => 404));
        }

        // Get the image data (base64)
        $image_data = $request->get_param('image');

        if (empty($image_data)) {
            return new WP_Error('no_image', 'No image provided', array('status' => 400));
        }

        // Decode base64
        $image_parts = explode(',', $image_data);
        $image_base64 = count($image_parts) > 1 ? $image_parts[1] : $image_parts[0];
        $image_decoded = base64_decode($image_base64);

        if (!$image_decoded) {
            return new WP_Error('invalid_image', 'Invalid image data', array('status' => 400));
        }

        // Save to uploads
        $upload_dir = wp_upload_dir();
        $pick_photos_dir = $upload_dir['basedir'] . '/pick-photos/' . date('Y/m');

        if (!file_exists($pick_photos_dir)) {
            wp_mkdir_p($pick_photos_dir);
        }

        $filename = 'pick-' . $pick_id . '-' . time() . '.jpg';
        $filepath = $pick_photos_dir . '/' . $filename;

        file_put_contents($filepath, $image_decoded);

        // Get URL
        $photo_url = $upload_dir['baseurl'] . '/pick-photos/' . date('Y/m') . '/' . $filename;

        // Update pick record
        $wpdb->update(
            $table,
            array('photo_url' => $photo_url),
            array('id' => $pick_id)
        );

        return rest_ensure_response(array(
            'success' => true,
            'photo_url' => $photo_url,
        ));
    }

    /**
     * Lookup barcode and match to product
     */
    public function lookup_barcode($request) {
        $code = sanitize_text_field($request->get_param('code'));

        if (empty($code)) {
            return new WP_Error('missing_code', 'Barcode is required', array('status' => 400));
        }

        // First check our products by UPC
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 1,
            'post_status' => 'any',
            'meta_query' => array(
                array(
                    'key' => '_centromex_upc',
                    'value' => $code,
                    'compare' => '=',
                ),
            ),
        );

        $products = get_posts($args);

        if (!empty($products)) {
            $product = wc_get_product($products[0]->ID);
            return rest_ensure_response(array(
                'found' => true,
                'source' => 'inventory',
                'product' => array(
                    'id' => $product->get_id(),
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku(),
                    'upc' => $code,
                    'image' => wp_get_attachment_url($product->get_image_id()),
                ),
            ));
        }

        // Try Open Food Facts
        $off_result = $this->lookup_open_food_facts($code);
        if ($off_result) {
            return rest_ensure_response(array(
                'found' => true,
                'source' => 'openfoodfacts',
                'product' => $off_result,
            ));
        }

        return rest_ensure_response(array(
            'found' => false,
            'code' => $code,
        ));
    }

    /**
     * Lookup product in Open Food Facts
     */
    private function lookup_open_food_facts($barcode) {
        $url = "https://world.openfoodfacts.org/api/v2/product/{$barcode}.json";

        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'CentromexVolunteerPicker/1.0',
            ),
        ));

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body) || $body['status'] !== 1) {
            return null;
        }

        $product = $body['product'];

        return array(
            'name' => $product['product_name'] ?? $product['product_name_en'] ?? 'Unknown',
            'brand' => $product['brands'] ?? '',
            'upc' => $barcode,
            'image' => $product['image_url'] ?? $product['image_front_url'] ?? null,
        );
    }

    /**
     * Translate text
     */
    public function translate_text($request) {
        $text = $request->get_param('text');
        $target_lang = $request->get_param('target_lang') ?: 'en';

        if (empty($text)) {
            return new WP_Error('missing_text', 'Text is required', array('status' => 400));
        }

        $translated = $this->translation_service->translate($text, $target_lang);

        return rest_ensure_response(array(
            'original' => $text,
            'translated' => $translated,
            'target_lang' => $target_lang,
        ));
    }
}
