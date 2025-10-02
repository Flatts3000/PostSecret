<?php
/**
 * Migration: Create ps_text_embeddings table for semantic search.
 *
 * Stores embedding vectors for each Secret to enable:
 * - Semantic similarity search
 * - "Find similar" functionality
 * - Topic/feeling/meaning clustering
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Migrations;

/**
 * Run the migration.
 *
 * @global \wpdb $wpdb
 */
function up() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'ps_text_embeddings';

    $sql = "
        CREATE TABLE $table_name (
            secret_id bigint(20) unsigned NOT NULL,
            model_version varchar(32) NOT NULL,
            embedding longtext NOT NULL,
            dimension smallint unsigned NOT NULL DEFAULT 1536,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (secret_id),
            KEY model_version (model_version),
            KEY updated_at (updated_at)
        ) $charset_collate;
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
