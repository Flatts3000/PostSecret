<?php
/**
 * Plugin Name: PostSecret AI (Ultra-MVP)
 * Description: Admin-only tools for classification: test console, single uploader, and bulk uploader.
 * Version: 0.0.6
 *
 * SETUP:
 * - Bulk upload requires database tables. Run migrations from postsecret-admin plugin:
 *   Visit: /wp-content/plugins/postsecret-admin/run-migrations.php
 */
if (!defined('ABSPATH')) exit;

define('PSAI_SLUG', 'postsecret-ai');
define('PSAI_VERSION', '0.0.6');

// Core
require __DIR__ . '/src/Prompt.php';
require __DIR__ . '/src/Schema.php';
require __DIR__ . '/src/SchemaGuard.php';
require __DIR__ . '/src/Classifier.php';
require __DIR__ . '/src/EmbeddingService.php';
require __DIR__ . '/src/ClassificationService.php';

// Utilities
require __DIR__ . '/src/Metadata.php';
require __DIR__ . '/src/AttachmentSync.php';
require __DIR__ . '/src/Ingress.php';

// Bulk Upload
require __DIR__ . '/src/BulkJobService.php';

// Admin
require __DIR__ . '/src/Settings.php';
require __DIR__ . '/src/AdminPage.php';
require __DIR__ . '/src/AdminSingleUpload.php';
require __DIR__ . '/src/AdminBulkUpload.php';
require __DIR__ . '/src/AdminBulkReclassify.php';
require __DIR__ . '/src/AdminMetaBox.php';
require __DIR__ . '/src/AdminPromptEditor.php';

/* ---------------------------------------------------------------------------
 * Small helpers: set/clear last error consistently on attachments
 * ------------------------------------------------------------------------- */
if (!function_exists('psai_set_last_error')) {
    /**
     * Store a trimmed error string to _ps_last_error on one or more attachment IDs.
     * @param int|array<int> $ids
     * @param string $message
     */
    function psai_set_last_error($ids, string $message): void
    {
        $msg = substr(trim($message), 0, 500);
        $ids = is_array($ids) ? $ids : [$ids];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                update_post_meta($id, '_ps_last_error', $msg);
            }
        }
    }
}
if (!function_exists('psai_clear_last_error')) {
    /**
     * Remove _ps_last_error from one or more attachment IDs.
     * @param int|array<int> $ids
     */
    function psai_clear_last_error($ids): void
    {
        $ids = is_array($ids) ? $ids : [$ids];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                delete_post_meta($id, '_ps_last_error');
            }
        }
    }
}

/* ---------------------------------------------------------------------------
 * Menus
 * - Tools → PostSecret AI (tester/settings)
 * - Postcards → Upload Single (two-slot uploader for Frank)
 * ------------------------------------------------------------------------- */
add_action('admin_menu', function () {
    // Existing tester under Tools
    add_management_page(
        'PostSecret AI',
        'PostSecret AI',
        'manage_options',
        PSAI_SLUG,
        ['PSAI\\AdminPage', 'render']
    );

    // NEW top-level "Postcards" + "Upload Single"
    add_menu_page(
        'Postcards',
        'Postcards',
        'upload_files',
        'psai_postcards',
        function () {
            echo '<div class="wrap"><h1>Postcards</h1><p>Choose a submenu: Upload Single.</p></div>';
        },
        'dashicons-format-image',
        25
    );

    add_submenu_page(
        'psai_postcards',
        'Upload Single',
        'Upload Single',
        'upload_files',
        'psai_upload_single',
        ['PSAI\\AdminSingleUpload', 'render']
    );

    add_submenu_page(
        'psai_postcards',
        'Bulk Upload',
        'Bulk Upload',
        'manage_options',
        'psai_bulk_upload',
        ['PSAI\\AdminBulkUpload', 'render']
    );

    add_submenu_page(
        'psai_postcards',
        'Bulk Reclassify',
        'Bulk Reclassify',
        'manage_options',
        'psai_bulk_reclassify',
        ['PSAI\\AdminBulkReclassify', 'render']
    );

    add_submenu_page(
        'psai_postcards',
        'Prompt Editor',
        'Prompt Editor',
        'manage_options',
        'psai_prompt_editor',
        ['PSAI\\AdminPromptEditor', 'render']
    );
});

/* Settings (tester page) */
add_action('admin_init', ['PSAI\\Settings', 'register']);

/* ---------------------------------------------------------------------------
 * Tester handler (Tools page) — URL-based single image test
 * NOTE: This tester does not create attachments; we keep using transients
 * but also store a global “last error” transient for quick feedback.
 * ------------------------------------------------------------------------- */
