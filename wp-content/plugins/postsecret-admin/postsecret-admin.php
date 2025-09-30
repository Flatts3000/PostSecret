<?php
/**
 * Plugin Name: PostSecret Admin
 * Plugin URI: https://example.com/postsecret-admin
 * Description: Admin tools for managing the PostSecret archive.
 * Version: 0.1.0
 * Author: Your Name
 * Text Domain: postsecret-admin
 * Domain Path: /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Autoload classes via Composer.
 */
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PostSecret\Admin\Routes\SearchRoute;
use PostSecret\Admin\Routes\ReviewRoute;
use PostSecret\Admin\Routes\TaxonomyRoute;
use PostSecret\Admin\Routes\BackfillRoute;
use PostSecret\Admin\Routes\SettingsRoute;

/**
 * Bootstrap the admin plugin.
 */
function postsecret_admin_bootstrap() {
    new SearchRoute();
    new ReviewRoute();
    new TaxonomyRoute();
    new BackfillRoute();
    new SettingsRoute();
}
add_action( 'plugins_loaded', 'postsecret_admin_bootstrap' );

/**
 * Load plugin text domain.
 */
function postsecret_admin_load_textdomain() {
    load_plugin_textdomain( 'postsecret-admin', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'postsecret_admin_load_textdomain' );
