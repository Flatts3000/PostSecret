<?php
/**
 * Bulk Upload Debug Page
 * Shows recent errors and allows test uploads
 */

require_once __DIR__ . '/../../../wp-load.php';

if (!current_user_can('manage_options')) {
    wp_die('Unauthorized');
}

// Enable error display
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Bulk Upload Debug</h1>\n";
echo "<style>body{font-family:sans-serif;padding:20px;} pre{background:#f5f5f5;padding:10px;overflow:auto;} .error{color:red;} .ok{color:green;}</style>\n";

// Check if we're processing a test upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['test_file'])) {
    echo "<h2>Processing Upload...</h2>\n";
    echo "<pre>";

    try {
        echo "Files received:\n";
        print_r($_FILES);
        echo "\n\n";

        echo "Calling BulkJobService::create_job()...\n";
        $result = \PSAI\BulkJobService::create_job($_FILES);

        echo "\n\nResult:\n";
        print_r($result);

        if ($result['success']) {
            echo "\n\n<span class='ok'>✓ SUCCESS! Job ID: {$result['job_id']}</span>\n";
        } else {
            echo "\n\n<span class='error'>✗ FAILED: {$result['error']}</span>\n";
        }
    } catch (\Throwable $e) {
        echo "\n\n<span class='error'>✗ EXCEPTION: {$e->getMessage()}</span>\n\n";
        echo "Trace:\n{$e->getTraceAsString()}\n";
    }

    echo "</pre>";
    echo "<hr>\n";
}

// Check transient error
$transient_error = get_transient('_ps_bulk_error');
if ($transient_error) {
    echo "<div style='background:#fee;padding:10px;border:1px solid #c00;'>";
    echo "<strong>Last Error:</strong> " . esc_html($transient_error);
    echo "</div><br>\n";
}

// Check database tables
global $wpdb;
$table_jobs = $wpdb->prefix . 'psai_bulk_jobs';
$table_items = $wpdb->prefix . 'psai_bulk_items';

echo "<h2>Database Status</h2>\n";
$jobs_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_jobs}'") === $table_jobs;
$items_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_items}'") === $table_items;

echo "<p>Jobs table: " . ($jobs_exists ? "<span class='ok'>EXISTS</span>" : "<span class='error'>NOT FOUND</span>") . "</p>\n";
echo "<p>Items table: " . ($items_exists ? "<span class='ok'>EXISTS</span>" : "<span class='error'>NOT FOUND</span>") . "</p>\n";

if ($jobs_exists) {
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_jobs}");
    echo "<p>Total jobs: {$count}</p>\n";

    if ($count > 0) {
        echo "<h3>Recent Jobs:</h3>\n";
        $jobs = $wpdb->get_results("SELECT * FROM {$table_jobs} ORDER BY created_at DESC LIMIT 5", ARRAY_A);
        echo "<pre>" . print_r($jobs, true) . "</pre>\n";
    }
}

// Test upload form
echo "<hr>\n";
echo "<h2>Test Upload</h2>\n";
echo "<form method='post' enctype='multipart/form-data'>\n";
echo "<p><input type='file' name='test_file[]' accept='.zip,.jpg,.jpeg,.png,.webp' multiple /></p>\n";
echo "<p><button type='submit' class='button button-primary'>Test Upload</button></p>\n";
echo "</form>\n";

// Check upload directory
echo "<hr>\n";
echo "<h2>Upload Directory</h2>\n";
$upload_dir = wp_upload_dir();
$staging_base = trailingslashit($upload_dir['basedir']) . 'psai-bulk-staging';

echo "<p>Upload base: <code>{$upload_dir['basedir']}</code></p>\n";
echo "<p>Staging dir: <code>{$staging_base}</code></p>\n";
echo "<p>Staging exists: " . (is_dir($staging_base) ? "<span class='ok'>YES</span>" : "NO (will be created)") . "</p>\n";
echo "<p>Parent writable: " . (is_writable($upload_dir['basedir']) ? "<span class='ok'>YES</span>" : "<span class='error'>NO</span>") . "</p>\n";

// Check PHP settings
echo "<hr>\n";
echo "<h2>PHP Settings</h2>\n";
echo "<p>upload_max_filesize: " . ini_get('upload_max_filesize') . "</p>\n";
echo "<p>post_max_size: " . ini_get('post_max_size') . "</p>\n";
echo "<p>max_file_uploads: " . ini_get('max_file_uploads') . "</p>\n";
echo "<p>ZipArchive available: " . (class_exists('ZipArchive') ? "<span class='ok'>YES</span>" : "<span class='error'>NO</span>") . "</p>\n";

echo "<hr>\n";
echo "<p><a href='" . admin_url('admin.php?page=psai_bulk_upload') . "'>← Back to Bulk Upload</a></p>\n";