add_action('admin_post_psai_classify', function () {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);
    check_admin_referer('psai_classify');

    $env = get_option(\PSAI\Settings::OPTION, \PSAI\Settings::defaults());
    $api = $env['API_KEY'] ?? '';
    $model = $env['MODEL_NAME'] ?? 'gpt-4o-mini';
    $image = isset($_POST['psai_image_url']) ? esc_url_raw(trim($_POST['psai_image_url'])) : '';

    if (!$api || !$image) {
        set_transient('_ps_last_error_global', !$api ? 'Missing API key.' : 'Image URL is required.', 600);
        $q = ['page' => PSAI_SLUG, 'psai_err' => !$api ? 'no_key' : 'no_image'];
        wp_redirect(add_query_arg($q, admin_url('tools.php')));
        exit;
    }

    try {
        $payload = \PSAI\Classifier::classify($api, $model, $image, null);

        set_transient('psai_last_result', [
            'image_url' => $image,
            'model' => $model,
            'payload' => $payload,
            'ts' => time(),
        ], 600);

        delete_transient('_ps_last_error_global');

        wp_redirect(add_query_arg(['page' => PSAI_SLUG, 'psai_done' => '1'], admin_url('tools.php')));
        exit;
    } catch (\Throwable $e) {
        $msg = substr($e->getMessage(), 0, 500);
        set_transient('_ps_last_error_global', $msg, 600);
        set_transient('psai_last_error', $msg, 300);
        wp_redirect(add_query_arg(['page' => PSAI_SLUG, 'psai_err' => 'call_failed'], admin_url('tools.php')));
        exit;
    }
});

/* ---------------------------------------------------------------------------
 * Admin: Upload Single (front required, back optional)
 * - sideloads to Media
 * - indexes + pairs
 * - queues & triggers classification
 * ------------------------------------------------------------------------- */
add_action('admin_post_psai_upload_single', function () {
    if (!current_user_can('upload_files')) wp_die('Not allowed', 403);
    check_admin_referer('psai_upload_single');

    $front_id = null;
    $back_id = null;
    $had_dupe = false;

    // 1) FRONT (required)
    if (empty($_FILES['psai_front']['name'])) {
        set_transient('_ps_last_error_global', 'Front image is required.', 600);
        wp_redirect(add_query_arg(['page' => 'psai_upload_single', 'psai_msg' => 'err'], admin_url('admin.php')));
        exit;
    }

    $front_id = \PSAI\Ingress::sideload($_FILES['psai_front'], 'front');
    if (!$front_id) {
        set_transient('_ps_last_error_global', 'Failed to import front image.', 600);
        wp_redirect(add_query_arg(['page' => 'psai_upload_single', 'psai_msg' => 'err'], admin_url('admin.php')));
        exit;
    }

    if (\PSAI\Ingress::mark_exact_duplicate($front_id)) $had_dupe = true;

    // 2) BACK (optional)
    if (!empty($_FILES['psai_back']['name'])) {
        $back_id = \PSAI\Ingress::sideload($_FILES['psai_back'], 'back');
        if ($back_id) {
            \PSAI\Ingress::pair($front_id, $back_id);
            if (\PSAI\Ingress::mark_exact_duplicate($back_id)) $had_dupe = true;
        } else {
            // record on the front if back import failed
            psai_set_last_error($front_id, 'Failed to import back image.');
        }
    }

    // 3) Queue pair processing (and also run immediately once)
    if (!get_post_meta($front_id, '_ps_duplicate_of', true)) {
        wp_schedule_single_event(time() + 5, 'psai_process_pair_event', [$front_id, (int)($back_id ?? 0)]);
        do_action('psai_process_pair_event', $front_id, (int)($back_id ?? 0));
    }

    $msg = $had_dupe ? 'dupe' : 'ok';
    wp_redirect(add_query_arg(['page' => 'psai_upload_single', 'psai_msg' => $msg], admin_url('admin.php')));
    exit;
});

/* ---------------------------------------------------------------------------
 * Background (and on-demand) processor for a front/back pair
 * ------------------------------------------------------------------------- */
add_action('psai_process_pair_event', function ($front_id, $back_id = 0) {
    $front_id = (int)$front_id;
    $back_id = (int)$back_id ?: null;

    \PSAI\ClassificationService::classify_and_store($front_id, $back_id, false);
}, 10, 2);

