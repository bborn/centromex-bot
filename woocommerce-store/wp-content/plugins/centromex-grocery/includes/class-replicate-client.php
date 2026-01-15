<?php
/**
 * Replicate API Client
 * Handles all ML operations: DINO detection, LLM identification, image upscaling
 *
 * @package Centromex_Grocery
 */

if (!defined('ABSPATH')) {
    exit;
}

class Centromex_Replicate_Client {

    private $api_token;
    private $api_base = 'https://api.replicate.com/v1';

    public function __construct() {
        $this->api_token = defined('CENTROMEX_REPLICATE_API_TOKEN')
            ? CENTROMEX_REPLICATE_API_TOKEN
            : getenv('REPLICATE_API_TOKEN');

        if (empty($this->api_token)) {
            error_log('Centromex: REPLICATE_API_TOKEN not configured');
        }
    }

    /**
     * Detect products in an image using Grounding DINO
     *
     * @param string $image_path Path to image file
     * @return array Array of detections with bbox, confidence, label
     */
    public function detect_products($image_path) {
        if (!file_exists($image_path)) {
            throw new Exception("Image file not found: $image_path");
        }

        $base64_image = $this->encode_image($image_path);

        // Multiple queries to catch different product types
        $queries = [
            "bottle . jar . carton . can . box . package",
            "tomato . lime . pepper . avocado . onion . lettuce . cucumber . chili",
            "chicken . meat . sausage . chorizo",
            "bread . tortilla . pan dulce . pastry",
            "yogurt . cream . cheese . milk"
        ];

        $all_detections = [];

        foreach ($queries as $index => $query) {
            error_log("Centromex: DINO query " . ($index + 1) . "/" . count($queries) . ": $query");

            $detections = $this->run_dino_query($base64_image, $query);
            error_log("Centromex: Found " . count($detections) . " detections");

            $all_detections = array_merge($all_detections, $detections);
        }

        // Remove duplicate/overlapping boxes
        $unique_detections = $this->remove_duplicate_boxes($all_detections);

        error_log("Centromex: Total unique detections: " . count($unique_detections));

        return $unique_detections;
    }

    /**
     * Run a single DINO query
     *
     * @param string $base64_image Base64 encoded image
     * @param string $query Detection query
     * @param float $box_thresh Box confidence threshold
     * @param float $text_thresh Text confidence threshold
     * @return array Detections
     */
    private function run_dino_query($base64_image, $query, $box_thresh = 0.15, $text_thresh = 0.15) {
        $prediction = $this->create_prediction('efd10a8ddc57ea28773327e881ce95e20cc1d734c589f7dd01d2036921ed78aa', [
            'image' => $base64_image,
            'query' => $query,
            'box_threshold' => $box_thresh,
            'text_threshold' => $text_thresh,
            'show_visualisation' => false
        ]);

        $prediction = $this->wait_for_prediction($prediction['id']);

        if ($prediction['status'] !== 'succeeded') {
            error_log("Centromex: DINO prediction failed: " . print_r($prediction, true));
            return [];
        }

        return isset($prediction['output']['detections']) ? $prediction['output']['detections'] : [];
    }

