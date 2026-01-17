<?php
/**
 * Translation Service
 * Handles product name translations with caching
 *
 * @package Centromex_Volunteer_Picker
 */

if (!defined('ABSPATH')) {
    exit;
}

class Centromex_Translation_Service {

    private $api_key;

    public function __construct() {
        $this->api_key = get_option('centromex_picker_openai_key', '');

        // Fall back to the main Centromex OpenAI key if available
        if (empty($this->api_key)) {
            $this->api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
        }
    }

    /**
     * Get translation for a product, with caching
     *
     * @param int $product_id
     * @param string $original_name
     * @return string|null Translated name or null if same language
     */
    public function get_translation($product_id, $original_name) {
        global $wpdb;

        $table = $wpdb->prefix . 'centromex_product_translations';

        // Check cache first
        $cached = $wpdb->get_var($wpdb->prepare(
            "SELECT translated_name FROM $table WHERE product_id = %d",
            $product_id
        ));

        if ($cached !== null) {
            return $cached ?: null;
        }

        // Detect if translation is needed
        if ($this->is_english($original_name)) {
            // Translate to Spanish
            $translated = $this->translate($original_name, 'es');
            $source_lang = 'en';
            $target_lang = 'es';
        } else {
            // Assume Spanish, translate to English
            $translated = $this->translate($original_name, 'en');
            $source_lang = 'es';
            $target_lang = 'en';
        }

        // Cache the result
        $wpdb->replace($table, array(
            'product_id' => $product_id,
            'original_name' => $original_name,
            'translated_name' => $translated,
            'source_lang' => $source_lang,
            'target_lang' => $target_lang,
            'created_at' => current_time('mysql'),
        ));

        return $translated;
    }

    /**
     * Detect if text is primarily English
     */
    private function is_english($text) {
        // Simple heuristic: check for Spanish-specific characters and common words
        $spanish_indicators = array(
            'ñ', 'á', 'é', 'í', 'ó', 'ú', 'ü', '¿', '¡',
            ' de ', ' la ', ' el ', ' los ', ' las ', ' con ', ' para ', ' en ',
            ' y ', ' del ', ' una ', ' uno ', ' es ', ' son ',
        );

        $text_lower = strtolower($text);

        foreach ($spanish_indicators as $indicator) {
            if (strpos($text_lower, $indicator) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Translate text using OpenAI
     *
     * @param string $text
     * @param string $target_lang 'en' or 'es'
     * @return string|null
     */
    public function translate($text, $target_lang = 'en') {
        if (empty($this->api_key)) {
            // No API key, return null (will show original only)
            return null;
        }

        $target_language = $target_lang === 'es' ? 'Spanish' : 'English';

        $prompt = "Translate this grocery product name to {$target_language}. Return ONLY the translated name, nothing else. If it's already in {$target_language}, return it unchanged.\n\nProduct: {$text}";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => 'You are a translator for a grocery store. Translate product names accurately and concisely. Return only the translation.',
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt,
                    ),
                ),
                'max_tokens' => 100,
                'temperature' => 0.1,
            )),
        ));

        if (is_wp_error($response)) {
            error_log('Centromex Picker Translation Error: ' . $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['choices'][0]['message']['content'])) {
            return null;
        }

        return trim($body['choices'][0]['message']['content']);
    }

    /**
     * Batch translate multiple product names
     *
     * @param array $products Array of ['product_id' => id, 'name' => name]
     * @return array Translations keyed by product_id
     */
    public function batch_translate($products) {
        if (empty($this->api_key) || empty($products)) {
            return array();
        }

        // Build the prompt
        $items = array();
        foreach ($products as $p) {
            $items[] = $p['product_id'] . ': ' . $p['name'];
        }

        $prompt = "Translate these grocery product names. For each, if it's in Spanish translate to English, if in English translate to Spanish. Return JSON with product_id as key and translated name as value.\n\nProducts:\n" . implode("\n", $items);

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => 'You are a translator for a grocery store. Return valid JSON only.',
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt,
                    ),
                ),
                'max_tokens' => 1000,
                'temperature' => 0.1,
            )),
        ));

        if (is_wp_error($response)) {
            return array();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['choices'][0]['message']['content'])) {
            return array();
        }

        $content = $body['choices'][0]['message']['content'];

        // Extract JSON from response
        if (preg_match('/\{[^{}]*\}/', $content, $matches)) {
            $translations = json_decode($matches[0], true);
            if (is_array($translations)) {
                return $translations;
            }
        }

        return array();
    }

    /**
     * Clear translation cache for a product
     */
    public function clear_cache($product_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_product_translations';
        $wpdb->delete($table, array('product_id' => $product_id));
    }

    /**
     * Clear all translation cache
     */
    public function clear_all_cache() {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_product_translations';
        $wpdb->query("TRUNCATE TABLE $table");
    }
}
