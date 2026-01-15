<?php
/**
 * Image Processor
 * Handles image cropping, hashing, and storage
 *
 * @package Centromex_Grocery
 */

if (!defined('ABSPATH')) {
    exit;
}

class Centromex_Image_Processor {

    private $upload_dir;
    private $products_dir;
    private $source_dir;

    public function __construct() {
        $wp_upload_dir = wp_upload_dir();
        $this->upload_dir = $wp_upload_dir['basedir'];

        // Create directories
        $this->products_dir = $this->upload_dir . '/centromex-products';
        $this->source_dir = $this->upload_dir . '/centromex-source';

        wp_mkdir_p($this->products_dir);
        wp_mkdir_p($this->source_dir);
    }

    /**
     * Calculate SHA-256 hash of image file for idempotency
     *
     * @param string $image_path Path to image
     * @return string SHA-256 hash
     */
    public function hash_image($image_path) {
        if (!file_exists($image_path)) {
            throw new Exception("Image file not found: $image_path");
        }

        return hash_file('sha256', $image_path);
    }

    /**
     * Store uploaded image in source directory
     *
     * @param string $temp_path Temporary upload path
     * @param string $original_filename Original filename
     * @return array ['path' => string, 'hash' => string]
     */
    public function store_source_image($temp_path, $original_filename) {
        if (!file_exists($temp_path)) {
            throw new Exception("Temporary file not found: $temp_path");
        }

        $hash = $this->hash_image($temp_path);

        // Use hash as filename to avoid duplicates
        $ext = pathinfo($original_filename, PATHINFO_EXTENSION);
        $new_filename = $hash . '.' . strtolower($ext);
        $new_path = $this->source_dir . '/' . $new_filename;

        // Copy to source directory
        if (!copy($temp_path, $new_path)) {
            throw new Exception("Failed to copy image to source directory");
        }

        return [
            'path' => $new_path,
            'hash' => $hash,
            'filename' => $new_filename
        ];
    }

    /**
     * Crop product from shelf image based on bounding box
     *
     * @param string $image_path Path to source image
     * @param array $bbox Bounding box [x1, y1, x2, y2]
     * @param int $index Detection index
     * @param int $padding Padding around box in pixels
     * @return string|null Path to cropped image or null on failure
     */
    public function crop_product($image_path, $bbox, $index, $padding = 5) {
        if (!file_exists($image_path)) {
            throw new Exception("Image file not found: $image_path");
        }

        // Load image based on type
        $image_type = exif_imagetype($image_path);

        switch ($image_type) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($image_path);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($image_path);
                break;
            case IMAGETYPE_WEBP:
                $source = imagecreatefromwebp($image_path);
                break;
            default:
                error_log("Centromex: Unsupported image type: $image_type");
                return null;
        }

        if (!$source) {
            error_log("Centromex: Failed to load image: $image_path");
            return null;
        }

        $img_width = imagesx($source);
        $img_height = imagesy($source);

        // Extract and validate bbox
        list($x1, $y1, $x2, $y2) = $bbox;

        // Add padding
        $x1 = max((int)$x1 - $padding, 0);
        $y1 = max((int)$y1 - $padding, 0);
        $x2 = min((int)$x2 + $padding, $img_width);
        $y2 = min((int)$y2 + $padding, $img_height);

        $crop_w = $x2 - $x1;
        $crop_h = $y2 - $y1;

        // Skip if too small
        if ($crop_w < 30 || $crop_h < 30) {
            imagedestroy($source);
            return null;
        }

        // Create cropped image
        $cropped = imagecreatetruecolor($crop_w, $crop_h);

        // Preserve transparency for PNG
        if ($image_type === IMAGETYPE_PNG) {
            imagealphablending($cropped, false);
            imagesavealpha($cropped, true);
            $transparent = imagecolorallocatealpha($cropped, 255, 255, 255, 127);
            imagefilledrectangle($cropped, 0, 0, $crop_w, $crop_h, $transparent);
        }

        imagecopy($cropped, $source, 0, 0, $x1, $y1, $crop_w, $crop_h);

        // Save to temporary file
        $temp_path = sys_get_temp_dir() . '/centromex_crop_' . uniqid() . '.jpg';
        imagejpeg($cropped, $temp_path, 95);

        imagedestroy($source);
        imagedestroy($cropped);

        return $temp_path;
    }

    /**
     * Save product image with SKU-based filename
     *
     * @param string $temp_path Temporary image path
     * @param string $sku Product SKU
     * @return array ['path' => string, 'url' => string, 'filename' => string]
     */
    public function save_product_image($temp_path, $sku) {
        if (!file_exists($temp_path)) {
            throw new Exception("Temporary file not found: $temp_path");
        }

        $filename = $sku . '.jpg';
        $final_path = $this->products_dir . '/' . $filename;

        if (!copy($temp_path, $final_path)) {
            throw new Exception("Failed to save product image");
        }

        // Clean up temp file
        @unlink($temp_path);

        $wp_upload_dir = wp_upload_dir();
        $url = $wp_upload_dir['baseurl'] . '/centromex-products/' . $filename;

        return [
            'path' => $final_path,
            'url' => $url,
            'filename' => $filename
        ];
    }

    /**
     * Get products directory path
     *
     * @return string
     */
    public function get_products_dir() {
        return $this->products_dir;
    }

    /**
     * Get source directory path
     *
     * @return string
     */
    public function get_source_dir() {
        return $this->source_dir;
    }

    /**
     * Check if image with hash already exists
     *
     * @param string $hash SHA-256 hash
     * @return bool
     */
    public function image_exists($hash) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_processed_images';

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE image_hash = %s",
            $hash
        ));

        return !is_null($result);
    }

    /**
     * Mark image as processed
     *
     * @param string $hash Image hash
     * @param string $original_filename Original filename
     * @param string $batch_id Batch ID
     * @param int $products_detected Number of products detected
     * @param int $products_created Number of products created
     * @return int|false Insert ID or false on failure
     */
    public function mark_processed($hash, $original_filename, $batch_id, $products_detected = 0, $products_created = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'centromex_processed_images';

        $result = $wpdb->insert($table, [
            'image_hash' => $hash,
            'original_filename' => $original_filename,
            'products_detected' => $products_detected,
            'products_created' => $products_created,
            'batch_id' => $batch_id,
            'processed_at' => current_time('mysql')
        ], [
            '%s', '%s', '%d', '%d', '%s', '%s'
        ]);

        return $result !== false ? $wpdb->insert_id : false;
    }
}
