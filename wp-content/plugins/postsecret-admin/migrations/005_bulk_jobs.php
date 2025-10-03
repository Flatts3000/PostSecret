<?php
/**
 * Migration: Bulk Jobs Tables
 * -----------------------------------------------------------------------------
 * Creates tables for bulk upload job management (psai-bulk):
 * - psai_bulk_jobs: Job records
 * - psai_bulk_items: Individual file items per job
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Migrations;

/**
 * Run the migration.
 *
 * @global \wpdb $wpdb
 */
function up_005_bulk_jobs(): void
{
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Jobs table
    $table_jobs = $wpdb->prefix . 'psai_bulk_jobs';
    $sql_jobs = "CREATE TABLE {$table_jobs} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        uuid varchar(36) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'new',
        source varchar(255) NOT NULL,
        staging_path varchar(500) NOT NULL,
        total_items int(11) unsigned NOT NULL DEFAULT 0,
        processed_items int(11) unsigned NOT NULL DEFAULT 0,
        success_count int(11) unsigned NOT NULL DEFAULT 0,
        fail_count int(11) unsigned NOT NULL DEFAULT 0,
        last_error text,
        settings text,
        started_at datetime DEFAULT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uuid (uuid),
        KEY status (status),
        KEY created_at (created_at)
    ) {$charset_collate};";

    // Items table
    $table_items = $wpdb->prefix . 'psai_bulk_items';
    $sql_items = "CREATE TABLE {$table_items} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        job_id bigint(20) unsigned NOT NULL,
        file_path varchar(500) NOT NULL,
        sha256 varchar(64) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        attachment_id bigint(20) unsigned DEFAULT NULL,
        attempts int(11) unsigned NOT NULL DEFAULT 0,
        last_error text,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY job_id (job_id),
        KEY status (status),
        KEY sha256 (sha256),
        KEY attachment_id (attachment_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_jobs);
    dbDelta($sql_items);
}
