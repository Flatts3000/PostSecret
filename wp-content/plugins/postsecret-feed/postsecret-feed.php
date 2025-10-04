<?php
/**
 * Plugin Name: PostSecret Feed
 * Description: Read-only REST + front-page stream for vetted postcards.
 * Version: 0.1.0
 * Author: PostSecret
 */
if (!defined('ABSPATH')) exit;

// Image size (safe to declare in plugin; theme can override if needed)
add_action('after_setup_theme', function () {
    if (!has_image_size('secret-card')) {
        add_image_size('secret-card', 800, 600, true);
    }
});

// REST: /wp-json/psai/v1/secrets
add_action('rest_api_init', function () {
    register_rest_route('psai/v1', '/secrets', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'args' => [
            'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
            'per_page' => ['type' => 'integer', 'default' => 24, 'minimum' => 1, 'maximum' => 60],
        ],
        'callback' => function (\WP_REST_Request $req) {
            $page = max(1, (int)$req['page']);
            $pp = min(60, max(1, (int)$req['per_page']));
            $q = new \WP_Query([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_mime_type' => 'image',
                'orderby' => 'date',
                'order' => 'DESC',
                'paged' => $page,
                'posts_per_page' => $pp,
                'meta_query' => [
                    ['key' => '_ps_side', 'value' => 'front', 'compare' => '='],
                    ['key' => '_ps_is_vetted', 'value' => '1', 'compare' => '='],
                ],
            ]);

            $items = [];
            foreach ($q->posts as $p) {
                $id = (int)$p->ID;
                $src = wp_get_attachment_image_src($id, 'secret-card');
                if (!$src) $src = [wp_get_attachment_url($id), 0, 0, true];

                // Get back side data if exists
                $back_id = (int)(get_post_meta($id, '_ps_pair_id', true) ?: 0) ?: null;
                $back_src = null;
                $back_alt = null;
                if ($back_id) {
                    $back_image = wp_get_attachment_image_src($back_id, 'secret-card');
                    if ($back_image) {
                        $back_src = $back_image[0];
                    } else {
                        $back_src = wp_get_attachment_url($back_id);
                    }
                    $back_alt = get_post_meta($back_id, '_wp_attachment_image_alt', true) ?: '';
                }

                $items[] = [
                    'id' => $id,
                    'src' => $src[0],
                    'width' => (int)$src[1],
                    'height' => (int)$src[2],
                    'alt' => get_post_meta($id, '_wp_attachment_image_alt', true) ?: '',
                    'caption' => get_post_field('post_excerpt', $id) ?: '',
                    'excerpt' => get_post_field('post_content', $id) ?: '',
                    'date' => get_post_datetime($id)?->format('c'),
                    'tags' => array_values((array)get_post_meta($id, '_ps_tags', true) ?: []),
                    'primary' => get_post_meta($id, '_ps_primary_hex', true) ?: '',
                    'orientation' => get_post_meta($id, '_ps_orientation', true) ?: '',
                    'back_id' => $back_id,
                    'back_src' => $back_src,
                    'back_alt' => $back_alt,
                    'link' => get_permalink($id),
                ];
            }

            return new \WP_REST_Response([
                'page' => $page,
                'per_page' => $pp,
                'total' => (int)$q->found_posts,
                'total_pages' => (int)$q->max_num_pages,
                'items' => $items,
            ], 200);
        }
    ]);
});

// Front-page stream (only on home)
add_action('wp_enqueue_scripts', function () {
    // Only load on front page - explicitly exclude other page types
    if (!is_front_page() || is_singular() || is_attachment() || is_search()) {
        return;
    }

    $handle = 'psai-stream';
    $version = '1.0.1'; // Version for cache busting
    wp_register_script($handle, plugins_url('assets/psai-stream.js', __FILE__), ['mustache'], $version, true);

    // Pretty and legacy (query-param) endpoints
    $pretty = rest_url('psai/v1/secrets');
    $legacy = add_query_arg('rest_route', '/psai/v1/secrets', site_url('/'));

    $cfg = [
        'endpoint' => esc_url_raw($pretty),
        'endpointLegacy' => esc_url_raw($legacy),
        'perPage' => 24,
        'mountId' => 'psai-stream',
    ];
    wp_add_inline_script($handle, 'window.PSAI_STREAM=' . wp_json_encode($cfg) . ';', 'before');
    wp_enqueue_script($handle);
});