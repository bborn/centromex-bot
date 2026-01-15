# Photo Import Plugin - Implementation Plan

## Overview
Extend the Centromex Grocery WordPress plugin to allow store owners to upload shelf photos and automatically create WooCommerce product listings using AI-powered image processing.

## Key Requirements
- ✅ **Async Processing**: All ML/API operations run in background jobs
- ✅ **Idempotent**: Can re-run without creating duplicates
- ✅ **Draft Status**: Products created as drafts for manual review
- ✅ **Progress Tracking**: Real-time UI showing processing status
- ✅ **Validation**: Products verified against Open Food Facts database

## Architecture

### 1. Background Job System
**Use WordPress Action Scheduler** (bundled with WooCommerce):
- Reliable queue-based processing
- Built-in retry logic
- No external dependencies
- Persistent across server restarts

### 2. Processing Pipeline (per uploaded image)

```
Upload → Queue Job → [Background Processing] → Review Interface
                           ↓
                     Grounding DINO (detect products)
                           ↓
                     For each detection:
                       - Crop product
                       - Gemini identification
                       - Open Food Facts validation
                       - nano-banana-pro upscaling
                       - Create WooCommerce draft product
```

### 3. Idempotency Strategy

**Image-level:**
- Hash uploaded image file (SHA256)
- Store hash in custom table: `wp_centromex_processed_images`
- Skip processing if hash exists

**Product-level:**
- Store detection metadata in product meta
- Use UPC/barcode as unique identifier when available
- Check for existing products by UPC before creation

### 4. Product Structure

**Draft Products with Custom Meta:**
```php
$product_meta = [
    '_centromex_status' => 'verified' | 'needs_review',
    '_centromex_source_hash' => 'sha256_hash',
    '_centromex_source_image' => 'original_filename.jpg',
    '_centromex_detected_data' => json_encode([
        'brand' => 'Goya',
        'product_name' => 'Mango Nectar',
        'confidence' => 0.95,
        'off_validated' => true,
        'detection_bbox' => [x1, y1, x2, y2]
    ]),
    '_centromex_upc' => '041331234567',
];

$product_status = 'draft'; // Always draft for manual review
```

## File Structure

```
centromex-grocery/
├── centromex-grocery.php          (main plugin file - add hooks)
├── includes/
│   ├── class-photo-importer.php        (main orchestrator)
│   ├── class-image-processor.php       (crop, hash, storage)
│   ├── class-replicate-client.php      (DINO + LLM + upscaling API - all via Replicate)
│   ├── class-openfoodfacts-client.php  (validation API)
│   ├── class-product-creator.php       (WooCommerce integration)
│   └── class-import-queue.php          (Action Scheduler wrapper)
├── admin/
│   ├── class-photo-import-admin.php    (admin page controller)
│   └── views/
│       ├── upload-form.php             (drag & drop interface)
│       └── progress-view.php           (processing status)
├── assets/
│   ├── css/
│   │   └── admin-photo-import.css
│   └── js/
│       ├── photo-upload.js             (drag & drop, AJAX)
│       └── progress-tracker.js         (polling, updates)
└── templates/
    └── admin-photo-import.php          (main template)
```

## Implementation Details

### 5. Admin Interface

**Menu Structure:**
```
Centromex Orders (existing)
└── Import from Photos (new submenu)
```

**Upload Page:**
- Drag & drop multiple images
- File validation (JPG/PNG/WEBP, max 10MB)
- Preview thumbnails before upload
- "Start Import" button → enqueues jobs

**Progress Page:**
- Real-time progress bar
- Status breakdown:
  - Images processed: X/Y
  - Products detected: Z
  - Verified: A
  - Needs review: B
- Live feed of recent detections
- Link to review draft products

### 6. API Integration

**Replicate API (All ML operations - DINO, LLM, Upscaling):**
```php
class Centromex_Replicate_Client {
    private $api_token;

    public function detect_products($image_path) {
        // Run multiple DINO queries (same as Ruby)
        // Model: adirik/grounding-dino
        $queries = [
            "bottle . jar . carton . can . box . package",
            "tomato . lime . pepper . avocado . onion",
            "chicken . meat . sausage . chorizo",
            // ... etc
        ];

        // Deduplicate overlapping boxes
        // Return detections with bounding boxes
    }

    public function identify_product($image_path) {
        // Use Gemini or Llama 3.1 on Replicate with JSON mode
        // Model: meta/llama-3.1-70b-instruct or similar
        $prompt = "Identify this product and return JSON with: brand, product_name, size, estimated_price_usd, is_product";

        $response = $this->predict_with_json([
            'model' => 'meta/llama-3.1-70b-instruct',
            'input' => [
                'image' => $base64_image,
                'prompt' => $prompt,
                'response_format' => ['type' => 'json_object']
            ]
        ]);

        return json_decode($response['output'], true);
    }

    public function validate_match($detected_brand, $detected_name, $off_brand, $off_name) {
        // Use LLM on Replicate to validate if products match
        // Returns JSON: {is_match: true/false, reason: "..."}
    }

    public function upscale_image($image_path, $product_description) {
        // Call nano-banana-pro
        // Download and replace image
    }
}
```

