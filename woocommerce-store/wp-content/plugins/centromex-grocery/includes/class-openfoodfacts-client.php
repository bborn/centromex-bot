<?php
/**
 * Open Food Facts API Client
 * Validates products against the Open Food Facts database
 *
 * @package Centromex_Grocery
 */

if (!defined('ABSPATH')) {
    exit;
}

class Centromex_OpenFoodFacts_Client {

    private $api_base = 'https://world.openfoodfacts.org';

    /**
     * Search Open Food Facts for a product
     *
     * @param string $query Search query (brand + product name)
     * @param int $page_size Number of results to return
     * @return array|null First matching product or null
     */
    public function search($query, $page_size = 5) {
        if (empty($query)) {
            return null;
        }

        $url = $this->api_base . '/cgi/search.pl?' . http_build_query([
            'search_terms' => $query,
            'json' => 1,
            'page_size' => $page_size
        ]);

        $response = wp_remote_get($url, [
            'headers' => [
                'User-Agent' => 'Centromex-Grocery-Plugin/1.0'
            ],
            'timeout' => 10,
            'sslverify' => false // OFF cert issues sometimes
        ]);

        if (is_wp_error($response)) {
            error_log('Centromex: Open Food Facts error: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['products']) || empty($data['products'])) {
            return null;
        }

        // Return first result
        return $data['products'][0];
    }

    /**
     * Validate product against Open Food Facts using LLM
     *
     * @param string $brand Detected brand
     * @param string $product_name Detected product name
     * @param Centromex_Replicate_Client $llm_client LLM client for validation
     * @return array Validation result with OFF data
     */
    public function validate_product($brand, $product_name, $llm_client) {
        // Try full query first
        $query = trim("$brand $product_name");
        $result = $this->search($query);

        if ($result) {
            $off_brand = isset($result['brands']) ? $result['brands'] : '';
            $off_name = isset($result['product_name']) ? $result['product_name'] : (isset($result['product_name_en']) ? $result['product_name_en'] : '');

            error_log("Centromex: Checking OFF match - '$off_brand' / '$off_name'");

            // Use LLM to validate if it's a real match
            $validation = $llm_client->validate_match($brand, $product_name, $off_brand, $off_name);

            if (isset($validation['is_match']) && $validation['is_match']) {
                error_log("Centromex: OFF MATCH - " . $validation['reason']);

                return [
                    'found' => true,
                    'off_name' => $off_name,
                    'off_brand' => $off_brand,
                    'off_categories' => isset($result['categories']) ? $result['categories'] : '',
                    'off_code' => isset($result['code']) ? $result['code'] : '',
                    'off_image' => isset($result['image_url']) ? $result['image_url'] : ''
                ];
            }

            error_log("Centromex: NO MATCH - " . $validation['reason']);
        }

        // Rate limit
        sleep(1);

        // Try brand-only search as fallback
        $result = $this->search($brand);

        if ($result) {
            $off_brand = isset($result['brands']) ? $result['brands'] : '';
            $off_name = isset($result['product_name']) ? $result['product_name'] : (isset($result['product_name_en']) ? $result['product_name_en'] : '');

            error_log("Centromex: Checking OFF match (brand only) - '$off_brand' / '$off_name'");

            $validation = $llm_client->validate_match($brand, $product_name, $off_brand, $off_name);

            if (isset($validation['is_match']) && $validation['is_match']) {
                error_log("Centromex: OFF MATCH - " . $validation['reason']);

                return [
                    'found' => true,
                    'off_name' => $off_name,
                    'off_brand' => $off_brand,
                    'off_categories' => isset($result['categories']) ? $result['categories'] : '',
                    'off_code' => isset($result['code']) ? $result['code'] : '',
                    'off_image' => isset($result['image_url']) ? $result['image_url'] : ''
                ];
            }
        }

        return ['found' => false];
    }

    /**
     * Get product by barcode/UPC
     *
     * @param string $code UPC/EAN code
     * @return array|null Product data or null
     */
    public function get_by_code($code) {
        if (empty($code)) {
            return null;
        }

        $url = $this->api_base . '/api/v0/product/' . $code . '.json';

        $response = wp_remote_get($url, [
            'headers' => [
                'User-Agent' => 'Centromex-Grocery-Plugin/1.0'
            ],
            'timeout' => 10,
            'sslverify' => false
        ]);

        if (is_wp_error($response)) {
            error_log('Centromex: Open Food Facts error: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['product']) || $data['status'] != 1) {
            return null;
        }

        return $data['product'];
    }
}
