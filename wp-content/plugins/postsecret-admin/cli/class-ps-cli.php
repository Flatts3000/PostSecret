<?php
/**
 * WP-CLI commands for PostSecret.
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\CLI;

use WP_CLI;

/**
 * Handles backfill operations.
 */
class PS_CLI {
    /**
     * Register commands.
     */
    public static function register() {
        WP_CLI::add_command( 'postsecret backfill', [ static::class, 'backfill' ] );
    }

    /**
     * Start the backfill process.
     *
     * @synopsis [--batch=<number>] [--rate=<number>]
     *
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public static function backfill( $args, $assoc_args ) {
        $batch = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 100;
        $rate  = isset( $assoc_args['rate'] ) ? intval( $assoc_args['rate'] ) : 10;

        // TODO: Implement backfill logic using BackfillService.
        WP_CLI::success( "Backfill started with batch {$batch} and rate {$rate}." );
    }
}

// Register commands if WP_CLI is defined.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    \PostSecret\Admin\CLI\PS_CLI::register();
}