**Open Food Facts (Validation):**
```php
class Centromex_OpenFoodFacts_Client {
    public function validate_product($brand, $product_name) {
        // Search OFF database
        // Use Gemini to validate match quality
        return [
            'found' => true,
            'name' => 'Goya Mango Nectar',
            'upc' => '041331...',
            'categories' => 'Beverages, Juices',
        ];
    }
}
```

### 7. Background Jobs (Action Scheduler)

**Job Structure:**
```php
// When images uploaded
as_enqueue_async_action(
    'centromex_process_image',
    [
        'image_path' => '/tmp/shelf_image_1.jpg',
        'image_hash' => 'abc123...',
        'batch_id' => 'batch_2026-01-15_001'
    ],
    'centromex-import'
);

// Job handler
add_action('centromex_process_image', 'centromex_handle_image_import', 10, 1);

function centromex_handle_image_import($args) {
    $importer = new Centromex_Photo_Importer();
    $importer->process_image(
        $args['image_path'],
        $args['image_hash'],
        $args['batch_id']
    );
}
```

**Progress Tracking:**
```php
// Store in Redis (fast) or WordPress transients (fallback)
$progress = [
    'batch_id' => 'batch_2026-01-15_001',
    'total_images' => 10,
    'processed_images' => 3,
    'total_products' => 47,
    'verified_products' => 32,
    'review_products' => 15,
    'status' => 'processing' | 'completed' | 'failed',
    'current_image' => 'shelf_image_4.jpg',
    'updated_at' => time()
];

set_transient("centromex_import_{$batch_id}", $progress, 3600);
```

### 8. Idempotency Implementation

**Custom Database Table:**
```sql
CREATE TABLE wp_centromex_processed_images (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    image_hash VARCHAR(64) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    products_detected INT DEFAULT 0,
    products_created INT DEFAULT 0,
    processed_at DATETIME NOT NULL,
    batch_id VARCHAR(50),
    PRIMARY KEY (id),
    UNIQUE KEY image_hash (image_hash),
    KEY batch_id (batch_id)
);
```

**Check Before Processing:**
```php
function is_image_already_processed($image_hash) {
    global $wpdb;
    $table = $wpdb->prefix . 'centromex_processed_images';

    $result = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE image_hash = %s",
        $image_hash
    ));

    return !is_null($result);
}
```

**Check Before Creating Product:**
```php
function product_exists_by_upc($upc) {
    if (empty($upc)) return false;

    $args = [
        'post_type' => 'product',
        'meta_query' => [
            [
                'key' => '_centromex_upc',
                'value' => $upc,
                'compare' => '='
            ]
        ],
        'posts_per_page' => 1
    ];

    $products = get_posts($args);
    return !empty($products);
}
```

### 9. Error Handling & Retry

**Action Scheduler handles retries automatically:**
- Failed jobs retry 3 times
- Exponential backoff
- Failures logged

**Custom error handling:**
```php
try {
    $detections = $replicate->detect_products($image_path);
} catch (Exception $e) {
    error_log("Centromex Import Error: " . $e->getMessage());

    // Update progress with error
    update_import_progress($batch_id, [
        'errors' => [
            'image' => $image_path,
            'message' => $e->getMessage(),
            'time' => current_time('mysql')
        ]
    ]);

    // Let Action Scheduler retry
    throw $e;
}
```

### 10. API Configuration

**Environment Variables (via Docker env vars or .env file):**
```php
// In wp-config.php or loaded from environment
define('CENTROMEX_REPLICATE_API_TOKEN', getenv('REPLICATE_API_TOKEN'));

// Access in plugin
$api_token = defined('CENTROMEX_REPLICATE_API_TOKEN')
    ? CENTROMEX_REPLICATE_API_TOKEN
    : '';
```

## Implementation Order

1. **Phase 1: Foundation**
   - Create file structure
   - Add admin menu & basic page
   - Set up Action Scheduler hooks
   - Create database table for tracking

2. **Phase 2: Upload Interface**
   - Drag & drop UI
   - AJAX upload endpoint
   - Image validation & hashing
   - Queue job creation

3. **Phase 3: API Clients**
   - Replicate client (DINO + LLM + upscaling - all operations)
   - Open Food Facts client (validation)

4. **Phase 4: Processing Pipeline**
   - Image processor (crop, storage)
   - Product creator (WooCommerce integration)
   - Main importer orchestrator
   - Action Scheduler job handler

5. **Phase 5: Progress Tracking**
   - AJAX progress endpoint
   - JavaScript polling
   - Live UI updates

6. **Phase 6: Review Interface**
   - Filter for draft products
   - Show verification status
   - Bulk publish action

## Configuration Decisions

✅ **API Keys**: Environment variables (REPLICATE_API_TOKEN)
✅ **LLM Provider**: Replicate for all ML operations (DINO, LLM, upscaling)
✅ **Image Storage**: WordPress uploads folder (`wp-content/uploads/centromex-products/`)
✅ **Source Images**: Keep in `wp-content/uploads/centromex-source/` for reference
✅ **Processing Limits**: 10 images max per batch
✅ **Implementation**: Pure PHP rewrite (no Ruby dependencies)

## Success Criteria

✅ Store owner can upload multiple shelf photos via drag & drop
✅ Processing happens in background (no page timeout)
✅ Progress displayed in real-time
✅ Products created as WooCommerce drafts
✅ Each product tagged as "verified" or "needs review"
✅ Idempotent - re-uploading same photo doesn't create duplicates
✅ Store owner reviews and publishes drafts manually
