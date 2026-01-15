<?php
/**
 * UPCitemdb API Client
 * Searches UPC database for product information
 *
 * @package Centromex_Grocery
 */

if (!defined('ABSPATH')) {
    exit;
}

class Centromex_UPCitemdb_Client {

    private $api_key;
    private $trial_base = 'https://api.upcitemdb.com/prod/trial/search';
    private $prod_base = 'https://api.upcitemdb.com/prod/v1/search';

    public function __construct() {
        $this->api_key = defined('CENTROMEX_UPCITEMDB_API_KEY')
            ? CENTROMEX_UPCITEMDB_API_KEY
            : getenv('UPCITEMDB_API_KEY');
    }

    /**
     * Search UPCitemdb by product name
     *
     * @param string $brand Product brand
     * @param string $product_name Product name
     * @return array|null Product data or null if not found
     */
    public function search_product($brand, $product_name) {
        $query = trim("$brand $product_name");

        if (empty($query)) {
            return null;
        }

        $url = $this->api_key ? $this->prod_base : $this->trial_base;
        $url .= '?s=' . urlencode($query);

        $args = [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip,deflate'
            ],
            'sslverify' => false
        ];

        // Add API key headers if available
        if ($this->api_key) {
            $args['headers']['user_key'] = $this->api_key;
            $args['headers']['key_type'] = '3scale';
        }

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            error_log("Centromex: UPCitemdb error: " . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);

        // Handle rate limiting
        if ($code === 429) {
            error_log("Centromex: UPCitemdb rate limited");
            return null;
        }

        if ($code !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['items']) || empty($data['items'])) {
            return null;
        }

        // Find best match - prefer exact brand match
        $item = null;
        foreach ($data['items'] as $i) {
            if (isset($i['brand']) && stripos($i['brand'], $brand) !== false) {
                $item = $i;
                break;
            }
        }

        // Fall back to first result
        if (!$item) {
            $item = $data['items'][0];
        }

        // Extract price from offers or recorded prices
        $price = null;

        if (isset($item['offers']) && !empty($item['offers'])) {
            $prices = [];
            foreach ($item['offers'] as $offer) {
                if (isset($offer['price']) && $offer['price'] > 0) {
                    $prices[] = (float)$offer['price'];
                }
            }
            if (!empty($prices)) {
                $price = round(array_sum($prices) / count($prices), 2);
            }
        }

        // Fall back to lowest/highest recorded price
        if (!$price || $price <= 0) {
            $low = isset($item['lowest_recorded_price']) ? (float)$item['lowest_recorded_price'] : 0;
            $high = isset($item['highest_recorded_price']) ? (float)$item['highest_recorded_price'] : 0;

            if ($low > 0 && $high > 0) {
                $price = round(($low + $high) / 2, 2);
            } elseif ($low > 0) {
                $price = $low;
            } elseif ($high > 0) {
                $price = $high;
            }
        }

        return [
            'found' => true,
            'name' => isset($item['title']) ? $item['title'] : '',
            'brand' => isset($item['brand']) ? $item['brand'] : '',
            'category' => isset($item['category']) ? $item['category'] : '',
            'description' => isset($item['description']) ? $item['description'] : '',
            'upc' => isset($item['ean']) ? $item['ean'] : (isset($item['upc']) ? $item['upc'] : ''),
            'price' => $price
        ];
    }
}
