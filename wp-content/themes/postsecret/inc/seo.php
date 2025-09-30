<?php
/**
 * SEO helpers.
 *
 * @package PostSecret
 */

namespace PostSecret\Theme\Inc;

/**
 * Output meta description tag.
 */
function meta_description() {
    if ( is_singular() ) {
        $excerpt = get_the_excerpt();
        echo '<meta name="description" content="' . esc_attr( wp_trim_words( $excerpt, 30, '...' ) ) . '" />' . "\n";
    }
}
add_action( 'wp_head', __NAMESPACE__ . '\\meta_description' );
