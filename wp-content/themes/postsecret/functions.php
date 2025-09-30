<?php
declare(strict_types=1);

// Enqueue scripts and styles
add_action('wp_enqueue_scripts', function () {
    // Theme toggle script
    wp_enqueue_script(
        'ps-mode-toggle',
        get_stylesheet_directory_uri() . '/mode-toggle.js',
        [],
        null,
        true
    );

    // Secret metadata script (only on single secret pages)
    if (is_singular('secret')) {
        wp_enqueue_script(
            'ps-secret-metadata',
            get_stylesheet_directory_uri() . '/secret-metadata.js',
            [],
            null,
            true
        );

        // Pass post data to JavaScript
        global $post;
        if ($post && $post->post_type === 'secret') {
            $metadata = [];
            $meta_fields = [
                'nsfw_score', 'review_status', 'contains_pii', 'language',
                'font_style', 'color_cast', 'exposure', 'classification_json'
            ];

            foreach ($meta_fields as $field) {
                $metadata[$field] = get_post_meta($post->ID, $field, true);
            }

            // Get taxonomy terms
            $tags = get_the_terms($post->ID, 'secret_tag');
            $tag_data = [];
            if ($tags && !is_wp_error($tags)) {
                foreach ($tags as $tag) {
                    $tag_data[] = [
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                        'link' => get_term_link($tag)
                    ];
                }
            }

            wp_localize_script('ps-secret-metadata', 'psSecretData', [
                'postId' => $post->ID,
                'postDate' => get_the_date('c', $post->ID),
                'metadata' => $metadata,
                'tags' => $tag_data
            ]);
        }
    }

    // Search enhancements script (on search pages)
    if (is_search()) {
        wp_enqueue_script(
            'ps-search-enhancements',
            get_stylesheet_directory_uri() . '/search-enhancements.js',
            [],
            null,
            true
        );
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
});

// Make our child theme the default fallback (prevents "twentytwentyfive" errors)
if ( ! defined('WP_DEFAULT_THEME') ) {
    define('WP_DEFAULT_THEME', 'ollie-child');
}