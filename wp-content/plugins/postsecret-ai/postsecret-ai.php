<?php
/**
 * Plugin Name: PostSecret AI (Ultra-MVP)
 * Description: Admin-only page to classify a single image; now with full settings from Python schema.
 * Version: 0.0.2
 */
if (!defined('ABSPATH')) exit;

define('PSAI_SLUG', 'postsecret-ai');


require __DIR__ . '/src/Prompt.php';
require __DIR__ . '/src/Schema.php';
require __DIR__ . '/src/Settings.php';
require __DIR__ . '/src/AdminPage.php';
require __DIR__ . '/src/Classifier.php';

// Menu: Tools → PostSecret AI (keeps it admin-gated)
add_action('admin_menu', function () {
    add_management_page('PostSecret AI', 'PostSecret AI', 'manage_options', PSAI_SLUG, ['PSAI\\AdminPage', 'render']);
});

// Settings (auto-register all fields from Schema)
add_action('admin_init', ['PSAI\\Settings', 'register']);

// Form handler for the "Run Classifier" button (unchanged from earlier)
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
        $payload = \PSAI\Classifier::classify($apiKey, $model, $image);
        set_transient('psai_last_result', ['image_url' => $image, 'model' => $model, 'payload' => $payload, 'ts' => time()], 600);
        wp_redirect(add_query_arg(['page' => PSAI_SLUG, 'psai_done' => '1'], admin_url('tools.php')));
        exit;
    } catch (\Throwable $e) {
        set_transient('psai_last_error', $e->getMessage(), 300);
        wp_redirect(add_query_arg(['page' => PSAI_SLUG, 'psai_err' => 'call_failed'], admin_url('tools.php')));
        exit;
    }
});