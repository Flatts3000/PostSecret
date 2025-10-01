<?php
/**
 * Plugin Name: PostSecret AI (Ultra-MVP)
 * Description: Admin-only tools for classification: test console + single postcard uploader.
 * Version: 0.0.3
 */
if (!defined('ABSPATH')) exit;

define('PSAI_SLUG', 'postsecret-ai');

require __DIR__ . '/src/Prompt.php';
require __DIR__ . '/src/SchemaGuard.php';
require __DIR__ . '/src/Metadata.php';
require __DIR__ . '/src/AttachmentSync.php';
require __DIR__ . '/src/Schema.php';
require __DIR__ . '/src/Settings.php';
require __DIR__ . '/src/AdminPage.php';
require __DIR__ . '/src/AdminSingleUpload.php';
require __DIR__ . '/src/Classifier.php';
require __DIR__ . '/src/Ingress.php';
require __DIR__ . '/src/AdminMetaBox.php';

/**
 * Menus
 * - Tools → PostSecret AI (existing tester/settings)
 * - Postcards → Upload Single (new two-slot uploader for Frank)
 */
add_action('admin_menu', function () {
    // Existing tester under Tools
    add_management_page('PostSecret AI', 'PostSecret AI', 'manage_options', PSAI_SLUG, ['PSAI\\AdminPage', 'render']);

    // NEW top-level "Postcards" + "Upload Single"
    add_menu_page(
        'Postcards', 'Postcards', 'upload_files', 'psai_postcards',
        function () {
            echo '<div class="wrap"><h1>Postcards</h1><p>Choose a submenu: Upload Single.</p></div>';
        },
        'dashicons-format-image', 25
    );
    add_submenu_page(
        'psai_postcards', 'Upload Single', 'Upload Single', 'upload_files',
        'psai_upload_single', ['PSAI\\AdminSingleUpload', 'render']
    );
});

/** Settings (tester page) */
add_action('admin_init', ['PSAI\\Settings', 'register']);

/**
 * Existing tester handler (leave as-is)
 * Tools → PostSecret AI → "Run Classifier" (URL-based single image test)
 */
add_action('admin_post_psai_classify', function () {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);
    check_admin_referer('psai_classify');

    $env = get_option(\PSAI\Settings::OPTION, \PSAI\Settings::defaults());
    $apiKey = ($env['API_KEY'] ?? '');
    $model = ($env['MODEL_NAME'] ?? 'gpt-4o-mini');
    $image = isset($_POST['psai_image_url']) ? esc_url_raw(trim($_POST['psai_image_url'])) : '';

    if (!$apiKey || !$image) {
        $q = ['page' => PSAI_SLUG, 'psai_err' => !$apiKey ? 'no_key' : 'no_image'];
        wp_redirect(add_query_arg($q, admin_url('tools.php')));
        exit;
    }

    try {
        $payload = \PSAI\Classifier::classify($apiKey, $model, $image, null); // signature supports (front, back?)
        set_transient('psai_last_result', ['image_url' => $image, 'model' => $model, 'payload' => $payload, 'ts' => time()], 600);
        wp_redirect(add_query_arg(['page' => PSAI_SLUG, 'psai_done' => '1'], admin_url('tools.php')));
        exit;
    } catch (\Throwable $e) {
        set_transient('psai_last_error', $e->getMessage(), 300);
        wp_redirect(add_query_arg(['page' => PSAI_SLUG, 'psai_err' => 'call_failed'], admin_url('tools.php')));
        exit;
    }
});