/* ---------------------------------------------------------------------------
 * "Process now" button on the attachment edit screen
 * ------------------------------------------------------------------------- */
add_action('admin_post_psai_process_now', function () {
    if (!current_user_can('upload_files')) wp_die('Not allowed', 403);

    $att = isset($_GET['att']) ? (int)$_GET['att'] : 0;
    check_admin_referer('psai_process_now_' . $att);
    if (!$att || get_post_type($att) !== 'attachment') {
        wp_redirect(admin_url('upload.php?psai_msg=bad_id'));
        exit;
    }

    $result = \PSAI\ClassificationService::process_attachment($att, false);

    if ($result['success']) {
        $url = add_query_arg(['psai_msg' => 'ok'], get_edit_post_link($att, ''));
        wp_safe_redirect($url ?: admin_url('upload.php?psai_msg=ok'));
    } else {
        $url = add_query_arg(['psai_msg' => 'err'], get_edit_post_link($att, ''));
        wp_safe_redirect($url ?: admin_url('upload.php?psai_msg=err'));
    }
    exit;
});

/* -------------------------------------------------------------------------
 * Re-classify action: Force new AI classification regardless of existing data
 * ------------------------------------------------------------------------- */
add_action('admin_post_psai_reclassify', function () {
    if (!current_user_can('upload_files')) wp_die('Not allowed', 403);

    $att = isset($_GET['att']) ? (int)$_GET['att'] : 0;
    check_admin_referer('psai_reclassify_' . $att);
    if (!$att || get_post_type($att) !== 'attachment') {
        wp_redirect(admin_url('upload.php?psai_msg=bad_id'));
        exit;
    }

    $result = \PSAI\ClassificationService::process_attachment($att, true);

    if ($result['success']) {
        // Check if there was a partial error (embedding failed but classification succeeded)
        $front_id = $att;
        $side = get_post_meta($att, '_ps_side', true);
        $maybePair = (int)get_post_meta($att, '_ps_pair_id', true);
        if ($side === 'back' && $maybePair) {
            $front_id = $maybePair;
        }

        $last_error = get_post_meta($front_id, '_ps_last_error', true);
        if ($last_error && str_contains($last_error, 'embedding')) {
            $url = add_query_arg(['psai_msg' => 'partial_err'], get_edit_post_link($att, ''));
            wp_safe_redirect($url ?: admin_url('upload.php?psai_msg=partial_err'));
        } else {
            $url = add_query_arg(['psai_msg' => 'reclassified'], get_edit_post_link($att, ''));
            wp_safe_redirect($url ?: admin_url('upload.php?psai_msg=reclassified'));
        }
    } else {
        $url = add_query_arg(['psai_msg' => 'err'], get_edit_post_link($att, ''));
        wp_safe_redirect($url ?: admin_url('upload.php?psai_msg=err'));
    }
    exit;
});

/* Small admin notice so you know the button worked */
add_action('admin_notices', function () {
    if (!is_admin() || !isset($_GET['psai_msg'])) return;
    $msg = sanitize_text_field($_GET['psai_msg']);
    if ($msg === 'ok') {
        echo '<div class="notice notice-success is-dismissible"><p>PostSecret AI: Attachment normalized.</p></div>';
    } elseif ($msg === 'reclassified') {
        echo '<div class="notice notice-success is-dismissible"><p>PostSecret AI: Attachment re-classified with latest AI model and prompt.</p></div>';
    } elseif ($msg === 'partial_err') {
        echo '<div class="notice notice-warning is-dismissible"><p>PostSecret AI: Classification completed but embedding generation failed. Check the meta box for details.</p></div>';
    } elseif ($msg === 'err') {
        echo '<div class="notice notice-error is-dismissible"><p>PostSecret AI: There was an error. See the meta box for details.</p></div>';
    } elseif ($msg === 'bad_id') {
        echo '<div class="notice notice-warning is-dismissible"><p>PostSecret AI: Invalid attachment.</p></div>';
    } elseif ($msg === 'dupe') {
        echo '<div class="notice notice-warning is-dismissible"><p>PostSecret AI: Uploaded image matches an existing file (duplicate marked).</p></div>';
    }
});


/**
 * Quick log peek: /wp-json/psai/v1/debug-log?lines=200
 * (Permissive: any logged-in user. Switch to stricter if needed.)
 */
