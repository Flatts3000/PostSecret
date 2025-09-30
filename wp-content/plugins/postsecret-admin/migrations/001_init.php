<?php
/**
 * Initial database schema for PostSecret admin.
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

    $table_name_classification = $wpdb->prefix . 'ps_classification';
    $table_name_audit          = $wpdb->prefix . 'ps_audit_log';
    $table_name_tag_alias      = $wpdb->prefix . 'ps_tag_alias';

    $sql = "
        CREATE TABLE $table_name_classification (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            secret_id bigint(20) unsigned NOT NULL,
            text longtext NOT NULL,
            descriptors text,
            confidences text,
            PRIMARY KEY  (id),
            KEY secret_id (secret_id)
        ) $charset_collate;

        CREATE TABLE $table_name_audit (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor_id bigint(20) unsigned NOT NULL,
            action varchar(191) NOT NULL,
            context longtext,
            timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY actor_id (actor_id)
        ) $charset_collate;

        CREATE TABLE $table_name_tag_alias (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            alias varchar(191) NOT NULL,
            canonical varchar(191) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY alias (alias)
        ) $charset_collate;
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
