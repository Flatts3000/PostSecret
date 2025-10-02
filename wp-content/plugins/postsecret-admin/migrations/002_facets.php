<?php
/**
 * Migration: Replace tags with facets (topics, feelings, meanings).
 *
 * This migration removes the ps_tag_alias table (no longer needed)
 * since facets are stored as post meta arrays.
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Migrations;

/**
 * Run the migration.
 *
 * @global \wpdb $wpdb
 */
function up_002_facets() {
    global $wpdb;

    // Drop ps_tag_alias table - no longer needed with facets
    $table_name_tag_alias = $wpdb->prefix . 'ps_tag_alias';
    $wpdb->query( "DROP TABLE IF EXISTS $table_name_tag_alias" );

    // Note: Facets are stored as post meta:
    // - _ps_topics (array)
    // - _ps_feelings (array)
    // - _ps_meanings (array)
    // No additional tables needed - WordPress post meta handles arrays natively.
}