add_action('rest_api_init', function () {
    register_rest_route('psai/v1', '/debug-log', [
        'methods' => 'GET',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
        'args' => [
            'lines' => ['type' => 'integer', 'default' => 200, 'minimum' => 10, 'maximum' => 2000],
        ],
        'callback' => function (\WP_REST_Request $req) {
            $content_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
            $file = trailingslashit($content_dir) . 'debug.log';

            if (!file_exists($file)) {
                return new \WP_REST_Response(['exists' => false, 'message' => 'No debug.log yet'], 200);
            }
            $n = (int)$req->get_param('lines');
            $n = max(10, min(2000, $n));

            $lines = [];
            $fp = fopen($file, 'r');
            if (!$fp) return new \WP_Error('fs_error', 'Cannot open debug.log');

            // tail n lines
            $pos = -1;
            $line = '';
            fseek($fp, 0, SEEK_END);
            $len = ftell($fp);
            while ($len > 0 && count($lines) <= $n) {
                fseek($fp, $len--, SEEK_SET);
                $char = fgetc($fp);
                if ($char === "\n" && $line !== '') {
                    $lines[] = strrev($line);
                    $line = '';
                    continue;
                }
                $line .= $char;
            }
            if ($line !== '') $lines[] = strrev($line);
            fclose($fp);

            $lines = array_slice(array_reverse($lines), -$n);
            return new \WP_REST_Response(['exists' => true, 'lines' => $lines], 200);
        },
    ]);
});

/* ---------------------------------------------------------------------------
 * Bulk Upload AJAX Endpoints
 * ------------------------------------------------------------------------- */

// List jobs
add_action('wp_ajax_psai_bulk_list_jobs', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

    $jobs = \PSAI\BulkJobService::list_jobs(50);

    // Format for display
    $formatted = array_map(function($job) {
        return [
            'id' => (int)$job['id'],
            'uuid' => $job['uuid'],
            'status' => $job['status'],
            'source' => $job['source'],
            'total' => (int)$job['total_items'],
            'processed' => (int)$job['processed_items'],
            'success_count' => (int)$job['success_count'],
            'fail_count' => (int)$job['fail_count'],
            'last_error' => $job['last_error'] ? substr($job['last_error'], 0, 100) : null,
            'created' => wp_date('Y-m-d H:i', strtotime($job['created_at'])),
        ];
    }, $jobs);

    wp_send_json_success(['jobs' => $formatted]);
});

// Get job detail
add_action('wp_ajax_psai_bulk_get_job', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

    $job_id = isset($_REQUEST['job_id']) ? (int)$_REQUEST['job_id'] : 0;
    if (!$job_id) wp_send_json_error('Invalid job ID');

    $job = \PSAI\BulkJobService::get_job($job_id);
    if (!$job) wp_send_json_error('Job not found');

    wp_send_json_success([
        'id' => (int)$job['id'],
        'uuid' => $job['uuid'],
        'status' => $job['status'],
        'source' => $job['source'],
        'staging_path' => $job['staging_path'],
        'total' => (int)$job['total_items'],
        'processed' => (int)$job['processed_items'],
        'success_count' => (int)$job['success_count'],
        'fail_count' => (int)$job['fail_count'],
        'started_at' => $job['started_at'],
        'created_at' => $job['created_at'],
        'updated_at' => $job['updated_at'],
    ]);
});

// Start job
add_action('wp_ajax_psai_bulk_start_job', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

    $job_id = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    if (!$job_id) wp_send_json_error('Invalid job ID');

    $result = \PSAI\BulkJobService::update_job_status($job_id, \PSAI\BulkJobService::STATUS_RUNNING);
    if ($result) {
        wp_send_json_success();
    } else {
        wp_send_json_error('Failed to start job');
    }
});

// Pause job
add_action('wp_ajax_psai_bulk_pause_job', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

    $job_id = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    if (!$job_id) wp_send_json_error('Invalid job ID');

    $result = \PSAI\BulkJobService::update_job_status($job_id, \PSAI\BulkJobService::STATUS_PAUSED);
    if ($result) {
        wp_send_json_success();
    } else {
        wp_send_json_error('Failed to pause job');
    }
});

// Stop job
add_action('wp_ajax_psai_bulk_stop_job', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

    $job_id = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    if (!$job_id) wp_send_json_error('Invalid job ID');

    $result = \PSAI\BulkJobService::update_job_status($job_id, \PSAI\BulkJobService::STATUS_STOPPED);
    if ($result) {
        wp_send_json_success();
    } else {
        wp_send_json_error('Failed to stop job');
    }
});

