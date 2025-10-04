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
        WP_CLI::add_command( 'postsecret migrate-facets', [ static::class, 'migrate_facets' ] );
        WP_CLI::add_command( 'postsecret migrate-text', [ static::class, 'migrate_text' ] );
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

    /**
     * Migrate facets from postmeta to ps_secret_facets table.
     *
     * Reads serialized facet arrays from postmeta and writes to normalized junction table.
     * Safe to run multiple times (uses INSERT IGNORE for idempotency).
     *
     * ## OPTIONS
     *
     * [--batch=<number>]
     * : Number of secrets to process per batch
     * ---
     * default: 100
     * ---
     *
     * [--dry-run]
     * : Preview what would be migrated without writing to database
     *
     * ## EXAMPLES
     *
     *     # Migrate all facets with default batch size
     *     wp postsecret migrate-facets
     *
     *     # Dry run to see what would be migrated
     *     wp postsecret migrate-facets --dry-run
     *
     *     # Use larger batch size
     *     wp postsecret migrate-facets --batch=500
     *
     * @synopsis [--batch=<number>] [--dry-run]
     *
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public static function migrate_facets( $args, $assoc_args ) {
        global $wpdb;

        $batch_size = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 100;
        $dry_run = isset( $assoc_args['dry-run'] );

        $facet_types = [ 'topics', 'feelings', 'meanings', 'style', 'locations', 'vibe' ];
        $table_name = $wpdb->prefix . 'ps_secret_facets';

        WP_CLI::line( 'Starting facet migration...' );
        if ( $dry_run ) {
            WP_CLI::warning( 'DRY RUN MODE - No data will be written' );
        }

        // Get total count of secrets with facets
        $total = $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id)
             FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_ps_topics', '_ps_feelings', '_ps_meanings', '_ps_style', '_ps_locations', '_ps_vibe')"
        );

        if ( ! $total ) {
            WP_CLI::error( 'No secrets with facets found.' );
            return;
        }

        WP_CLI::line( "Found {$total} secrets with facets to migrate" );

        $progress = \WP_CLI\Utils\make_progress_bar( 'Migrating facets', $total );

        $offset = 0;
        $migrated_count = 0;
        $facet_count = 0;

        while ( $offset < $total ) {
            // Get batch of secret IDs with facets
            $secret_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT post_id
                     FROM {$wpdb->postmeta}
                     WHERE meta_key IN ('_ps_topics', '_ps_feelings', '_ps_meanings', '_ps_style', '_ps_locations', '_ps_vibe')
                     ORDER BY post_id ASC
                     LIMIT %d OFFSET %d",
                    $batch_size,
                    $offset
                )
            );

            if ( empty( $secret_ids ) ) {
                break;
            }

            foreach ( $secret_ids as $secret_id ) {
                foreach ( $facet_types as $facet_type ) {
                    $meta_key = '_ps_' . $facet_type;
                    $facet_values = get_post_meta( $secret_id, $meta_key, true );

                    if ( ! empty( $facet_values ) && is_array( $facet_values ) ) {
                        foreach ( $facet_values as $facet_value ) {
                            if ( ! empty( $facet_value ) ) {
                                if ( ! $dry_run ) {
                                    // INSERT IGNORE for idempotency
                                    $wpdb->query(
                                        $wpdb->prepare(
                                            "INSERT IGNORE INTO {$table_name} (secret_id, facet_type, facet_value)
                                             VALUES (%d, %s, %s)",
                                            $secret_id,
                                            $facet_type,
                                            $facet_value
                                        )
                                    );
                                }
                                $facet_count++;
                            }
                        }
                    }
                }

                $migrated_count++;
                $progress->tick();
            }

            $offset += $batch_size;
        }

        $progress->finish();

        if ( $dry_run ) {
            WP_CLI::success( "DRY RUN: Would migrate {$facet_count} facet entries for {$migrated_count} secrets" );
        } else {
            WP_CLI::success( "Migrated {$facet_count} facet entries for {$migrated_count} secrets" );
        }
    }

    /**
     * Migrate extracted text from payload to post_content for FULLTEXT search.
     *
     * Reads extracted text from _ps_payload and writes to wp_posts.post_content
     * for optimized MySQL FULLTEXT searching.
     *
     * ## OPTIONS
     *
     * [--batch=<number>]
     * : Number of secrets to process per batch
     * ---
     * default: 100
     * ---
     *
     * [--dry-run]
     * : Preview what would be migrated without writing to database
     *
     * ## EXAMPLES
     *
     *     # Migrate all text with default batch size
     *     wp postsecret migrate-text
     *
     *     # Dry run to see what would be migrated
     *     wp postsecret migrate-text --dry-run
     *
     *     # Use larger batch size
     *     wp postsecret migrate-text --batch=500
     *
     * @synopsis [--batch=<number>] [--dry-run]
     *
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public static function migrate_text( $args, $assoc_args ) {
        global $wpdb;

        $batch_size = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 100;
        $dry_run = isset( $assoc_args['dry-run'] );

        WP_CLI::line( 'Starting text migration to post_content...' );
        if ( $dry_run ) {
            WP_CLI::warning( 'DRY RUN MODE - No data will be written' );
        }

        // Get total count of secrets with payloads
        $total = $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id)
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_ps_payload'"
        );

        if ( ! $total ) {
            WP_CLI::error( 'No secrets with payloads found.' );
            return;
        }

        WP_CLI::line( "Found {$total} secrets with payloads to migrate" );

        $progress = \WP_CLI\Utils\make_progress_bar( 'Migrating text', $total );

        $offset = 0;
        $migrated_count = 0;
        $text_count = 0;

        while ( $offset < $total ) {
            // Get batch of secret IDs with payloads
            $secret_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT post_id
                     FROM {$wpdb->postmeta}
                     WHERE meta_key = '_ps_payload'
                     ORDER BY post_id ASC
                     LIMIT %d OFFSET %d",
                    $batch_size,
                    $offset
                )
            );

            if ( empty( $secret_ids ) ) {
                break;
            }

            foreach ( $secret_ids as $secret_id ) {
                $payload = get_post_meta( $secret_id, '_ps_payload', true );

                if ( ! empty( $payload ) && is_array( $payload ) ) {
                    $full_text = trim( (string) ( $payload['text']['fullText'] ?? '' ) );

                    if ( ! empty( $full_text ) ) {
                        if ( ! $dry_run ) {
                            wp_update_post( [
                                'ID' => $secret_id,
                                'post_content' => $full_text,
                            ] );
                        }
                        $text_count++;
                    }
                }

                $migrated_count++;
                $progress->tick();
            }

            $offset += $batch_size;
        }

        $progress->finish();

        if ( $dry_run ) {
            WP_CLI::success( "DRY RUN: Would migrate text for {$text_count} of {$migrated_count} secrets" );
        } else {
            WP_CLI::success( "Migrated text for {$text_count} of {$migrated_count} secrets" );
        }
    }
}

// Register commands if WP_CLI is defined.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    \PostSecret\Admin\CLI\PS_CLI::register();
}