    /**
     * Identify product using LLM with JSON mode
     *
     * @param string $image_path Path to cropped product image
     * @return array Product identification data
     */
    public function identify_product($image_path) {
        if (!file_exists($image_path)) {
            throw new Exception("Image file not found: $image_path");
        }

        $base64_image = $this->encode_image($image_path);

        $prompt = <<<PROMPT
Look at this product image carefully. Your job is to READ THE LABEL and extract product information.

CRITICAL: You must identify:
1. BRAND NAME - exactly as shown on the packaging
2. PRODUCT NAME - the specific product
3. SIZE - weight or volume if visible (e.g., "16 oz", "450ml")
4. ESTIMATED PRICE - estimate a reasonable US retail price
5. CATEGORY - assign one category from the list below

CATEGORIES (choose ONE that best fits):
- Snacks & Chips
- Beverages
- Dairy & Eggs
- Meat & Seafood
- Bakery & Bread
- Pantry & Canned Goods
- Condiments & Sauces
- Frozen Foods
- Fresh Produce
- Breakfast & Cereal
- International Foods
- Candy & Sweets
- Health & Personal Care

Examples:
- Brand: "Goya", Product: "Mango Nectar", Size: "9.6 oz", Category: "Beverages", Price: 1.49
- Brand: "El Mexicano", Product: "Crema Mexicana", Size: "15 oz", Category: "Dairy & Eggs", Price: 4.99
- Brand: "Cheetos", Product: "Puffs", Size: "8 oz", Category: "Snacks & Chips", Price: 3.99

PRICING GUIDELINES (USD):
- Small juice/nectar bottles (8-12 oz): $1.00 - $2.00
- Large juice bottles (32+ oz): $3.00 - $5.00
- Yogurt drinks (individual): $1.50 - $3.00
- Cream/Crema (small): $3.00 - $5.00
- Cream/Crema (large): $5.00 - $8.00
- Cheese: $4.00 - $8.00
- Meat/Chorizo: $5.00 - $10.00
- Snacks (8-12 oz): $3.00 - $5.00

For fresh produce: Brand="Fresh", product_type="produce", category="Fresh Produce"

RULES:
- If you cannot clearly read a brand name, set is_product=false
- Estimate price based on product type, size, and brand positioning
- Always assign a category from the list above

Return ONLY the JSON object, no markdown formatting:
{
  "brand": "brand name",
  "product_name": "product name",
  "full_name": "brand + product name",
  "is_product": true or false,
  "product_type": "packaged" | "produce" | "meat" | "bakery",
  "category": "category from list above",
  "size": "size string",
  "estimated_price_usd": 0.00
}
PROMPT;

        $prediction = $this->create_prediction('bfb7df9586ae4fafa00a593d8dc4868698f72cf9d695da28b8c8a70f88e876ba', [
            'prompt' => $prompt,
            'images' => [$base64_image],
            'max_output_tokens' => 500,
            'temperature' => 0.1
        ]);

        $prediction = $this->wait_for_prediction($prediction['id']);

        if ($prediction['status'] !== 'succeeded') {
            error_log("Centromex: LLM identification failed");
            return ['is_product' => false];
        }

        // Parse JSON from output - Gemini returns array of text parts
        if (is_array($prediction['output'])) {
            // Join all text parts
            $output = '';
            foreach ($prediction['output'] as $part) {
                if (is_string($part)) {
                    $output .= $part;
                } elseif (isset($part['text'])) {
                    $output .= $part['text'];
                }
            }
        } else {
            $output = $prediction['output'];
        }

        // Clean up output - remove markdown code blocks
        $output = trim($output);
        $output = preg_replace('/^```(?:json)?\s*/s', '', $output);
        $output = preg_replace('/\s*```$/s', '', $output);

        // Extract JSON object
        if (preg_match('/(\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\})/s', $output, $matches)) {
            $json_str = $matches[1];
        } else {
            $json_str = $output;
        }

        $data = json_decode($json_str, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Centromex: Failed to parse LLM JSON response: " . substr($output, 0, 500));
            return ['is_product' => false];
        }

        return $data;
    }