// Process batch (step)
add_action('wp_ajax_psai_bulk_step', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

    $job_id = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    $batch_size = isset($_POST['batch_size']) ? (int)$_POST['batch_size'] : 25;

    if (!$job_id) wp_send_json_error('Invalid job ID');

    $result = \PSAI\BulkJobService::process_batch($job_id, $batch_size);
    wp_send_json_success($result);
});

// Retry failed items
add_action('wp_ajax_psai_bulk_retry_failed', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

    $job_id = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    if (!$job_id) wp_send_json_error('Invalid job ID');

    $count = \PSAI\BulkJobService::retry_failed($job_id);
    wp_send_json_success(['requeued' => $count]);
});

// Delete job
add_action('wp_ajax_psai_bulk_delete_job', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

    $job_id = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    if (!$job_id) wp_send_json_error('Invalid job ID');

    $result = \PSAI\BulkJobService::delete_job($job_id);
    if ($result) {
        wp_send_json_success();
    } else {
        wp_send_json_error('Failed to delete job');
    }
});

// Get errors
add_action('wp_ajax_psai_bulk_get_errors', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

    $job_id = isset($_REQUEST['job_id']) ? (int)$_REQUEST['job_id'] : 0;
    if (!$job_id) wp_send_json_error('Invalid job ID');

    $errors = \PSAI\BulkJobService::get_errors($job_id, 100);

    $formatted = array_map(function($error) {
        return [
            'id' => (int)$error['id'],
            'file_path' => $error['file_path'],
            'status' => $error['status'],
            'attempts' => (int)$error['attempts'],
            'last_error' => $error['last_error'],
            'updated_at' => wp_date('Y-m-d H:i:s', strtotime($error['updated_at'])),
        ];
    }, $errors);

    wp_send_json_success(['errors' => $formatted]);
});

// Save job settings
add_action('wp_ajax_psai_bulk_save_settings', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

    $job_id = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    $settings = isset($_POST['settings']) ? (array)$_POST['settings'] : [];

    if (!$job_id) wp_send_json_error('Invalid job ID');

    $result = \PSAI\BulkJobService::save_settings($job_id, $settings);
    if ($result) {
        wp_send_json_success();
    } else {
        wp_send_json_error('Failed to save settings');
    }
});

// Export errors CSV
add_action('wp_ajax_psai_bulk_export_errors', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

    $job_id = isset($_REQUEST['job_id']) ? (int)$_REQUEST['job_id'] : 0;
    if (!$job_id) wp_die('Invalid job ID');

    $csv = \PSAI\BulkJobService::export_errors_csv($job_id);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=psai-bulk-errors-' . $job_id . '.csv');
    echo $csv;
    exit;
});

// Create job (file upload)
add_action('admin_post_psai_bulk_create_job', function() {
    try {
        error_log('PSAI Bulk: Handler called');

        if (!current_user_can('manage_options')) {
            error_log('PSAI Bulk: Unauthorized user');
            wp_die('Unauthorized', 403);
        }

        error_log('PSAI Bulk: Checking nonce');
        check_admin_referer('psai_bulk_create_job', 'psai_bulk_nonce');

        // Check if tables exist first
        global $wpdb;
        $table_jobs = $wpdb->prefix . 'psai_bulk_jobs';
        $tables_exist = $wpdb->get_var("SHOW TABLES LIKE '{$table_jobs}'") === $table_jobs;

        error_log('PSAI Bulk: Tables exist: ' . ($tables_exist ? 'yes' : 'no'));

        if (!$tables_exist) {
            set_transient('_ps_bulk_error', 'Database tables not found. Please run migrations first.', 300);
            wp_redirect(add_query_arg([
                'page' => 'psai_bulk_upload',
                'psai_msg' => 'err'
            ], admin_url('admin.php')));
            exit;
        }

        // Debug: Log the $_FILES array
        error_log('PSAI Bulk Upload - $_FILES: ' . print_r($_FILES, true));

        $result = \PSAI\BulkJobService::create_job($_FILES);

        // Debug: Log the result
        error_log('PSAI Bulk Upload - Result: ' . print_r($result, true));

        if ($result['success']) {
            wp_redirect(add_query_arg([
                'page' => 'psai_bulk_upload',
                'psai_msg' => 'job_created',
                'job_id' => $result['job_id']
            ], admin_url('admin.php')));
        } else {
            set_transient('_ps_bulk_error', $result['error'], 300);
            wp_redirect(add_query_arg([
                'page' => 'psai_bulk_upload',
                'psai_msg' => 'err'
            ], admin_url('admin.php')));
        }
        exit;
    } catch (\Throwable $e) {
        error_log('PSAI Bulk FATAL ERROR: ' . $e->getMessage());
        error_log('PSAI Bulk FATAL TRACE: ' . $e->getTraceAsString());
        set_transient('_ps_bulk_error', 'Fatal error: ' . $e->getMessage(), 300);
        wp_redirect(add_query_arg([
            'page' => 'psai_bulk_upload',
            'psai_msg' => 'err'
        ], admin_url('admin.php')));
        exit;
    }
});

