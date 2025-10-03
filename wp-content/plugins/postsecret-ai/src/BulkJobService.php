<?php
/**
 * PSAI\BulkJobService
 * -----------------------------------------------------------------------------
 * Service for managing bulk upload jobs and items.
 *
 * Responsibilities:
 * - Create jobs from ZIP or image uploads
 * - Extract and index files
 * - Process items in batches
 * - Track status, progress, and errors
 * - Handle quarantine and retries
 *
 * @package PSAI
 */

declare(strict_types=1);

namespace PSAI;

if (!defined('ABSPATH')) {
    exit;
}

final class BulkJobService
{
    // Job statuses
    public const STATUS_NEW = 'new';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_STOPPED = 'stopped';

    // Item statuses
    public const ITEM_PENDING = 'pending';
    public const ITEM_PROCESSING = 'processing';
    public const ITEM_SUCCESS = 'success';
    public const ITEM_ERROR = 'error';
    public const ITEM_QUARANTINED = 'quarantined';
    public const ITEM_SKIPPED = 'skipped';

    /**
     * Create a new job from uploaded files.
     *
     * @param array $files $_FILES array
     * @return array{success: bool, job_id?: int, error?: string}
     */
    public static function create_job(array $files): array
    {
        global $wpdb;

        try {
            if (empty($files['psai_bulk_files'])) {
                return ['success' => false, 'error' => 'No files uploaded.'];
            }

            error_log('PSAI Bulk: Starting job creation');
            error_log('PSAI Bulk: Files structure: ' . print_r($files['psai_bulk_files'], true));

        // Create staging directory
        $upload_dir = wp_upload_dir();
        $staging_base = trailingslashit($upload_dir['basedir']) . 'psai-bulk-staging';
        wp_mkdir_p($staging_base);

        $job_uuid = wp_generate_uuid4();
        $staging_path = trailingslashit($staging_base) . sanitize_file_name($job_uuid);
        wp_mkdir_p($staging_path);

        // Handle multiple files
        $file_paths = [];
        $source = '';
        $is_zip = false;

        if (is_array($files['psai_bulk_files']['name'])) {
            // Multiple files
            $count = count($files['psai_bulk_files']['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($files['psai_bulk_files']['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }

                $filename = sanitize_file_name($files['psai_bulk_files']['name'][$i]);
                $tmp_name = $files['psai_bulk_files']['tmp_name'][$i];

                // Check if it's a ZIP
                if (pathinfo($filename, PATHINFO_EXTENSION) === 'zip') {
                    $is_zip = true;
                    $zip_path = trailingslashit($staging_path) . $filename;
                    move_uploaded_file($tmp_name, $zip_path);
                    $extracted = self::extract_zip($zip_path, $staging_path);
                    if (!$extracted['success']) {
                        return ['success' => false, 'error' => $extracted['error']];
                    }
                    $file_paths = array_merge($file_paths, $extracted['files']);
                    $source = 'zip:' . $filename;
                } else {
                    // Regular image file
                    $dest = trailingslashit($staging_path) . $filename;
                    move_uploaded_file($tmp_name, $dest);
                    $file_paths[] = $dest;
                }
            }

            if (!$is_zip) {
                $source = 'files:' . $count;
            }
        } else {
            // Single file
            $filename = sanitize_file_name($files['psai_bulk_files']['name']);
            $tmp_name = $files['psai_bulk_files']['tmp_name'];

            if (pathinfo($filename, PATHINFO_EXTENSION) === 'zip') {
                $is_zip = true;
                $zip_path = trailingslashit($staging_path) . $filename;
                move_uploaded_file($tmp_name, $zip_path);
                $extracted = self::extract_zip($zip_path, $staging_path);
                if (!$extracted['success']) {
                    return ['success' => false, 'error' => $extracted['error']];
                }
                $file_paths = $extracted['files'];
                $source = 'zip:' . $filename;
            } else {
                $dest = trailingslashit($staging_path) . $filename;
                move_uploaded_file($tmp_name, $dest);
                $file_paths[] = $dest;
                $source = 'file:' . $filename;
            }
        }

        if (empty($file_paths)) {
            return ['success' => false, 'error' => 'No valid files found.'];
        }

        // Filter to supported image formats
        $supported_exts = ['jpg', 'jpeg', 'png', 'webp'];
        $image_files = array_filter($file_paths, function($path) use ($supported_exts) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            return in_array($ext, $supported_exts, true);
        });

        if (empty($image_files)) {
            return ['success' => false, 'error' => 'No supported image files found (JPEG, PNG, WebP).'];
        }

        // Create job record
        $table_jobs = $wpdb->prefix . 'psai_bulk_jobs';
        $wpdb->insert($table_jobs, [
            'uuid' => $job_uuid,
            'status' => self::STATUS_NEW,
            'source' => $source,
            'staging_path' => $staging_path,
            'total_items' => count($image_files),
            'processed_items' => 0,
            'success_count' => 0,
            'fail_count' => 0,
            'settings' => wp_json_encode(['batch_size' => 25, 'max_step_time' => 8]),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        $job_id = (int)$wpdb->insert_id;

        // Compute hashes for all files first
        $file_hashes = [];
        foreach ($image_files as $file_path) {
            $relative_path = str_replace($staging_path, '', $file_path);
            $sha256 = @hash_file('sha256', $file_path) ?: '';
            $file_hashes[] = [
                'path' => $relative_path,
                'hash' => $sha256,
                'full_path' => $file_path,
            ];
        }

        // Batch-fetch existing hashes to avoid N queries
        $all_hashes = array_column($file_hashes, 'hash');
        $existing_hashes = [];

        if (!empty($all_hashes)) {
            $placeholders = implode(',', array_fill(0, count($all_hashes), '%s'));
            $query = $wpdb->prepare(
                "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = '_ps_sha256' AND meta_value IN ({$placeholders})",
                ...$all_hashes
            );
            $results = $wpdb->get_col($query);
            $existing_hashes = array_flip($results); // Use as lookup set
        }

        // Create item records
        $table_items = $wpdb->prefix . 'psai_bulk_items';
        foreach ($file_hashes as $file_data) {
            $status = isset($existing_hashes[$file_data['hash']]) ? self::ITEM_SKIPPED : self::ITEM_PENDING;

            $wpdb->insert($table_items, [
                'job_id' => $job_id,
                'file_path' => $file_data['path'],
                'sha256' => $file_data['hash'],
                'status' => $status,
                'attempts' => 0,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
        }

            error_log('PSAI Bulk: Job created successfully - ID: ' . $job_id);
            return ['success' => true, 'job_id' => $job_id];

        } catch (\Throwable $e) {
            $error_msg = 'Job creation failed: ' . $e->getMessage();
            error_log('PSAI Bulk Error: ' . $error_msg);
            error_log('PSAI Bulk Trace: ' . $e->getTraceAsString());
            return ['success' => false, 'error' => $error_msg];
        }
    }

    /**
     * Extract ZIP file and return image file paths.
     * Validates each entry to prevent Zip Slip attacks and ZIP bombs.
     *
     * @param string $zip_path Path to ZIP file
     * @param string $dest_path Destination directory
     * @return array{success: bool, files?: array, error?: string}
     */
    private static function extract_zip(string $zip_path, string $dest_path): array
    {
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'error' => 'ZipArchive class not available.'];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return ['success' => false, 'error' => 'Failed to open ZIP file.'];
        }

        // Normalize destination path for safe comparison
        $dest_real = realpath($dest_path);
        if ($dest_real === false) {
            $zip->close();
            return ['success' => false, 'error' => 'Invalid destination path.'];
        }

        // ZIP bomb protection thresholds
        $max_files = 5000;
        $max_total_bytes = 2 * 1024 * 1024 * 1024; // 2GB
        $cumulative_size = 0;
        $file_count = 0;

        $files = [];
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        // Manually extract each file after validation
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry_name = $zip->getNameIndex($i);
            if ($entry_name === false) {
                continue;
            }

            // Security checks for path traversal (Zip Slip)
            // 1. Reject absolute paths
            if (strpos($entry_name, '/') === 0 || preg_match('/^[a-zA-Z]:/', $entry_name)) {
                error_log("Bulk upload: Rejected absolute path in ZIP: {$entry_name}");
                continue;
            }

            // 2. Reject directory traversal sequences
            if (strpos($entry_name, '../') !== false || strpos($entry_name, '..\\') !== false) {
                error_log("Bulk upload: Rejected directory traversal in ZIP: {$entry_name}");
                continue;
            }

            // 3. Reject control characters and null bytes
            if (preg_match('/[\x00-\x1F\x7F]/', $entry_name)) {
                error_log("Bulk upload: Rejected entry with control characters: {$entry_name}");
                continue;
            }

            // 4. Normalize and verify final path stays within destination
            $target_path = $dest_path . DIRECTORY_SEPARATOR . $entry_name;
            $target_real = realpath(dirname($target_path));

            // If parent directory doesn't exist yet, create it safely
            if ($target_real === false) {
                $parent_dir = dirname($target_path);
                if (!wp_mkdir_p($parent_dir)) {
                    error_log("Bulk upload: Failed to create directory: {$parent_dir}");
                    continue;
                }
                $target_real = realpath($parent_dir);
            }

            // Verify the target is within destination directory
            if ($target_real === false || strpos($target_real, $dest_real) !== 0) {
                error_log("Bulk upload: Rejected path outside destination: {$entry_name}");
                continue;
            }

            // Skip directories
            if (substr($entry_name, -1) === '/') {
                continue;
            }

            // Only extract allowed image file types
            $ext = strtolower(pathinfo($entry_name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_extensions, true)) {
                continue;
            }

            // Extract the file
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                error_log("Bulk upload: Failed to read entry: {$entry_name}");
                continue;
            }

            $content_size = strlen($content);

            // ZIP bomb protection: check cumulative size and file count
            $cumulative_size += $content_size;
            $file_count++;

            if ($file_count > $max_files) {
                $zip->close();
                error_log("Bulk upload: ZIP bomb protection - exceeded max files ({$max_files})");
                return ['success' => false, 'error' => "ZIP contains too many files (max {$max_files})."];
            }

            if ($cumulative_size > $max_total_bytes) {
                $zip->close();
                $max_gb = round($max_total_bytes / (1024 * 1024 * 1024), 1);
                error_log("Bulk upload: ZIP bomb protection - exceeded max size ({$max_gb}GB)");
                return ['success' => false, 'error' => "ZIP decompressed size exceeds {$max_gb}GB limit."];
            }

            if (file_put_contents($target_path, $content) !== false) {
                $files[] = $target_path;
            } else {
                error_log("Bulk upload: Failed to write file: {$target_path}");
            }
        }

