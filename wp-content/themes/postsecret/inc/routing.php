<?php
/**
 * Custom rewrite rules and routing.
 *
 * @package PostSecret
 */

namespace PostSecret\Theme\Inc;

/**
 * Register custom post type for secrets.
 */
function register_secret_cpt() {
    $labels = [
        'name'          => __( 'Secrets', 'postsecret' ),
        'singular_name' => __( 'Secret', 'postsecret' ),
    ];

    $args = [
        'labels'             => $labels,
        'public'             => true,
        'has_archive'        => true,
        'rewrite'            => [ 'slug' => 'secrets' ],
        'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'tags' ],
        'show_in_rest'       => true,
    ];

    register_post_type( 'secret', $args );
}
add_action( 'init', __NAMESPACE__ . '\\register_secret_cpt' );
