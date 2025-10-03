<?php
/**
 * Migration: Add input_hash column to ps_text_embeddings table.
 *
 * The input_hash column stores a SHA-256 hash of the embedding input
 * (topics, feelings, meanings, text) to avoid regenerating embeddings
 * when the input hasn't changed.
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Migrations;

/**
 * Run the migration.
 *
 * @global \wpdb $wpdb
 */
function up_004_embeddings_input_hash() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ps_text_embeddings';

    // Check if column already exists
    $column_exists = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s
             AND TABLE_NAME = %s
             AND COLUMN_NAME = 'input_hash'",
            DB_NAME,
            $table_name
        )
    );

    if (empty($column_exists)) {
        $wpdb->query(
            "ALTER TABLE $table_name
             ADD COLUMN input_hash varchar(64) NULL AFTER dimension"
        );
    }
}