add_action('admin_post_psai_upload_single', function () {
    if (!current_user_can('upload_files')) wp_die('Not allowed', 403);
    check_admin_referer('psai_upload_single');

    $front_id = null;
    $back_id = null;
    $had_dupe = false;

    // 1) FRONT (required)
    if (empty($_FILES['psai_front']['name'])) {
        wp_redirect(add_query_arg(['page' => 'psai_upload_single', 'psai_msg' => 'err'], admin_url('admin.php')));
        exit;
    }
    $front_id = \PSAI\Ingress::sideload($_FILES['psai_front'], 'front');
    if (!$front_id) {
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
        }
    }

    // 3) Queue pair processing via WP-Cron (runs in background)
    if (!get_post_meta($front_id, '_ps_duplicate_of', true)) {
        wp_schedule_single_event(time() + 5, 'psai_process_pair_event', [$front_id, (int)($back_id ?? 0)]);
        do_action('psai_process_pair_event', $front_id, (int)($back_id ?? 0));
    }

    $msg = $had_dupe ? 'dupe' : 'ok';
    wp_redirect(add_query_arg(['page' => 'psai_upload_single', 'psai_msg' => $msg], admin_url('admin.php')));
    exit;
});

add_action('psai_process_pair_event', function ($front_id, $back_id = 0) {
    $front_id = (int)$front_id;
    $back_id = (int)$back_id ?: null;

    $env = get_option(\PSAI\Settings::OPTION, \PSAI\Settings::defaults());
    $api = $env['API_KEY'] ?? '';
    $model = $env['MODEL_NAME'] ?? 'gpt-4o-mini';
    if (!$api || !$front_id) return;
    if (get_post_meta($front_id, '_ps_duplicate_of', true)) return;

    try {
        // Build data URLs (works on localhost/private sites)
        $frontSrc = \PSAI\psai_make_data_url($front_id);
        $backSrc = $back_id ? \PSAI\psai_make_data_url($back_id) : null;

        $payload = \PSAI\Classifier::classify($api, $model, $frontSrc, $backSrc);
        $payload = \PSAI\SchemaGuard::normalize($payload);

        \PSAI\psai_store_result($front_id, $payload, $model);

        // ✅ Sync AI → attachment fields (Alt / Caption / Description)
        \PSAI\AttachmentSync::sync_from_payload($front_id, $payload, $back_id);

        \PSAI\Metadata::compute_and_store($front_id);
        if ($back_id) \PSAI\Metadata::compute_and_store($back_id);
        \PSAI\psai_update_manifest($front_id, $payload);

        if ($back_id) {
            update_post_meta($back_id, '_ps_pair_id', $front_id);
            update_post_meta($back_id, '_ps_side', 'back');
            update_post_meta($back_id, '_ps_payload', $payload);
            update_post_meta($back_id, '_ps_tags', get_post_meta($front_id, '_ps_tags', true));
            update_post_meta($back_id, '_ps_model', get_post_meta($front_id, '_ps_model', true));
            update_post_meta($back_id, '_ps_prompt_version', get_post_meta($front_id, '_ps_prompt_version', true));
            update_post_meta($back_id, '_ps_updated_at', wp_date('c'));
        }

        delete_post_meta($front_id, '_ps_last_error');
        if ($back_id) delete_post_meta($back_id, '_ps_last_error');

    } catch (\Throwable $e) {
        $msg = substr($e->getMessage(), 0, 500);
        update_post_meta($front_id, '_ps_last_error', $msg);
        if ($back_id) update_post_meta($back_id, '_ps_last_error', $msg);
    }
}, 10, 2);

add_action('admin_post_psai_process_now', function () {
    if (!current_user_can('upload_files')) wp_die('Not allowed', 403);
    $att = isset($_GET['att']) ? (int)$_GET['att'] : 0;
    check_admin_referer('psai_process_now_' . $att);
    if (!$att) wp_redirect(admin_url('upload.php'));

    // find paired back if any
    $back = (int)get_post_meta($att, '_ps_pair_id', true);
    $front_id = $att;
    // Ensure $front_id is the canonical "front" if the other side exists
    $side = get_post_meta($att, '_ps_side', true);
    if ($side === 'back' && $back) {
        $front_id = $back;
        $back = $att;
    }

    // run immediately
    do_action('psai_process_pair_event', $front_id, (int)($back ?: 0));

    wp_redirect(get_edit_post_link($att, ''));
    exit;
});