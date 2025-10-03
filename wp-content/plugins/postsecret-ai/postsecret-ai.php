<?php
/**
 * Plugin Name: PostSecret AI (Ultra-MVP)
 * Description: Admin-only tools for classification: test console + single postcard uploader.
 * Version: 0.0.5
 */
if (!defined('ABSPATH')) exit;

define('PSAI_SLUG', 'postsecret-ai');

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

// Admin
require __DIR__ . '/src/Settings.php';
require __DIR__ . '/src/AdminPage.php';
require __DIR__ . '/src/AdminSingleUpload.php';
require __DIR__ . '/src/AdminMetaBox.php';

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
        'upload_files',
        'psai_bulk_upload',
        function () {
            echo '<div class="wrap"><h1>Bulk Upload</h1><p>Bulk upload interface coming soon.</p></div>';
        }
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