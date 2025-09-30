<?php
/**
 * Accessibility helpers.
 *
 * @package PostSecret
 */

namespace PostSecret\Theme\Inc;

/**
 * Skip link for screen readers.
 */
function skip_to_content() {
    echo '<a class="skip-link screen-reader-text" href="#primary">' . esc_html__( 'Skip to content', 'postsecret' ) . '</a>';
}
add_action( 'wp_body_open', __NAMESPACE__ . '\\skip_to_content' );
