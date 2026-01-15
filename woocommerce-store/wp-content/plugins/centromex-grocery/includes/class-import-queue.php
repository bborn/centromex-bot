<?php
/**
 * Import Queue
 * Wraps WordPress Action Scheduler for async image processing
 *
 * @package Centromex_Grocery
 */

if (!defined('ABSPATH')) {
    exit;
}

class Centromex_Import_Queue {

    const HOOK_PROCESS_IMAGE = 'centromex_process_image';
    const HOOK_COMPLETE_BATCH = 'centromex_complete_batch';
    const GROUP = 'centromex-import';

    /**
     * Initialize hooks
     */
    public static function init() {
        add_action(self::HOOK_PROCESS_IMAGE, [__CLASS__, 'handle_process_image'], 10, 1);
        add_action(self::HOOK_COMPLETE_BATCH, [__CLASS__, 'handle_complete_batch'], 10, 1);
    }

    /**
     * Queue image for processing
     *
     * @param string $image_path Path to image file
     * @param string $image_hash Image hash
     * @param string $batch_id Batch identifier
     * @return int Action ID
     */
    public static function queue_image($image_path, $image_hash, $batch_id) {
        if (!function_exists('as_enqueue_async_action')) {
            throw new Exception('Action Scheduler not available');
        }

        return as_enqueue_async_action(
            self::HOOK_PROCESS_IMAGE,
            [
                'image_path' => $image_path,
                'image_hash' => $image_hash,
                'batch_id' => $batch_id
            ],
            self::GROUP
        );
    }

    /**
     * Queue batch completion
     *
     * @param string $batch_id Batch identifier
     * @return int Action ID
     */
    public static function queue_batch_complete($batch_id) {
        if (!function_exists('as_schedule_single_action')) {
            throw new Exception('Action Scheduler not available');
        }

        // Schedule 30 seconds in the future to give time for all images to queue
        return as_schedule_single_action(
            time() + 30,
            self::HOOK_COMPLETE_BATCH,
            ['batch_id' => $batch_id],
            self::GROUP
        );
    }

    /**
     * Handle image processing job
     *
     * @param array $args Job arguments
     */
    public static function handle_process_image($args) {
        try {
            $image_path = $args['image_path'];
            $image_hash = $args['image_hash'];
            $batch_id = $args['batch_id'];

            error_log("Centromex: Starting job for image: " . basename($image_path));

            $importer = new Centromex_Photo_Importer();
            $result = $importer->process_image($image_path, $image_hash, $batch_id);

            if ($result['success']) {
                error_log("Centromex: Job completed - Created: {$result['products_created']}, Skipped: {$result['products_skipped']}");
            } else {
                error_log("Centromex: Job failed with errors: " . implode(', ', $result['errors']));
            }

        } catch (Exception $e) {
            error_log("Centromex: Job exception: " . $e->getMessage());
            throw $e; // Re-throw for Action Scheduler retry
        }
    }

    /**
     * Handle batch completion
     *
     * @param array $args Job arguments
     */
    public static function handle_complete_batch($args) {
        $batch_id = $args['batch_id'];

        error_log("Centromex: Completing batch: $batch_id");

        $importer = new Centromex_Photo_Importer();
        $importer->complete_batch($batch_id);
    }

    /**
     * Get queue status
     *
     * @param string $batch_id Optional batch ID filter
     * @return array Status info
     */
    public static function get_queue_status($batch_id = null) {
        if (!function_exists('as_get_scheduled_actions')) {
            return ['total' => 0, 'pending' => 0, 'running' => 0, 'complete' => 0, 'failed' => 0];
        }

        $args = [
            'group' => self::GROUP,
            'per_page' => -1
        ];

        $actions = as_get_scheduled_actions($args, 'ids');

        $status = [
            'total' => count($actions),
            'pending' => 0,
            'running' => 0,
            'complete' => 0,
            'failed' => 0
        ];

        foreach ($actions as $action_id) {
            $action_status = ActionScheduler::store()->fetch_action($action_id)->get_status();

            if (isset($status[$action_status])) {
                $status[$action_status]++;
            }
        }

        return $status;
    }

    /**
     * Cancel all pending jobs for a batch
     *
     * @param string $batch_id
     * @return int Number of actions cancelled
     */
    public static function cancel_batch($batch_id) {
        if (!function_exists('as_unschedule_all_actions')) {
            return 0;
        }

        $cancelled = 0;

        // Cancel image processing jobs
        as_unschedule_all_actions(self::HOOK_PROCESS_IMAGE, ['batch_id' => $batch_id], self::GROUP);
        $cancelled++;

        // Cancel batch completion job
        as_unschedule_all_actions(self::HOOK_COMPLETE_BATCH, ['batch_id' => $batch_id], self::GROUP);
        $cancelled++;

        error_log("Centromex: Cancelled batch $batch_id");

        return $cancelled;
    }
}

// Initialize hooks
Centromex_Import_Queue::init();
