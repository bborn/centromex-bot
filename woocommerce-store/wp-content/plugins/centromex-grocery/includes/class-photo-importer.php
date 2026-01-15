<?php
/**
 * Photo Importer
 * Main orchestrator for the import pipeline
 *
 * @package Centromex_Grocery
 */

if (!defined('ABSPATH')) {
    exit;
}

class Centromex_Photo_Importer {

    private $replicate;
    private $openfoodfacts;
    private $image_processor;
    private $product_creator;

    public function __construct() {
        $this->replicate = new Centromex_Replicate_Client();
        $this->openfoodfacts = new Centromex_OpenFoodFacts_Client();
        $this->image_processor = new Centromex_Image_Processor();
        $this->product_creator = new Centromex_Product_Creator();
    }

    /**
     * Process a single shelf image
     *
     * @param string $image_path Path to source image
     * @param string $image_hash Image hash for idempotency
     * @param string $batch_id Batch identifier
     * @return array Results
     */
    public function process_image($image_path, $image_hash, $batch_id) {
        $results = [
            'success' => false,
            'products_detected' => 0,
            'products_created' => 0,
            'products_skipped' => 0,
            'errors' => []
        ];

        try {
            // Check if already processed
            if ($this->image_processor->image_exists($image_hash)) {
                error_log("Centromex: Image already processed (hash: $image_hash)");
                $results['success'] = true;
                $results['skipped'] = true;
                return $results;
            }

            error_log("Centromex: Processing image: " . basename($image_path));

            // Update progress
            $this->update_progress($batch_id, [
                'current_image' => basename($image_path),
                'status' => 'detecting'
            ]);

            // Step 1: Detect products with DINO
            $detections = $this->replicate->detect_products($image_path);
            $results['products_detected'] = count($detections);

            error_log("Centromex: Found {$results['products_detected']} products");

            $this->update_progress($batch_id, [
                'total_products' => get_transient("centromex_import_{$batch_id}_total_products") + count($detections)
            ]);
            set_transient("centromex_import_{$batch_id}_total_products", get_transient("centromex_import_{$batch_id}_total_products") + count($detections), 3600);

            // Step 2: Process each detection
            foreach ($detections as $index => $detection) {
                try {
                    $product_result = $this->process_detection($detection, $index, $image_path, $image_hash, $batch_id);

                    if ($product_result['created']) {
                        $results['products_created']++;
                    } elseif ($product_result['skipped']) {
                        $results['products_skipped']++;
                    }

                } catch (Exception $e) {
                    error_log("Centromex: Detection processing error: " . $e->getMessage());
                    $results['errors'][] = $e->getMessage();
                }
            }

            // Mark image as processed
            $this->image_processor->mark_processed(
                $image_hash,
                basename($image_path),
                $batch_id,
                $results['products_detected'],
                $results['products_created']
            );

            // Update final progress
            $this->update_progress($batch_id, [
                'processed_images' => get_transient("centromex_import_{$batch_id}_processed") + 1,
                'status' => 'processing'
            ]);
            set_transient("centromex_import_{$batch_id}_processed", get_transient("centromex_import_{$batch_id}_processed") + 1, 3600);

            $results['success'] = true;

        } catch (Exception $e) {
            error_log("Centromex: Image processing error: " . $e->getMessage());
            $results['errors'][] = $e->getMessage();
            throw $e; // Re-throw for Action Scheduler retry
        }

        return $results;
    }

