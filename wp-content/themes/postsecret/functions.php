<?php
/**
 * Theme functions and definitions.
 *
 * @package PostSecret
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueue theme assets.
 */
function postsecret_enqueue_scripts() {
    $theme_version = wp_get_theme()->get( 'Version' );

    wp_enqueue_style(
        'postsecret-style',
        get_template_directory_uri() . '/assets/css/style.css',
        [],
        $theme_version
    );

    wp_enqueue_script(
        'postsecret-script',
        get_template_directory_uri() . '/assets/js/main.js',
        [ 'jquery' ],
        $theme_version,
        true
    );
}
add_action( 'wp_enqueue_scripts', 'postsecret_enqueue_scripts' );

/**
 * Theme setup.
 */
function postsecret_setup() {
    // Let WordPress handle the document title.
    add_theme_support( 'title-tag' );

    // Register nav menus.
    register_nav_menus(
        [
            'primary' => __( 'Primary Menu', 'postsecret' ),
        ]
    );

    // Add support for featured images.
    add_theme_support( 'post-thumbnails' );

    // Load text domain for translation.
    load_theme_textdomain( 'postsecret', get_template_directory() . '/languages' );
}
add_action( 'after_setup_theme', 'postsecret_setup' );
