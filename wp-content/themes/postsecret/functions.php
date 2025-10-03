<?php
declare(strict_types=1);

// Load theme includes
require_once get_stylesheet_directory() . '/inc/routing.php';
require_once get_stylesheet_directory() . '/inc/seo.php';

// Enqueue scripts and styles
add_action('wp_enqueue_scripts', function () {
    // Stream styles (only on front page and search)
    if (is_front_page() || is_search()) {
        wp_enqueue_style(
            'ps-stream',
            get_stylesheet_directory_uri() . '/assets/css/ps-stream.css',
            [],
            filemtime(get_stylesheet_directory() . '/assets/css/ps-stream.css')
        );
    }

    // Mustache (templating) — keep it global so any template can use it
    wp_enqueue_script(
        'mustache',
        'https://unpkg.com/mustache@4.2.0/mustache.min.js',
        [],
        null,
        true
    );

    // Theme toggle script
    wp_enqueue_script(
        'ps-mode-toggle',
        get_stylesheet_directory_uri() . '/mode-toggle.js',
        [],
        null,
        true
    );

    // Semantic search script (global - needed for header search bar)
    wp_enqueue_script(
        'ps-semantic-search',
        get_stylesheet_directory_uri() . '/assets/js/semantic-search.js',
        ['mustache'],
        '1.0.1', // Version for cache busting
        true
    );

    // Secret detail page assets (only on attachment pages for front-side secrets)
    if (is_attachment()) {
        $side = get_post_meta(get_the_ID(), '_ps_side', true);
        if ($side === 'front') {
            // Detail page styles
            wp_enqueue_style(
                'ps-secret-detail',
                get_stylesheet_directory_uri() . '/assets/css/secret-detail.css',
                [],
                filemtime(get_stylesheet_directory() . '/assets/css/secret-detail.css')
            );

            // Detail page interactions (lightbox, copy link, flip, similar secrets)
            wp_enqueue_script(
                'ps-secret-detail',
                get_stylesheet_directory_uri() . '/assets/js/secret-detail.js',
                ['mustache'],
                filemtime(get_stylesheet_directory() . '/assets/js/secret-detail.js'),
                true
            );
        }
    }

    // Font Awesome 6
    wp_enqueue_style(
        'fa6',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css',
        [],
        null
    );

    // Dark mode overrides
    wp_enqueue_style(
        'ps-dark-overrides',
        get_stylesheet_directory_uri() . '/dark-overrides.css',
        [],
        null
    );

    // Layout fixes for header/footer constraints
    wp_enqueue_style(
        'ps-layout-fix',
        get_stylesheet_directory_uri() . '/assets/css/layout-fix.css',
        [],
        '1.0.0'
    );
});

// Make our child theme the default fallback (prevents "twentytwentyfive" errors)
if (!defined('WP_DEFAULT_THEME')) {
    define('WP_DEFAULT_THEME', 'ollie-child');
}

// Add shared Mustache card template to footer (used by front-page, search, and detail pages with Similar Secrets)
add_action('wp_footer', function () {
    // Only load on pages that need the card template
    if (is_front_page() || is_search() || (is_attachment() && get_post_meta(get_the_ID(), '_ps_side', true) === 'front')) {
        get_template_part('parts/card-mustache-template');
    }
});