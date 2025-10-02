<?php
/**
 * Plugin Name: PostSecret AI (Ultra-MVP)
 * Description: Admin-only tools for classification: test console + single postcard uploader.
 * Version: 0.0.4
 */
if (!defined('ABSPATH')) exit;

define('PSAI_SLUG', 'postsecret-ai');

// Core
require __DIR__ . '/src/Prompt.php';
require __DIR__ . '/src/Schema.php';
require __DIR__ . '/src/SchemaGuard.php';
require __DIR__ . '/src/Classifier.php';
require __DIR__ . '/src/EmbeddingService.php';

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
});

/* Settings (tester page) */
add_action('admin_init', ['PSAI\\Settings', 'register']);

/* ---------------------------------------------------------------------------
 * Tester handler (Tools page) — URL-based single image test
 * ------------------------------------------------------------------------- */
add_action('admin_post_psai_classify', function () {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);
    check_admin_referer('psai_classify');

    $env = get_option(\PSAI\Settings::OPTION, \PSAI\Settings::defaults());
    $api = $env['API_KEY'] ?? '';
    $model = $env['MODEL_NAME'] ?? 'gpt-4o-mini';
    $image = isset($_POST['psai_image_url']) ? esc_url_raw(trim($_POST['psai_image_url'])) : '';

    if (!$api || !$image) {
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

        wp_redirect(add_query_arg(['page' => PSAI_SLUG, 'psai_done' => '1'], admin_url('tools.php')));
        exit;
    } catch (\Throwable $e) {
        set_transient('psai_last_error', $e->getMessage(), 300);
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
 * - builds data URLs (works on localhost/private)
 * - classifies
 * - stores payload + flags, syncs attachment fields
 * - computes orientation/color
 * ------------------------------------------------------------------------- */
add_action('psai_process_pair_event', function ($front_id, $back_id = 0) {
    $front_id = (int)$front_id;
    $back_id = (int)$back_id ?: null;

    $env = get_option(\PSAI\Settings::OPTION, \PSAI\Settings::defaults());
    $api = $env['API_KEY'] ?? '';
    $model = $env['MODEL_NAME'] ?? 'gpt-4o-mini';

    if (!$api || !$front_id) return;
    if (get_post_meta($front_id, '_ps_duplicate_of', true)) return;

    try {
        // Data URLs so we don’t rely on public URLs
        $frontSrc = \PSAI\psai_make_data_url($front_id);
        $backSrc = $back_id ? \PSAI\psai_make_data_url($back_id) : null;

        // Classify + normalize to schema
        $payload = \PSAI\Classifier::classify($api, $model, $frontSrc, $backSrc);
        $payload = \PSAI\SchemaGuard::normalize($payload);

        // Store result (sets tags, model, prompt version, vetted flags)
        \PSAI\psai_store_result($front_id, $payload, $model);

        // Sync media fields (Alt/Caption/Description) — safe no-op if class missing
        \PSAI\AttachmentSync::sync_from_payload($front_id, $payload, $back_id);

        // Enrich quick orientation/color on both sides
        \PSAI\Metadata::compute_and_store($front_id);
        if ($back_id) \PSAI\Metadata::compute_and_store($back_id);

        // Optional export manifest
        \PSAI\psai_update_manifest($front_id, $payload);

        // Mirror some fields onto back (paired) for convenience
        if ($back_id) {
            update_post_meta($back_id, '_ps_pair_id', $front_id);
            update_post_meta($back_id, '_ps_side', 'back');
            update_post_meta($back_id, '_ps_payload', $payload);
            update_post_meta($back_id, '_ps_tags', get_post_meta($front_id, '_ps_tags', true));
            update_post_meta($back_id, '_ps_model', get_post_meta($front_id, '_ps_model', true));
            update_post_meta($back_id, '_ps_prompt_version', get_post_meta($front_id, '_ps_prompt_version', true));
            update_post_meta($back_id, '_ps_updated_at', wp_date('c'));

            // keep vetted flags mirrored on back for UI/API convenience
            $rs = get_post_meta($front_id, '_ps_review_status', true);
            update_post_meta($back_id, '_ps_review_status', $rs);
            update_post_meta($back_id, '_ps_is_vetted', $rs === 'auto_vetted' ? '1' : '0');
        }

        delete_post_meta($front_id, '_ps_last_error');
        if ($back_id) delete_post_meta($back_id, '_ps_last_error');

    } catch (\Throwable $e) {
        $msg = substr($e->getMessage(), 0, 500);
        update_post_meta($front_id, '_ps_last_error', $msg);
        if ($back_id) update_post_meta($back_id, '_ps_last_error', $msg);
    }
}, 10, 2);

/* ---------------------------------------------------------------------------
 * “Process now” button on the attachment edit screen
 * - If payload missing → classify this single attachment (front-only)
 * - In all cases → normalize flags + compute orientation/color
 * ------------------------------------------------------------------------- */
add_action('admin_post_psai_process_now', function () {
    if (!current_user_can('upload_files')) wp_die('Not allowed', 403);

    $att = isset($_GET['att']) ? (int)$_GET['att'] : 0;
    check_admin_referer('psai_process_now_' . $att);
    if (!$att || get_post_type($att) !== 'attachment') {
        wp_redirect(admin_url('upload.php?psai_msg=bad_id'));
        exit;
    }

    try {
        // If this is the back, flip to the front as canonical
        $maybePair = (int)get_post_meta($att, '_ps_pair_id', true);
        $side = get_post_meta($att, '_ps_side', true);
        $front_id = ($side === 'back' && $maybePair) ? $maybePair : $att;

        // Classify if we don't already have a payload
        $payload = get_post_meta($front_id, '_ps_payload', true);
        if (!is_array($payload)) {
            $env = get_option(\PSAI\Settings::OPTION, \PSAI\Settings::defaults());
            $api = $env['API_KEY'] ?? '';
            $model = $env['MODEL_NAME'] ?? 'gpt-4o-mini';
            if (!$api) throw new \RuntimeException('Missing OpenAI API key.');

            $frontSrc = \PSAI\psai_make_data_url($front_id);
            $payload = \PSAI\Classifier::classify($api, $model, $frontSrc, null);
            $payload = \PSAI\SchemaGuard::normalize($payload);

            \PSAI\psai_store_result($front_id, $payload, $model);
            \PSAI\AttachmentSync::sync_from_payload($front_id, $payload, null);

            // Generate and store embedding for new classifications
            $embed_model = $env['EMBEDDING_MODEL'] ?? 'text-embedding-3-small';
            \PSAI\EmbeddingService::generate_and_store($front_id, $payload, $api, $embed_model);
        }

        // Normalize flags from the saved payload + recompute metadata
        \PSAI\Ingress::normalize_from_existing_payload($front_id);

        // Also compute orientation/color for the side the user is viewing
        \PSAI\Metadata::compute_and_store($att);

        delete_post_meta($front_id, '_ps_last_error');

        $url = add_query_arg(['psai_msg' => 'ok'], get_edit_post_link($att, ''));
        wp_safe_redirect($url ?: admin_url('upload.php?psai_msg=ok'));
        exit;

    } catch (\Throwable $e) {
        update_post_meta($att, '_ps_last_error', substr($e->getMessage(), 0, 500));
        $url = add_query_arg(['psai_msg' => 'err'], get_edit_post_link($att, ''));
        wp_safe_redirect($url ?: admin_url('upload.php?psai_msg=err'));
        exit;
    }
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

    try {
        // If this is the back, flip to the front as canonical
        $maybePair = (int)get_post_meta($att, '_ps_pair_id', true);
        $side = get_post_meta($att, '_ps_side', true);
        $front_id = ($side === 'back' && $maybePair) ? $maybePair : $att;

        // Get config
        $env = get_option(\PSAI\Settings::OPTION, \PSAI\Settings::defaults());
        $api = $env['API_KEY'] ?? '';
        $model = $env['MODEL_NAME'] ?? 'gpt-4o-mini';
        if (!$api) throw new \RuntimeException('Missing OpenAI API key.');

        // Force re-classification
        $frontSrc = \PSAI\psai_make_data_url($front_id);
        $payload = \PSAI\Classifier::classify($api, $model, $frontSrc, null);
        $payload = \PSAI\SchemaGuard::normalize($payload);

        \PSAI\psai_store_result($front_id, $payload, $model);
        \PSAI\AttachmentSync::sync_from_payload($front_id, $payload, null);

        // Generate and store embedding
        $embed_model = $env['EMBEDDING_MODEL'] ?? 'text-embedding-3-small';
        \PSAI\EmbeddingService::generate_and_store($front_id, $payload, $api, $embed_model);

        // Normalize flags + recompute metadata
        \PSAI\Ingress::normalize_from_existing_payload($front_id);
        \PSAI\Metadata::compute_and_store($att);

        delete_post_meta($front_id, '_ps_last_error');

        $url = add_query_arg(['psai_msg' => 'reclassified'], get_edit_post_link($att, ''));
        wp_safe_redirect($url ?: admin_url('upload.php?psai_msg=reclassified'));
        exit;

    } catch (\Throwable $e) {
        update_post_meta($att, '_ps_last_error', substr($e->getMessage(), 0, 500));
        $url = add_query_arg(['psai_msg' => 'err'], get_edit_post_link($att, ''));
        wp_safe_redirect($url ?: admin_url('upload.php?psai_msg=err'));
        exit;
    }
});

/* Small admin notice so you know the button worked */
add_action('admin_notices', function () {
    if (!is_admin() || !isset($_GET['psai_msg'])) return;
    $msg = sanitize_text_field($_GET['psai_msg']);
    if ($msg === 'ok') {
        echo '<div class="notice notice-success is-dismissible"><p>PostSecret AI: Attachment normalized.</p></div>';
    } elseif ($msg === 'reclassified') {
        echo '<div class="notice notice-success is-dismissible"><p>PostSecret AI: Attachment re-classified with latest AI model and prompt.</p></div>';
    } elseif ($msg === 'err') {
        echo '<div class="notice notice-error is-dismissible"><p>PostSecret AI: There was an error. See the meta box for details.</p></div>';
    } elseif ($msg === 'bad_id') {
        echo '<div class="notice notice-warning is-dismissible"><p>PostSecret AI: Invalid attachment.</p></div>';
    } elseif ($msg === 'dupe') {
        echo '<div class="notice notice-warning is-dismissible"><p>PostSecret AI: Uploaded image matches an existing file (duplicate marked).</p></div>';
    }
});