// Admin notices for bulk upload
add_action('admin_notices', function() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'psai_bulk_upload') return;
    if (!isset($_GET['psai_msg'])) return;

    $msg = sanitize_text_field($_GET['psai_msg']);

    if ($msg === 'job_created') {
        echo '<div class="notice notice-success is-dismissible"><p>Job created successfully! Click "Open" to start processing.</p></div>';
    } elseif ($msg === 'err') {
        $error = get_transient('_ps_bulk_error');
        delete_transient('_ps_bulk_error');
        echo '<div class="notice notice-error is-dismissible"><p>Error: ' . esc_html($error ?: 'Unknown error') . '</p></div>';
    }
});

/* ---------------------------------------------------------------------------
 * Bulk Reclassification AJAX handlers
 * ------------------------------------------------------------------------- */

// Preview reclassification count
add_action('wp_ajax_psai_reclassify_preview', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

    $filters = isset($_POST['filters']) ? (array)$_POST['filters'] : [];

    // Build query args
    $args = [
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'post_status' => 'inherit',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => [
            'relation' => 'AND',
        ],
    ];

    // Apply filters
    if (!empty($filters['status'])) {
        $args['post_parent__in'] = get_posts([
            'post_type' => 'secret',
            'post_status' => sanitize_key($filters['status']),
            'fields' => 'ids',
            'posts_per_page' => -1,
        ]);
    }

    if (!empty($filters['side'])) {
        $args['meta_query'][] = [
            'key' => '_ps_side',
            'value' => sanitize_key($filters['side']),
        ];
    }

    if (!empty($filters['date_from'])) {
        $args['date_query'] = [
            'after' => sanitize_text_field($filters['date_from']),
        ];
    }

    if (!empty($filters['date_to'])) {
        if (!isset($args['date_query'])) {
            $args['date_query'] = [];
        }
        $args['date_query']['before'] = sanitize_text_field($filters['date_to']);
    }

    // Get count
    $query = new WP_Query($args);
    $count = $query->found_posts;

    // Apply limit if specified
    if (!empty($filters['limit']) && (int)$filters['limit'] > 0) {
        $count = min($count, (int)$filters['limit']);
    }

    // Estimate cost (rough estimate: $0.0015 per image for gpt-4o-mini vision)
    $env = get_option(\PSAI\Settings::OPTION, \PSAI\Settings::defaults());
    $model = $env['MODEL_NAME'] ?? 'gpt-4o-mini';

    $cost_per_image = 0.0015; // Default for gpt-4o-mini
    if (strpos($model, 'gpt-4o') !== false && strpos($model, 'mini') === false) {
        $cost_per_image = 0.005; // gpt-4o is more expensive
    }

    $estimated_cost = '$' . number_format($count * $cost_per_image, 2);

    wp_send_json_success([
        'count' => $count,
        'estimated_cost' => $estimated_cost,
    ]);
});

