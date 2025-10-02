<?php
/**
 * Manual migration runner for PostSecret Admin.
 *
 * Run this file directly to execute all pending migrations.
 * Or visit: wp-admin/admin.php?page=postsecret-run-migrations
 *
 * @package PostSecret\Admin
 */

// Load WordPress
// Path: /wp-content/plugins/postsecret-admin/ -> /wp-load.php
require_once __DIR__ . '/../../../wp-load.php';

if ( ! current_user_can( 'manage_options' ) && ! defined( 'WP_CLI' ) ) {
    wp_die( 'Unauthorized' );
}

global $wpdb;

echo "<h1>PostSecret Database Migrations</h1>\n";

$migrations_dir = __DIR__ . '/migrations/';
$migrations = glob( $migrations_dir . '*.php' );
sort( $migrations );

foreach ( $migrations as $migration_file ) {
    $migration_name = basename( $migration_file );
    echo "<p>Running migration: <strong>{$migration_name}</strong>...";

    // Create isolated scope and execute
    $result = ( function() use ( $migration_file, $wpdb ) {
        ob_start();
        try {
            require $migration_file;
            \PostSecret\Admin\Migrations\up();
            $output = ob_get_clean();
            return [ 'success' => true, 'output' => $output ];
        } catch ( \Throwable $e ) {
            $output = ob_get_clean();
            return [ 'success' => false, 'error' => $e->getMessage(), 'output' => $output ];
        }
    } )();

    if ( $result['success'] ) {
        echo " <span style='color:green;'>✓ Success</span></p>\n";
        if ( ! empty( $result['output'] ) ) {
            echo "<pre>" . esc_html( $result['output'] ) . "</pre>\n";
        }
    } else {
        echo " <span style='color:red;'>✗ Failed</span></p>\n";
        echo "<p style='color:red;'>Error: " . esc_html( $result['error'] ) . "</p>\n";
        if ( ! empty( $result['output'] ) ) {
            echo "<pre>" . esc_html( $result['output'] ) . "</pre>\n";
        }
    }
}

echo "<p><strong>Migrations complete!</strong></p>\n";
echo "<p><a href='" . admin_url() . "'>← Back to Dashboard</a></p>\n";