    /**
     * Process a single detection
     *
     * @param array $detection Detection data with bbox
     * @param int $index Detection index
     * @param string $source_image_path Source image path
     * @param string $source_hash Source image hash
     * @param string $batch_id Batch ID
     * @return array Result with 'created' or 'skipped' flags
     */
    private function process_detection($detection, $index, $source_image_path, $source_hash, $batch_id) {
        $result = ['created' => false, 'skipped' => false];

        $bbox = $detection['bbox'];
        $confidence = isset($detection['confidence']) ? $detection['confidence'] : 0;
        $label = isset($detection['label']) ? $detection['label'] : 'product';

        error_log("Centromex: Detection #{$index}: $label (" . round($confidence * 100) . "%)");

        // Step 1: Crop product
        $crop_path = $this->image_processor->crop_product($source_image_path, $bbox, $index);

        if (!$crop_path) {
            error_log("Centromex: Failed to crop detection #{$index}");
            $result['skipped'] = true;
            return $result;
        }

        // Step 2: Identify with LLM
        error_log("Centromex: Identifying product...");
        $product_info = $this->replicate->identify_product($crop_path);

        if (!isset($product_info['is_product']) || !$product_info['is_product']) {
            error_log("Centromex: Not a product");
            @unlink($crop_path);
            $result['skipped'] = true;
            return $result;
        }

        $brand = isset($product_info['brand']) ? trim($product_info['brand']) : '';
        $product_name = isset($product_info['product_name']) ? trim($product_info['product_name']) : '';
        $full_name = isset($product_info['full_name']) ? trim($product_info['full_name']) : '';

        if (empty($brand) || empty($product_name)) {
            error_log("Centromex: Missing brand or product name");
            @unlink($crop_path);
            $result['skipped'] = true;
            return $result;
        }

        error_log("Centromex: Identified - $brand / $product_name");

        // Step 3: Validate with Open Food Facts
        $status = 'needs_review';
        $upc = '';
        $categories = isset($product_info['category']) ? [$product_info['category']] : [];
        $description = '';
        $final_name = $full_name;
        $final_brand = $brand;

        error_log("Centromex: Validating with Open Food Facts...");
        $validation = $this->openfoodfacts->validate_product($brand, $product_name, $this->replicate);

        if ($validation['found']) {
            error_log("Centromex: VERIFIED via Open Food Facts");
            $status = 'verified';
            $upc = isset($validation['off_code']) ? $validation['off_code'] : '';
            $final_name = isset($validation['off_name']) ? $validation['off_name'] : $full_name;
            $final_brand = isset($validation['off_brand']) ? $validation['off_brand'] : $brand;
            // Use Open Food Facts categories if available, otherwise keep LLM category
            if (isset($validation['off_categories']) && !empty($validation['off_categories'])) {
                $categories = is_array($validation['off_categories']) ? $validation['off_categories'] : [$validation['off_categories']];
            }
        } else {
            error_log("Centromex: NOT IN DATABASE - marking for review");
        }

        // Check for duplicate UPC
        if (!empty($upc) && $this->product_creator->find_by_upc($upc)) {
            error_log("Centromex: Duplicate UPC ($upc)");
            @unlink($crop_path);
            $result['skipped'] = true;
            return $result;
        }

        // Step 4: Upscale image
        error_log("Centromex: Upscaling image...");
        try {
            $this->replicate->upscale_image($crop_path, "$final_brand $final_name");
        } catch (Exception $e) {
            error_log("Centromex: Upscale failed, continuing with original: " . $e->getMessage());
        }

        // Step 5: Save product image
        $sku = $this->generate_temp_sku($final_brand, $final_name);
        $image_info = $this->image_processor->save_product_image($crop_path, $sku);

        // Step 6: Create WooCommerce product
        $product_data = [
            'brand' => $final_brand,
            'product_name' => $final_name,
            'full_name' => $final_name,
            'size' => isset($product_info['size']) ? $product_info['size'] : '',
            'price' => isset($product_info['estimated_price_usd']) ? $product_info['estimated_price_usd'] : 0,
            'status' => $status,
            'upc' => $upc,
            'categories' => $categories,
            'description' => $description,
            'off_validated' => $validation['found']
        ];

        $product_id = $this->product_creator->create_product(
            $product_data,
            $image_info['path'],
            $source_hash,
            basename($source_image_path)
        );

        if (is_wp_error($product_id)) {
            error_log("Centromex: Failed to create product: " . $product_id->get_error_message());
            $result['skipped'] = true;
            return $result;
        }

        // Update progress counters
        if ($status === 'verified') {
            $key = "centromex_import_{$batch_id}_verified";
            set_transient($key, get_transient($key) + 1, 3600);
        } else {
            $key = "centromex_import_{$batch_id}_needs_review";
            set_transient($key, get_transient($key) + 1, 3600);
        }

        error_log("Centromex: Created product #{$product_id} - Status: $status");

        $result['created'] = true;
        return $result;
    }

    /**
     * Generate temporary SKU for image storage
     */
    private function generate_temp_sku($brand, $product_name) {
        $sku = $brand . '-' . $product_name;
        $sku = strtolower($sku);
        $sku = preg_replace('/[^a-z0-9]+/', '-', $sku);
        $sku = preg_replace('/-+/', '-', $sku);
        $sku = trim($sku, '-');
        return substr($sku, 0, 50);
    }

    /**
     * Update progress transient
     *
     * @param string $batch_id
     * @param array $updates
     */
    private function update_progress($batch_id, $updates) {
        $progress = get_transient("centromex_import_{$batch_id}");

        if (!$progress) {
            $progress = [
                'batch_id' => $batch_id,
                'total_images' => 0,
                'processed_images' => 0,
                'total_products' => 0,
                'verified_products' => 0,
                'review_products' => 0,
                'status' => 'starting',
                'current_image' => '',
                'updated_at' => time()
            ];
        }

        $progress = array_merge($progress, $updates);
        $progress['updated_at'] = time();

        set_transient("centromex_import_{$batch_id}", $progress, 3600);
    }

    /**
     * Initialize batch progress
     *
     * @param string $batch_id
     * @param int $total_images
     */
    public function init_batch($batch_id, $total_images) {
        $this->update_progress($batch_id, [
            'total_images' => $total_images,
            'status' => 'queued'
        ]);

        // Initialize counters
        set_transient("centromex_import_{$batch_id}_processed", 0, 3600);
        set_transient("centromex_import_{$batch_id}_total_products", 0, 3600);
        set_transient("centromex_import_{$batch_id}_verified", 0, 3600);
        set_transient("centromex_import_{$batch_id}_needs_review", 0, 3600);
    }

    /**
     * Mark batch as complete
     *
     * @param string $batch_id
     */
    public function complete_batch($batch_id) {
        $this->update_progress($batch_id, [
            'status' => 'completed'
        ]);
    }
}