    /**
     * Validate if detected product matches Open Food Facts result using LLM
     *
     * @param string $detected_brand
     * @param string $detected_name
     * @param string $off_brand
     * @param string $off_name
     * @return array ['is_match' => bool, 'reason' => string]
     */
    public function validate_match($detected_brand, $detected_name, $off_brand, $off_name) {
        $prompt = <<<PROMPT
I detected a product from an image and found a potential match in a database.
Determine if these are the SAME product.

DETECTED FROM IMAGE:
- Brand: "$detected_brand"
- Product: "$detected_name"

DATABASE RESULT:
- Brand: "$off_brand"
- Product: "$off_name"

RULES:
- The brands must be the same company (exact match or known variant)
- The product type must match (both creams, both yogurts, etc.)
- Minor spelling differences are OK (e.g., "Goya" vs "GOYA")
- Different products from the same brand are NOT a match
- If the database brand is completely different, it's NOT a match

Examples:
- "El Mexicano" + "Crema" vs "El Mexicano" + "Sour Cream" = MATCH (same brand, same product type)
- "Del Prado" + "Crema" vs "Verduras Curro" + "Remolacha" = NOT MATCH (different brands, different products)
- "Saborico" + "Yogurt" vs "gullón" + "Sandwich" = NOT MATCH (different brands, different products)

Return ONLY valid JSON:
{
  "is_match": true or false,
  "reason": "brief explanation"
}
PROMPT;

        $prediction = $this->create_prediction('bfb7df9586ae4fafa00a593d8dc4868698f72cf9d695da28b8c8a70f88e876ba', [
            'prompt' => $prompt,
            'max_output_tokens' => 200,
            'temperature' => 0.1
        ]);

        $prediction = $this->wait_for_prediction($prediction['id']);

        if ($prediction['status'] !== 'succeeded') {
            error_log("Centromex: LLM validation failed");
            return ['is_match' => false, 'reason' => 'LLM error'];
        }

        // Parse JSON from output - Gemini returns array of text parts
        if (is_array($prediction['output'])) {
            $output = '';
            foreach ($prediction['output'] as $part) {
                if (is_string($part)) {
                    $output .= $part;
                } elseif (isset($part['text'])) {
                    $output .= $part['text'];
                }
            }
        } else {
            $output = $prediction['output'];
        }

        // Clean up output - remove markdown code blocks
        $output = trim($output);
        $output = preg_replace('/^```(?:json)?\s*/s', '', $output);
        $output = preg_replace('/\s*```$/s', '', $output);

        // Extract JSON object
        if (preg_match('/(\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\})/s', $output, $matches)) {
            $json_str = $matches[1];
        } else {
            $json_str = $output;
        }

        $data = json_decode($json_str, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Centromex: Failed to parse validation JSON: " . substr($output, 0, 500));
            return ['is_match' => false, 'reason' => 'Parse error'];
        }

        return $data;
    }

    /**
     * Upscale image using nano-banana-pro
     *
     * @param string $image_path Path to image to upscale
     * @param string $product_description Description for better upscaling
     * @return string Path to upscaled image (replaces original)
     */
    public function upscale_image($image_path, $product_description) {
        if (!file_exists($image_path)) {
            throw new Exception("Image file not found: $image_path");
        }

        $base64_image = $this->encode_image($image_path);

        $prediction = $this->create_prediction('google/nano-banana-pro', [
            'prompt' => "Product photo of $product_description, clean white background, professional product photography",
            'image_input' => [$base64_image],
            'aspect_ratio' => '1:1',
            'resolution' => '1K',
            'output_format' => 'jpg'
        ]);

        $prediction = $this->wait_for_prediction($prediction['id']);

        if ($prediction['status'] !== 'succeeded') {
            error_log("Centromex: Upscale failed for $image_path");
            return $image_path; // Return original on failure
        }

        // Download upscaled image
        $output_url = is_array($prediction['output']) ? $prediction['output'][0] : $prediction['output'];

        if (empty($output_url)) {
            error_log("Centromex: No upscale output URL");
            return $image_path;
        }

        // Download and replace original
        $response = wp_remote_get($output_url, ['timeout' => 60]);

        if (is_wp_error($response)) {
            error_log("Centromex: Failed to download upscaled image: " . $response->get_error_message());
            return $image_path;
        }

        $image_data = wp_remote_retrieve_body($response);
        file_put_contents($image_path, $image_data);

        error_log("Centromex: Upscaled " . basename($image_path));

        return $image_path;
    }

    /**
     * Create a prediction on Replicate
     *
     * @param string $model_version Model identifier (e.g., 'owner/model')
     * @param array $input Input parameters
     * @return array Prediction response
     */
    private function create_prediction($model_version, $input) {
        $url = $this->api_base . '/predictions';

        $body = json_encode([
            'version' => $model_version,
            'input' => $input
        ]);

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json'
            ],
            'body' => $body,
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new Exception('Replicate API error: ' . $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (wp_remote_retrieve_response_code($response) !== 201) {
            throw new Exception('Replicate API error: ' . print_r($data, true));
        }

        return $data;
    }

