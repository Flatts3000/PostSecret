<?php
/**
 * Migration: Create ps_secret_facets junction table.
 *
 * Replaces inefficient serialized array storage in postmeta with a proper
 * normalized table for facet filtering and aggregation.
 *
 * Benefits:
 * - Fast facet filtering with proper indexes
 * - Efficient facet counting for UI dropdowns
 * - Supports composite filters (multiple facets AND logic)
 * - Scales to 1M+ secrets
 *
 * Facet types: topics, feelings, meanings, style, locations, vibe
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Migrations;

/**
 * Run the migration.
 *
 * @global \wpdb $wpdb
 */
function up_006_secret_facets() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'ps_secret_facets';

    $sql = "
        CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            secret_id bigint(20) unsigned NOT NULL,
            facet_type varchar(20) NOT NULL,
            facet_value varchar(255) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY secret_facet (secret_id, facet_type, facet_value),
            KEY facet_lookup (facet_type, facet_value),
            KEY secret_facets (secret_id, facet_type)
        ) $charset_collate;
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
