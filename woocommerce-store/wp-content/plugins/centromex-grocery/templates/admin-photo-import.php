<?php
/**
 * Admin photo import page template
 *
 * @package Centromex_Grocery
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check for Replicate API token
$has_api_token = defined('CENTROMEX_REPLICATE_API_TOKEN') && !empty(CENTROMEX_REPLICATE_API_TOKEN);
?>

<div class="wrap centromex-photo-import-wrap">
    <h1><?php _e('Import Products from Photos', 'centromex-grocery'); ?></h1>

    <?php if (!$has_api_token) : ?>
        <div class="notice notice-error">
            <p>
                <strong><?php _e('API Configuration Required', 'centromex-grocery'); ?></strong><br>
                <?php _e('Please configure CENTROMEX_REPLICATE_API_TOKEN in your environment variables or wp-config.php to use this feature.', 'centromex-grocery'); ?>
            </p>
        </div>
    <?php else : ?>

        <div class="centromex-import-instructions">
            <h2><?php _e('How it Works', 'centromex-grocery'); ?></h2>
            <ol>
                <li><?php _e('Upload photos of your grocery store shelves (JPG, PNG, or WEBP)', 'centromex-grocery'); ?></li>
                <li><?php _e('AI will detect and identify each product', 'centromex-grocery'); ?></li>
                <li><?php _e('Products are validated against Open Food Facts database', 'centromex-grocery'); ?></li>
                <li><?php _e('Products are created as drafts for you to review', 'centromex-grocery'); ?></li>
                <li><?php _e('Review and publish products when ready', 'centromex-grocery'); ?></li>
            </ol>
            <p class="description">
                <?php _e('Maximum 10 images per batch. Max file size: 10MB. Processing happens in the background.', 'centromex-grocery'); ?>
            </p>
        </div>

        <div class="centromex-upload-section">
            <div id="centromex-drop-zone" class="centromex-drop-zone">
                <div class="centromex-drop-zone-content">
                    <span class="dashicons dashicons-cloud-upload"></span>
                    <p><?php _e('Drag & Drop shelf photos here', 'centromex-grocery'); ?></p>
                    <p class="description"><?php _e('or click to browse', 'centromex-grocery'); ?></p>
                    <input type="file" id="centromex-file-input" multiple accept="image/jpeg,image/png,image/webp" style="display: none;">
                </div>
            </div>

            <div id="centromex-file-preview" class="centromex-file-preview"></div>

            <button id="centromex-start-import" class="button button-primary button-large" disabled>
                <?php _e('Start Import', 'centromex-grocery'); ?>
            </button>
        </div>

        <div id="centromex-progress-section" class="centromex-progress-section" style="display: none;">
            <h2><?php _e('Import Progress', 'centromex-grocery'); ?></h2>

            <div class="centromex-progress-bar-container">
                <div id="centromex-progress-bar" class="centromex-progress-bar"></div>
            </div>

            <div class="centromex-progress-stats">
                <div class="stat-card">
                    <span class="stat-label"><?php _e('Images Processed', 'centromex-grocery'); ?></span>
                    <span class="stat-value" id="stat-images">0 / 0</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label"><?php _e('Products Detected', 'centromex-grocery'); ?></span>
                    <span class="stat-value" id="stat-products">0</span>
                </div>
                <div class="stat-card success">
                    <span class="stat-label"><?php _e('Verified', 'centromex-grocery'); ?></span>
                    <span class="stat-value" id="stat-verified">0</span>
                </div>
                <div class="stat-card warning">
                    <span class="stat-label"><?php _e('Needs Review', 'centromex-grocery'); ?></span>
                    <span class="stat-value" id="stat-review">0</span>
                </div>
            </div>

            <div class="centromex-progress-status">
                <p id="centromex-status-message"></p>
            </div>

            <div id="centromex-completion-message" style="display: none;">
                <div class="notice notice-success inline">
                    <p>
                        <strong><?php _e('Import Complete!', 'centromex-grocery'); ?></strong><br>
                        <?php _e('Products have been created as drafts. Review and publish them below.', 'centromex-grocery'); ?>
                    </p>
                </div>
                <p>
                    <a href="<?php echo admin_url('edit.php?post_type=product&post_status=draft'); ?>" class="button button-primary">
                        <?php _e('Review Draft Products', 'centromex-grocery'); ?>
                    </a>
                    <button id="centromex-import-another" class="button">
                        <?php _e('Import Another Batch', 'centromex-grocery'); ?>
                    </button>
                </p>
            </div>
        </div>

    <?php endif; ?>
</div>