    /**
     * Wait for prediction to complete
     *
     * @param string $prediction_id
     * @param int $max_wait Max seconds to wait
     * @return array Prediction result
     */
    private function wait_for_prediction($prediction_id, $max_wait = 300) {
        $url = $this->api_base . '/predictions/' . $prediction_id;
        $start_time = time();

        while (time() - $start_time < $max_wait) {
            $response = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_token
                ],
                'timeout' => 30
            ]);

            if (is_wp_error($response)) {
                throw new Exception('Replicate API error: ' . $response->get_error_message());
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            $status = isset($data['status']) ? $data['status'] : 'unknown';

            if ($status === 'succeeded' || $status === 'failed' || $status === 'canceled') {
                return $data;
            }

            sleep(2);
        }

        throw new Exception('Prediction timeout');
    }

    /**
     * Get model version ID (hardcoded for known models)
     *
     * @param string $model_name
     * @return string Version ID
     */
    private function get_model_version($model_name) {
        // In production, you'd fetch these via API or cache them
        // For now, use latest version string format
        $versions = [
            'adirik/grounding-dino' => 'latest',
            'meta/llama-3.2-11b-vision-instruct' => 'latest',
            'meta/llama-3.2-3b-instruct' => 'latest',
            'google/nano-banana-pro' => 'latest'
        ];

        if (isset($versions[$model_name]) && $versions[$model_name] === 'latest') {
            // Fetch model to get latest version
            return $this->get_latest_version($model_name);
        }

        throw new Exception("Unknown model: $model_name");
    }

    /**
     * Get latest version of a model
     *
     * @param string $model_name
     * @return string Version ID
     */
    private function get_latest_version($model_name) {
        $url = $this->api_base . '/models/' . $model_name;

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_token
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new Exception('Failed to get model version: ' . $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($data['latest_version']['id'])) {
            return $data['latest_version']['id'];
        }

        throw new Exception('Could not determine model version');
    }

    /**
     * Encode image to base64 data URI
     *
     * @param string $image_path
     * @return string Base64 data URI
     */
    private function encode_image($image_path) {
        $image_data = file_get_contents($image_path);
        $mime_type = mime_content_type($image_path);

        return 'data:' . $mime_type . ';base64,' . base64_encode($image_data);
    }

    /**
     * Remove duplicate/overlapping bounding boxes
     *
     * @param array $detections
     * @param float $iou_threshold IoU threshold for duplicates
     * @return array Filtered detections
     */
    private function remove_duplicate_boxes($detections, $iou_threshold = 0.5) {
        if (empty($detections)) {
            return [];
        }

        // Sort by confidence (highest first)
        usort($detections, function($a, $b) {
            $conf_a = isset($a['confidence']) ? $a['confidence'] : 0;
            $conf_b = isset($b['confidence']) ? $b['confidence'] : 0;
            return $conf_b <=> $conf_a;
        });

        $kept = [];

        foreach ($detections as $detection) {
            $is_duplicate = false;

            foreach ($kept as $kept_detection) {
                $iou = $this->calculate_iou($detection['bbox'], $kept_detection['bbox']);
                if ($iou > $iou_threshold) {
                    $is_duplicate = true;
                    break;
                }
            }

            if (!$is_duplicate) {
                $kept[] = $detection;
            }
        }

        return $kept;
    }

    /**
     * Calculate IoU (Intersection over Union) between two boxes
     *
     * @param array $box1 [x1, y1, x2, y2]
     * @param array $box2 [x1, y1, x2, y2]
     * @return float IoU value
     */
    private function calculate_iou($box1, $box2) {
        $x1 = max($box1[0], $box2[0]);
        $y1 = max($box1[1], $box2[1]);
        $x2 = min($box1[2], $box2[2]);
        $y2 = min($box1[3], $box2[3]);

        $inter_area = max(0, $x2 - $x1) * max(0, $y2 - $y1);

        $box1_area = ($box1[2] - $box1[0]) * ($box1[3] - $box1[1]);
        $box2_area = ($box2[2] - $box2[0]) * ($box2[3] - $box2[1]);

        $union_area = $box1_area + $box2_area - $inter_area;

        return $union_area > 0 ? $inter_area / $union_area : 0;
    }
}