// Create reclassification job
add_action('wp_ajax_psai_reclassify_create_job', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

    $filters = isset($_POST['filters']) ? (array)$_POST['filters'] : [];

    // Build query args (same as preview)
    $args = [
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'post_status' => 'inherit',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => [
            'relation' => 'AND',
        ],
    ];

    // Apply filters
    if (!empty($filters['status'])) {
        $args['post_parent__in'] = get_posts([
            'post_type' => 'secret',
            'post_status' => sanitize_key($filters['status']),
            'fields' => 'ids',
            'posts_per_page' => -1,
        ]);
    }

    if (!empty($filters['side'])) {
        $args['meta_query'][] = [
            'key' => '_ps_side',
            'value' => sanitize_key($filters['side']),
        ];
    }

    if (!empty($filters['date_from'])) {
        $args['date_query'] = [
            'after' => sanitize_text_field($filters['date_from']),
        ];
    }

    if (!empty($filters['date_to'])) {
        if (!isset($args['date_query'])) {
            $args['date_query'] = [];
        }
        $args['date_query']['before'] = sanitize_text_field($filters['date_to']);
    }

    // Get attachment IDs
    $query = new WP_Query($args);
    $attachment_ids = $query->posts;

    // Apply limit if specified
    if (!empty($filters['limit']) && (int)$filters['limit'] > 0) {
        $attachment_ids = array_slice($attachment_ids, 0, (int)$filters['limit']);
    }

    if (empty($attachment_ids)) {
        wp_send_json_error('No attachments match your filters.');
    }

    // Create job
    global $wpdb;
    $table_jobs = $wpdb->prefix . 'psai_bulk_jobs';
    $table_items = $wpdb->prefix . 'psai_bulk_items';

    $job_uuid = wp_generate_uuid4();

    // Build source description from filters
    $source_parts = [];
    if (!empty($filters['status'])) $source_parts[] = 'status:' . $filters['status'];
    if (!empty($filters['side'])) $source_parts[] = 'side:' . $filters['side'];
    if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
        $date_range = [];
        if (!empty($filters['date_from'])) $date_range[] = $filters['date_from'];
        $date_range[] = 'to';
        if (!empty($filters['date_to'])) $date_range[] = $filters['date_to'];
        $source_parts[] = implode(' ', $date_range);
    }
    if (!empty($filters['limit'])) $source_parts[] = 'limit:' . $filters['limit'];
    $source = 'reclassify:' . (count($source_parts) > 0 ? implode(', ', $source_parts) : 'all');

    $wpdb->insert($table_jobs, [
        'uuid' => $job_uuid,
        'status' => \PSAI\BulkJobService::STATUS_NEW,
        'source' => $source,
        'staging_path' => '', // Not used for reclassification
        'total_items' => count($attachment_ids),
        'processed_items' => 0,
        'success_count' => 0,
        'fail_count' => 0,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ]);

    $job_id = $wpdb->insert_id;

    // Insert items as "reclassify" tasks (use file_path to store attachment_id)
    foreach ($attachment_ids as $att_id) {
        $wpdb->insert($table_items, [
            'job_id' => $job_id,
            'file_path' => 'attachment:' . $att_id, // Store attachment ID in file_path
            'sha256' => '', // Not used for reclassification
            'status' => \PSAI\BulkJobService::ITEM_PENDING,
            'attempts' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
    }

    wp_send_json_success(['job_id' => $job_id]);
});

// List reclassification jobs (reuse existing bulk job list, filter by source)
add_action('wp_ajax_psai_reclassify_list_jobs', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

    global $wpdb;
    $table_jobs = $wpdb->prefix . 'psai_bulk_jobs';

    $jobs = $wpdb->get_results(
        "SELECT * FROM {$table_jobs}
         WHERE source LIKE 'reclassify:%'
         ORDER BY created_at DESC
         LIMIT 50",
        ARRAY_A
    );

    // Format for display
    $formatted = array_map(function($job) {
        return [
            'id' => (int)$job['id'],
            'uuid' => $job['uuid'],
            'status' => $job['status'],
            'source' => $job['source'],
            'total' => (int)$job['total_items'],
            'processed' => (int)$job['processed_items'],
            'success_count' => (int)$job['success_count'],
            'fail_count' => (int)$job['fail_count'],
            'last_error' => $job['last_error'],
            'created' => mysql2date('M j, Y g:i a', $job['created_at']),
        ];
    }, $jobs);

    wp_send_json_success(['jobs' => $formatted]);
});

