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

/**
 * Filter attachment permalinks to use /secrets/{id}/ for front-side secrets.
 *
 * Since secrets are stored as attachments with _ps_side='front', we intercept
 * their permalinks and route them to our custom secret detail page.
 *
 * @param string $link The attachment permalink.
 * @param int    $post_id The attachment ID.
 * @return string Modified permalink.
 */
function filter_secret_attachment_permalink( $link, $post_id ) {
    // Only apply to attachments marked as 'front' side secrets
    $side = get_post_meta( $post_id, '_ps_side', true );

    if ( $side !== 'front' ) {
        return $link;
    }

    // Generate clean permalink: /secrets/{id}/
    return home_url( '/secrets/' . $post_id . '/' );
}
add_filter( 'attachment_link', __NAMESPACE__ . '\\filter_secret_attachment_permalink', 10, 2 );

/**
 * Add rewrite rule to route /secrets/{id}/ to our template.
 *
 * This catches URLs like /secrets/123/ and routes them to single-secret.php
 * with the attachment ID as a query var.
 */
function add_secret_rewrite_rules() {
    // Add the rewrite tag first
    add_rewrite_tag( '%secret_id%', '([0-9]+)' );
    add_rewrite_tag( '%time_machine%', '([^/]+)' );

    // Add the rewrite rule
    add_rewrite_rule(
        '^secrets/([0-9]+)/?$',
        'index.php?secret_id=$matches[1]',
        'top'
    );

    // Add Time Machine rewrite rule
    add_rewrite_rule(
        '^time-machine/?$',
        'index.php?time_machine=1',
        'top'
    );
}
add_action( 'init', __NAMESPACE__ . '\\add_secret_rewrite_rules', 10, 0 );

/**
 * Register query vars for secret routing.
 *
 * @param array $vars Existing query vars.
 * @return array Modified query vars.
 */
function add_secret_query_vars( $vars ) {
    $vars[] = 'secret_id';
    $vars[] = 'time_machine';
    return $vars;
}
add_filter( 'query_vars', __NAMESPACE__ . '\\add_secret_query_vars' );

/**
 * Ensure single-secret.php template is used for secret attachments.
 *
 * When WordPress loads an attachment page for a front-side secret,
 * redirect to use our custom single-secret.php template.
 *
 * @param string $template The path to the template.
 * @return string Modified template path.
 */
function use_secret_template_for_attachments( $template ) {
    // Handle Time Machine page
    if ( get_query_var( 'time_machine' ) ) {
        $time_machine_template = locate_template( 'page-time-machine.php' );
        if ( $time_machine_template ) {
            return $time_machine_template;
        }
    }

    // Handle direct attachment URLs (e.g., ?attachment_id=123)
    if ( is_attachment() ) {
        $post_id = get_queried_object_id();
        $side = get_post_meta( $post_id, '_ps_side', true );

        if ( $side === 'front' ) {
            $secret_template = locate_template( 'single-secret.php' );
            if ( $secret_template ) {
                return $secret_template;
            }
        }
    }

    // Handle rewrite rule URLs (e.g., /secrets/123/)
    global $wp_query, $post;
    $secret_id = get_query_var( 'secret_id' );

    if ( $secret_id ) {
        // Set up the post data
        $attachment = get_post( $secret_id );
        if ( $attachment && $attachment->post_type === 'attachment' ) {
            $side = get_post_meta( $secret_id, '_ps_side', true );

            if ( $side === 'front' ) {
                // Completely set up the WordPress query as if this is an attachment page
                $wp_query->posts = array( $attachment );
                $wp_query->post_count = 1;
                $wp_query->found_posts = 1;
                $wp_query->max_num_pages = 1;
                $wp_query->is_404 = false;
                $wp_query->is_attachment = true;
                $wp_query->is_single = true;
                $wp_query->is_singular = true;
                $wp_query->queried_object = $attachment;
                $wp_query->queried_object_id = $secret_id;
                $wp_query->post = $attachment;
                $post = $attachment;

                // Set up global post data
                setup_postdata( $attachment );

                $secret_template = locate_template( 'single-secret.php' );
                if ( $secret_template ) {
                    return $secret_template;
                }
            }
        }
    }

    return $template;
}
add_filter( 'template_include', __NAMESPACE__ . '\\use_secret_template_for_attachments', 99 );