        $zip->close();

        return ['success' => true, 'files' => $files];
    }

    /**
     * Get job by ID.
     *
     * @param int $job_id
     * @return array|null
     */
    public static function get_job(int $job_id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'psai_bulk_jobs';
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $job_id), ARRAY_A);
        return $job ?: null;
    }

    /**
     * List recent jobs.
     *
     * @param int $limit
     * @return array
     */
    public static function list_jobs(int $limit = 50): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'psai_bulk_jobs';
        $jobs = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit),
            ARRAY_A
        );
        return $jobs ?: [];
    }

    /**
     * Update job status.
     *
     * @param int $job_id
     * @param string $status
     * @return bool
     */
    public static function update_job_status(int $job_id, string $status): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'psai_bulk_jobs';
        $result = $wpdb->update($table, [
            'status' => $status,
            'updated_at' => current_time('mysql'),
        ], ['id' => $job_id]);

        // Set started_at if transitioning to running
        if ($status === self::STATUS_RUNNING) {
            $job = self::get_job($job_id);
            if ($job && !$job['started_at']) {
                $wpdb->update($table, [
                    'started_at' => current_time('mysql'),
                ], ['id' => $job_id]);
            }
        }

        return $result !== false;
    }

    /**
     * Process a batch of items for a job.
     *
     * @param int $job_id
     * @param int $batch_size
     * @return array{success: bool, processed: int, status: string, has_more: bool}
     */
    public static function process_batch(int $job_id, int $batch_size = 25): array
    {
        global $wpdb;

        $job = self::get_job($job_id);
        if (!$job) {
            return ['success' => false, 'processed' => 0, 'status' => 'unknown', 'has_more' => false];
        }

        // Only process if job is running
        if ($job['status'] !== self::STATUS_RUNNING) {
            return ['success' => true, 'processed' => 0, 'status' => $job['status'], 'has_more' => false];
        }

        $table_items = $wpdb->prefix . 'psai_bulk_items';

        // Get pending items
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_items} WHERE job_id = %d AND status = %s ORDER BY id ASC LIMIT %d",
            $job_id,
            self::ITEM_PENDING,
            $batch_size
        ), ARRAY_A);

        if (empty($items)) {
            // No more items, mark job as completed
            self::update_job_status($job_id, self::STATUS_COMPLETED);
            return ['success' => true, 'processed' => 0, 'status' => self::STATUS_COMPLETED, 'has_more' => false];
        }

        $processed = 0;
        $success_count = 0;
        $fail_count = 0;

        foreach ($items as $item) {
            // Mark as processing
            $wpdb->update($table_items, [
                'status' => self::ITEM_PROCESSING,
                'updated_at' => current_time('mysql'),
            ], ['id' => $item['id']]);

            // Attempt to process
            $result = self::process_item($job, $item);

            if ($result['success']) {
                $wpdb->update($table_items, [
                    'status' => self::ITEM_SUCCESS,
                    'attachment_id' => $result['attachment_id'],
                    'updated_at' => current_time('mysql'),
                ], ['id' => $item['id']]);
                $success_count++;
            } else {
                $attempts = (int)$item['attempts'] + 1;
                $status = $attempts >= 3 ? self::ITEM_QUARANTINED : self::ITEM_ERROR;

                $wpdb->update($table_items, [
                    'status' => $status,
                    'attempts' => $attempts,
                    'last_error' => substr($result['error'], 0, 500),
                    'updated_at' => current_time('mysql'),
                ], ['id' => $item['id']]);
                $fail_count++;
            }

            $processed++;

            // Check if job was paused/stopped during processing
            $current_job = self::get_job($job_id);
            if ($current_job['status'] !== self::STATUS_RUNNING) {
                break;
            }
        }

        // Update job counts
        $table_jobs = $wpdb->prefix . 'psai_bulk_jobs';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_jobs} SET
                processed_items = processed_items + %d,
                success_count = success_count + %d,
                fail_count = fail_count + %d,
                updated_at = %s
            WHERE id = %d",
            $processed,
            $success_count,
            $fail_count,
            current_time('mysql'),
            $job_id
        ));

        // Check if there are more items
        $remaining = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_items} WHERE job_id = %d AND status = %s",
            $job_id,
            self::ITEM_PENDING
        ));

        $has_more = $remaining > 0;

        // Update final job status
        $final_job = self::get_job($job_id);
        $status = $final_job['status'];

        if (!$has_more && $status === self::STATUS_RUNNING) {
            self::update_job_status($job_id, self::STATUS_COMPLETED);
            $status = self::STATUS_COMPLETED;
        }

        return [
            'success' => true,
            'processed' => $processed,
            'status' => $status,
            'has_more' => $has_more
        ];
    }

    /**
     * Process a single item.
     *
     * @param array $job
     * @param array $item
     * @return array{success: bool, attachment_id?: int, error?: string}
     */
    private static function process_item(array $job, array $item): array
    {
        try {
            // Validate and sanitize file path to prevent directory traversal
            $staging_base = trailingslashit($job['staging_path']);
            $staging_real = realpath($staging_base);

            if ($staging_real === false) {
                return ['success' => false, 'error' => 'Invalid staging directory.'];
            }

            // Concatenate the paths
            $file_path = $staging_base . $item['file_path'];

            // Resolve the actual path
            $file_real = realpath($file_path);

            // Security: Verify the resolved path is within staging directory
            if ($file_real === false || strpos($file_real, $staging_real) !== 0) {
                error_log("Bulk upload: Path traversal attempt blocked - file_path: {$item['file_path']}");
                return ['success' => false, 'error' => 'Invalid file path (security check failed).'];
            }

            if (!file_exists($file_real)) {
                return ['success' => false, 'error' => 'File not found: ' . basename($item['file_path'])];
            }

            // Use the validated path for all subsequent operations
            $file_path = $file_real;

            // Sideload file
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $file_array = [
                'name' => basename($file_path),
                'tmp_name' => $file_path,
                'error' => 0,
                'size' => filesize($file_path),
            ];

            $att_id = media_handle_sideload($file_array, 0);

            if (is_wp_error($att_id)) {
                return ['success' => false, 'error' => $att_id->get_error_message()];
            }

            // Mark as front, index
            update_post_meta($att_id, '_ps_side', 'front');
            update_post_meta($att_id, '_ps_bulk_job_id', $job['id']);
            Ingress::index($att_id);

            // Classify and store
            $result = ClassificationService::classify_and_store($att_id, null, false);

            if (!$result['success']) {
                return ['success' => false, 'error' => $result['error'] ?? 'Classification failed.'];
            }

            return ['success' => true, 'attachment_id' => $att_id];

        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get error items for a job.
     *
     * @param int $job_id
     * @param int $limit
     * @return array
     */
    public static function get_errors(int $job_id, int $limit = 100): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'psai_bulk_items';
        $errors = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE job_id = %d AND (status = %s OR status = %s) ORDER BY updated_at DESC LIMIT %d",
            $job_id,
            self::ITEM_ERROR,
            self::ITEM_QUARANTINED,
            $limit
        ), ARRAY_A);
        return $errors ?: [];
    }

    /**
     * Retry failed items.
     *
     * @param int $job_id
     * @return int Number of items requeued
     */
    public static function retry_failed(int $job_id): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'psai_bulk_items';
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, attempts = 0, last_error = NULL, updated_at = %s
             WHERE job_id = %d AND (status = %s OR status = %s)",
            self::ITEM_PENDING,
            current_time('mysql'),
            $job_id,
            self::ITEM_ERROR,
            self::ITEM_QUARANTINED
        ));
        return $result ?: 0;
    }

    /**
     * Delete a job and its items.
     *
     * @param int $job_id
     * @return bool
     */
    public static function delete_job(int $job_id): bool
    {
        global $wpdb;

        $job = self::get_job($job_id);
        if (!$job) {
            return false;
        }

        // Delete items
        $table_items = $wpdb->prefix . 'psai_bulk_items';
        $wpdb->delete($table_items, ['job_id' => $job_id]);

        // Delete job
        $table_jobs = $wpdb->prefix . 'psai_bulk_jobs';
        $wpdb->delete($table_jobs, ['id' => $job_id]);

        // Clean up staging directory
        if ($job['staging_path'] && is_dir($job['staging_path'])) {
            self::delete_directory($job['staging_path']);
        }

        return true;
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $dir
     */
    private static function delete_directory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? self::delete_directory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Save job settings.
     *
     * @param int $job_id
     * @param array $settings
     * @return bool
     */
    public static function save_settings(int $job_id, array $settings): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'psai_bulk_jobs';
        $result = $wpdb->update($table, [
            'settings' => wp_json_encode($settings),
            'updated_at' => current_time('mysql'),
        ], ['id' => $job_id]);
        return $result !== false;
    }

    /**
     * Export errors to CSV.
     *
     * @param int $job_id
     * @return string CSV content
     */
    public static function export_errors_csv(int $job_id): string
    {
        $errors = self::get_errors($job_id, 10000);

        $csv = "Item ID,File Path,Status,Attempts,Last Error,Last Updated\n";
        foreach ($errors as $error) {
            $csv .= sprintf(
                "%d,\"%s\",\"%s\",%d,\"%s\",\"%s\"\n",
                $error['id'],
                $error['file_path'],
                $error['status'],
                $error['attempts'],
                str_replace('"', '""', $error['last_error'] ?? ''),
                $error['updated_at']
            );
        }

        return $csv;
    }
}