// Process reclassification step
add_action('wp_ajax_psai_reclassify_step', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

    $job_id = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    $batch_size = isset($_POST['batch_size']) ? (int)$_POST['batch_size'] : 10;

    if (!$job_id) wp_send_json_error('Invalid job ID');

    global $wpdb;
    $table_jobs = $wpdb->prefix . 'psai_bulk_jobs';
    $table_items = $wpdb->prefix . 'psai_bulk_items';

    // Get job
    $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_jobs} WHERE id = %d", $job_id), ARRAY_A);
    if (!$job || $job['status'] !== \PSAI\BulkJobService::STATUS_RUNNING) {
        wp_send_json_error('Job is not running');
    }

    // Get pending items
    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_items}
         WHERE job_id = %d AND status = %s
         ORDER BY id ASC
         LIMIT %d",
        $job_id,
        \PSAI\BulkJobService::ITEM_PENDING,
        $batch_size
    ), ARRAY_A);

    if (empty($items)) {
        // No more items - mark job as completed
        $wpdb->update($table_jobs, [
            'status' => \PSAI\BulkJobService::STATUS_COMPLETED,
            'completed_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $job_id]);

        wp_send_json_success([
            'status' => \PSAI\BulkJobService::STATUS_COMPLETED,
            'has_more' => false,
        ]);
    }

    // Process each item (reclassify)
    $success = 0;
    $failed = 0;

    foreach ($items as $item) {
        $item_id = (int)$item['id'];

        // Extract attachment ID from file_path (format: "attachment:123")
        $file_path = $item['file_path'];
        if (strpos($file_path, 'attachment:') === 0) {
            $att_id = (int)substr($file_path, strlen('attachment:'));

            // Reclassify
            try {
                $wpdb->update($table_items, [
                    'status' => \PSAI\BulkJobService::ITEM_PROCESSING,
                    'attempts' => (int)$item['attempts'] + 1,
                    'updated_at' => current_time('mysql'),
                ], ['id' => $item_id]);

                // Get API key and model from settings
                $env = get_option(\PSAI\Settings::OPTION, \PSAI\Settings::defaults());
                $api_key = $env['API_KEY'] ?? '';
                $model = $env['MODEL_NAME'] ?? 'gpt-4o-mini';

                if (empty($api_key)) {
                    throw new \Exception('API key not configured');
                }

                // Generate data URLs (same as ClassificationService - works with localhost)
                $frontSrc = \PSAI\psai_make_data_url($att_id);

                // Check if there's a back side
                $pair_id = (int) get_post_meta($att_id, '_ps_pair_id', true);
                $backSrc = $pair_id ? \PSAI\psai_make_data_url($pair_id) : null;

                // Classify (throws exception on error)
                $payload = \PSAI\Classifier::classify($api_key, $model, $frontSrc, $backSrc);

                // Store classification result
                \PSAI\psai_store_result($att_id, $payload, $model);

                // Mark success
                $wpdb->update($table_items, [
                    'status' => \PSAI\BulkJobService::ITEM_SUCCESS,
                    'updated_at' => current_time('mysql'),
                ], ['id' => $item_id]);

                $success++;

            } catch (\Exception $e) {
                $error_msg = $e->getMessage();

                $wpdb->update($table_items, [
                    'status' => \PSAI\BulkJobService::ITEM_ERROR,
                    'last_error' => substr($error_msg, 0, 500),
                    'updated_at' => current_time('mysql'),
                ], ['id' => $item_id]);

                $wpdb->update($table_jobs, [
                    'last_error' => substr($error_msg, 0, 500),
                    'updated_at' => current_time('mysql'),
                ], ['id' => $job_id]);

                $failed++;
            }
        }
    }

    // Update job stats
    $wpdb->query($wpdb->prepare(
        "UPDATE {$table_jobs} SET
         processed_items = processed_items + %d,
         success_count = success_count + %d,
         fail_count = fail_count + %d,
         updated_at = %s
         WHERE id = %d",
        $success + $failed,
        $success,
        $failed,
        current_time('mysql'),
        $job_id
    ));

    // Check if more items remain
    $remaining = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_items}
         WHERE job_id = %d AND status = %s",
        $job_id,
        \PSAI\BulkJobService::ITEM_PENDING
    ));

    wp_send_json_success([
        'status' => \PSAI\BulkJobService::STATUS_RUNNING,
        'has_more' => $remaining > 0,
        'processed' => $success + $failed,
    ]);
});

/* ---------------------------------------------------------------------------
 * Prompt Editor handlers
 * ------------------------------------------------------------------------- */
add_action('admin_post_psai_save_prompt', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);
    check_admin_referer('psai_save_prompt');

    $custom_prompt = isset($_POST['psai_custom_prompt']) ? $_POST['psai_custom_prompt'] : '';

    // Get current options
    $opts = get_option(\PSAI\Settings::OPTION, []) ?: [];

    // Update the custom prompt
    $opts['CUSTOM_PROMPT'] = $custom_prompt;

    // Save back
    update_option(\PSAI\Settings::OPTION, $opts);

    wp_redirect(add_query_arg([
        'page' => 'psai_prompt_editor',
        'psai_prompt_saved' => '1'
    ], admin_url('admin.php')));
    exit;
});

add_action('admin_post_psai_reset_prompt', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);
    check_admin_referer('psai_reset_prompt');

    // Get current options
    $opts = get_option(\PSAI\Settings::OPTION, []) ?: [];

    // Clear the custom prompt
    $opts['CUSTOM_PROMPT'] = '';

    // Save back
    update_option(\PSAI\Settings::OPTION, $opts);

    wp_redirect(add_query_arg([
        'page' => 'psai_prompt_editor',
        'psai_prompt_reset' => '1'
    ], admin_url('admin.php')));
    exit;
});