/**
 * One-time flush of rewrite rules on theme activation.
 *
 * TEMPORARY: This will auto-flush rules once. Remove this function after
 * the site has loaded once with the new routing code.
 */
function flush_rewrite_rules_once() {
    $flushed = get_option( 'ps_rewrite_rules_flushed' );

    if ( ! $flushed ) {
        flush_rewrite_rules();
        update_option( 'ps_rewrite_rules_flushed', true );

        // Add debug flag
        error_log( 'PostSecret: Rewrite rules flushed automatically.' );
    }
}
add_action( 'init', __NAMESPACE__ . '\\flush_rewrite_rules_once', 999 );

/**
 * Debug helper: Force flush via URL parameter.
 *
 * Visit: /?ps_flush_rewrites=1 to manually trigger flush.
 * REMOVE THIS AFTER TESTING.
 */
function debug_flush_rewrites() {
    if ( isset( $_GET['ps_flush_rewrites'] ) && current_user_can( 'manage_options' ) ) {
        flush_rewrite_rules();
        delete_option( 'ps_rewrite_rules_flushed' ); // Reset auto-flush flag
        wp_die( 'Rewrite rules flushed! <a href="' . home_url( '/secrets/112/' ) . '">Test /secrets/112/</a>' );
    }
}
add_action( 'init', __NAMESPACE__ . '\\debug_flush_rewrites', 1 );

/**
 * Debug helper: Check rewrite rules and attachment.
 *
 * Visit: /?ps_debug_routing=1 to see routing info.
 * REMOVE THIS AFTER TESTING.
 */
function debug_routing() {
    if ( isset( $_GET['ps_debug_routing'] ) && current_user_can( 'manage_options' ) ) {
        global $wp_rewrite;

        $attachment = get_post( 112 );
        $side = get_post_meta( 112, '_ps_side', true );
        $permalink = get_permalink( 112 );

        echo '<h1>Routing Debug</h1>';

        echo '<h2>Permalink Settings</h2>';
        echo '<pre>';
        $permalink_structure = get_option( 'permalink_structure' );
        echo 'Permalink Structure: ' . ( $permalink_structure ?: 'PLAIN (not enabled!)' ) . "\n";
        echo 'Using Permalinks: ' . ( $permalink_structure ? 'Yes' : 'No' ) . "\n";
        echo '</pre>';

        echo '<h2>Attachment 112</h2>';
        echo '<pre>';
        echo 'Exists: ' . ( $attachment ? 'Yes' : 'No' ) . "\n";
        if ( $attachment ) {
            echo 'Post Type: ' . $attachment->post_type . "\n";
            echo 'Post Status: ' . $attachment->post_status . "\n";
        }
        echo 'Side Meta: ' . ( $side ?: 'EMPTY' ) . "\n";
        echo 'Permalink: ' . $permalink . "\n";
        echo '</pre>';

        echo '<h2>Rewrite Rules</h2>';
        echo '<pre>';
        $rules = get_option( 'rewrite_rules' );
        if ( $rules ) {
            $found = false;
            foreach ( $rules as $pattern => $rewrite ) {
                if ( strpos( $pattern, 'secret' ) !== false ) {
                    echo $pattern . ' => ' . $rewrite . "\n";
                    $found = true;
                }
            }
            if ( ! $found ) {
                echo "NO SECRET RULES FOUND!\n\n";
                echo "All rules (first 10):\n";
                $count = 0;
                foreach ( $rules as $pattern => $rewrite ) {
                    echo $pattern . ' => ' . $rewrite . "\n";
                    if ( ++$count >= 10 ) break;
                }
            }
        } else {
            echo "No rewrite rules at all!\n";
        }
        echo '</pre>';

        echo '<h2>Test Links</h2>';
        echo '<ul>';
        echo '<li><a href="' . home_url( '/secrets/112/' ) . '">/secrets/112/</a></li>';
        echo '<li><a href="' . home_url( '/?attachment_id=112' ) . '">?attachment_id=112</a></li>';
        echo '<li><a href="' . $permalink . '">' . $permalink . '</a></li>';
        echo '</ul>';

        wp_die();
    }
}
add_action( 'init', __NAMESPACE__ . '\\debug_routing', 1 );
