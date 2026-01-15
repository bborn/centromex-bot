<?php
/**
 * Product Creator
 * Creates WooCommerce products from detected product data
 *
 * @package Centromex_Grocery
 */

if (!defined('ABSPATH')) {
    exit;
}

class Centromex_Product_Creator {

    /**
     * Create WooCommerce product as draft
     *
     * @param array $product_data Product information
     * @param string $image_url URL to product image
     * @param string $source_hash Source image hash
     * @param string $source_filename Source image filename
     * @return int|WP_Error Product ID or error
     */
    public function create_product($product_data, $image_url, $source_hash, $source_filename) {
        // Check if product already exists by UPC
        if (!empty($product_data['upc'])) {
            $existing_id = $this->find_by_upc($product_data['upc']);
            if ($existing_id) {
                error_log("Centromex: Product with UPC {$product_data['upc']} already exists (ID: $existing_id)");
                return new WP_Error('duplicate_upc', 'Product with this UPC already exists', ['product_id' => $existing_id]);
            }
        }

        // Generate SKU
        $sku = $this->generate_sku($product_data['brand'], $product_data['product_name']);

        // Check SKU uniqueness
        if ($this->sku_exists($sku)) {
            $sku = $sku . '-' . substr(md5($source_hash), 0, 6);
        }

        // Create product
        $product = new WC_Product_Simple();

        // Basic info
        $product->set_name($product_data['full_name']);
        $product->set_sku($sku);
        $product->set_status('draft'); // Always draft for review
        $product->set_catalog_visibility('visible');

        // Price
        if (!empty($product_data['price']) && $product_data['price'] > 0) {
            $product->set_regular_price($product_data['price']);
        }

        // Description
        if (!empty($product_data['description'])) {
            $product->set_description($product_data['description']);
        }

        // Short description (size if available)
        if (!empty($product_data['size'])) {
            $product->set_short_description($product_data['size']);
        }

        // Categories
        if (!empty($product_data['categories'])) {
            $category_ids = $this->get_or_create_categories($product_data['categories']);
            $product->set_category_ids($category_ids);
        }

        // Save product
        $product_id = $product->save();

        if (!$product_id) {
            return new WP_Error('create_failed', 'Failed to create product');
        }

        // Add custom meta
        update_post_meta($product_id, '_centromex_status', $product_data['status']);
        update_post_meta($product_id, '_centromex_source_hash', $source_hash);
        update_post_meta($product_id, '_centromex_source_image', $source_filename);

        if (!empty($product_data['upc'])) {
            update_post_meta($product_id, '_centromex_upc', $product_data['upc']);
        }

        // Store detection data as JSON
        update_post_meta($product_id, '_centromex_detected_data', json_encode([
            'brand' => $product_data['brand'],
            'product_name' => $product_data['product_name'],
            'size' => isset($product_data['size']) ? $product_data['size'] : '',
            'off_validated' => isset($product_data['off_validated']) ? $product_data['off_validated'] : false,
            'off_code' => isset($product_data['off_code']) ? $product_data['off_code'] : '',
            'detected_at' => current_time('mysql')
        ]));

        // Set product image
        if (!empty($image_url)) {
            $this->set_product_image($product_id, $image_url, $sku);
        }

        error_log("Centromex: Created product #{$product_id} - {$product_data['full_name']} (SKU: $sku, Status: {$product_data['status']})");

        return $product_id;
    }

    /**
     * Generate SKU from brand and product name
     *
     * @param string $brand
     * @param string $product_name
     * @return string
     */
    private function generate_sku($brand, $product_name) {
        $sku = $brand . '-' . $product_name;
        $sku = strtolower($sku);
        $sku = preg_replace('/[^a-z0-9]+/', '-', $sku);
        $sku = preg_replace('/-+/', '-', $sku);
        $sku = trim($sku, '-');
        $sku = substr($sku, 0, 50);

        return $sku;
    }

    /**
     * Check if SKU already exists
     *
     * @param string $sku
     * @return bool
     */
    private function sku_exists($sku) {
        global $wpdb;

        $product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
            $sku
        ));

        return !is_null($product_id);
    }

    /**
     * Find product by UPC
     *
     * @param string $upc
     * @return int|null Product ID or null
     */
    public function find_by_upc($upc) {
        $args = [
            'post_type' => 'product',
            'posts_per_page' => 1,
            'post_status' => 'any',
            'meta_query' => [
                [
                    'key' => '_centromex_upc',
                    'value' => $upc,
                    'compare' => '='
                ]
            ]
        ];

        $products = get_posts($args);

        return !empty($products) ? $products[0]->ID : null;
    }

    /**
     * Get or create product categories
     *
     * @param string|array $categories_str Comma-separated categories or array of category names
     * @return array Category IDs
     */
    private function get_or_create_categories($categories_str) {
        if (empty($categories_str)) {
            return [];
        }

        // Handle both string and array input
        if (is_array($categories_str)) {
            $category_names = array_map('trim', $categories_str);
        } else {
            $category_names = array_map('trim', explode(',', $categories_str));
        }

        $category_ids = [];

        foreach ($category_names as $name) {
            if (empty($name)) {
                continue;
            }

            // Check if category exists
            $term = get_term_by('name', $name, 'product_cat');

            if ($term) {
                $category_ids[] = $term->term_id;
            } else {
                // Create new category
                $result = wp_insert_term($name, 'product_cat');
                if (!is_wp_error($result)) {
                    $category_ids[] = $result['term_id'];
                }
            }
        }

        return $category_ids;
    }

    /**
     * Set product featured image
     *
     * @param int $product_id
     * @param string $image_url
     * @param string $sku
     * @return int|false Attachment ID or false
     */
    private function set_product_image($product_id, $image_url, $sku) {
        // Check if it's a local file path
        if (strpos($image_url, 'http') !== 0) {
            // It's a file path, convert to URL
            $upload_dir = wp_upload_dir();
            $image_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $image_url);
        }

        // Download image to media library
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // For local files, just attach them
        if (strpos($image_url, $upload_dir['baseurl']) === 0) {
            $image_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $image_url);

            if (file_exists($image_path)) {
                $filetype = wp_check_filetype(basename($image_path), null);

                $attachment = [
                    'post_mime_type' => $filetype['type'],
                    'post_title' => sanitize_file_name($sku),
                    'post_content' => '',
                    'post_status' => 'inherit'
                ];

                $attach_id = wp_insert_attachment($attachment, $image_path, $product_id);

                if (!is_wp_error($attach_id)) {
                    $attach_data = wp_generate_attachment_metadata($attach_id, $image_path);
                    wp_update_attachment_metadata($attach_id, $attach_data);
                    set_post_thumbnail($product_id, $attach_id);

                    return $attach_id;
                }
            }
        }

        return false;
    }

    /**
     * Get statistics on created products
     *
     * @param string $batch_id
     * @return array Stats
     */
    public function get_batch_stats($batch_id = null) {
        $args = [
            'post_type' => 'product',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ];

        if ($batch_id) {
            $args['meta_query'] = [
                [
                    'key' => '_centromex_batch_id',
                    'value' => $batch_id,
                    'compare' => '='
                ]
            ];
        }

        $product_ids = get_posts($args);
        $total = count($product_ids);

        $verified = 0;
        $needs_review = 0;

        foreach ($product_ids as $product_id) {
            $status = get_post_meta($product_id, '_centromex_status', true);
            if ($status === 'verified') {
                $verified++;
            } else {
                $needs_review++;
            }
        }

        return [
            'total' => $total,
            'verified' => $verified,
            'needs_review' => $needs_review
        ];
    }
}
