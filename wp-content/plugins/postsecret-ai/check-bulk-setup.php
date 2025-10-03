<?php
/**
 * Diagnostic: Check if bulk upload tables exist
 *
 * Visit this file to verify database setup
 */

require_once __DIR__ . '/../../../wp-load.php';

if (!current_user_can('manage_options')) {
    wp_die('Unauthorized');
}

global $wpdb;

echo "<h1>PostSecret Bulk Upload - Setup Check</h1>\n";
echo "<style>body{font-family:sans-serif;padding:20px;} .ok{color:green;} .error{color:red;} pre{background:#f5f5f5;padding:10px;}</style>\n";

// Check tables
$table_jobs = $wpdb->prefix . 'psai_bulk_jobs';
$table_items = $wpdb->prefix . 'psai_bulk_items';

echo "<h2>Database Tables</h2>\n";

$jobs_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_jobs}'") === $table_jobs;
$items_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_items}'") === $table_items;

if ($jobs_exists) {
    echo "<p class='ok'>✓ Table <code>{$table_jobs}</code> exists</p>\n";
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_jobs}");
    echo "<p>Jobs in table: {$count}</p>\n";

    if ($count > 0) {
        echo "<h3>Recent Jobs:</h3>\n";
        $jobs = $wpdb->get_results("SELECT * FROM {$table_jobs} ORDER BY created_at DESC LIMIT 5", ARRAY_A);
        echo "<pre>" . print_r($jobs, true) . "</pre>\n";
    }
} else {
    echo "<p class='error'>✗ Table <code>{$table_jobs}</code> does NOT exist</p>\n";
    echo "<p><strong>Action needed:</strong> Run migrations at <a href='" . plugins_url('postsecret-admin/run-migrations.php') . "'>postsecret-admin/run-migrations.php</a></p>\n";
}

if ($items_exists) {
    echo "<p class='ok'>✓ Table <code>{$table_items}</code> exists</p>\n";
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_items}");
    echo "<p>Items in table: {$count}</p>\n";
} else {
    echo "<p class='error'>✗ Table <code>{$table_items}</code> does NOT exist</p>\n";
    echo "<p><strong>Action needed:</strong> Run migrations at <a href='" . plugins_url('postsecret-admin/run-migrations.php') . "'>postsecret-admin/run-migrations.php</a></p>\n";
}

// Check upload directory
echo "<h2>Upload Directory</h2>\n";
$upload_dir = wp_upload_dir();
$staging_base = trailingslashit($upload_dir['basedir']) . 'psai-bulk-staging';

if (is_dir($staging_base)) {
    echo "<p class='ok'>✓ Staging directory exists: <code>{$staging_base}</code></p>\n";
    echo "<p>Writable: " . (is_writable($staging_base) ? '<span class="ok">Yes</span>' : '<span class="error">No</span>') . "</p>\n";
} else {
    echo "<p>Staging directory will be created on first upload: <code>{$staging_base}</code></p>\n";
    echo "<p>Parent writable: " . (is_writable($upload_dir['basedir']) ? '<span class="ok">Yes</span>' : '<span class="error">No</span>') . "</p>\n";
}

// Test AJAX endpoint
echo "<h2>AJAX Endpoints</h2>\n";
echo "<p>Testing <code>psai_bulk_list_jobs</code>...</p>\n";

$_REQUEST['action'] = 'psai_bulk_list_jobs';
ob_start();
do_action('wp_ajax_psai_bulk_list_jobs');
$response = ob_get_clean();

echo "<p>Response:</p>\n";
echo "<pre>" . esc_html($response) . "</pre>\n";

if ($jobs_exists && $items_exists) {
    echo "<h2>✓ Setup Complete</h2>\n";
    echo "<p><a href='" . admin_url('admin.php?page=psai_bulk_upload') . "'>Go to Bulk Upload Page</a></p>\n";
} else {
    echo "<h2>Setup Required</h2>\n";
    echo "<p><strong>1. Run migrations:</strong> <a href='" . plugins_url('postsecret-admin/run-migrations.php') . "'>Click here to run migrations</a></p>\n";
    echo "<p><strong>2. Refresh this page</strong> to verify setup</p>\n";
}

echo "<hr>\n";
echo "<p><a href='" . admin_url() . "'>← Back to Dashboard</a></p>\n";
