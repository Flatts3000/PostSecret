<?php
/**
 * Migration: Add FULLTEXT index for post_content search.
 *
 * Enables fast full-text search on extracted OCR text stored in wp_posts.post_content.
 * MySQL FULLTEXT indexes provide much better performance than LIKE queries for text search.
 *
 * Benefits:
 * - Relevance scoring with MATCH AGAINST
 * - Natural language search with stopwords and stemming
 * - Boolean mode for complex queries
 * - ~100x faster than LIKE '%term%' on large datasets
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Migrations;

/**
 * Run the migration.
 *
 * @global \wpdb $wpdb
 */
function up_007_fulltext_search() {
    global $wpdb;

    $posts_table = $wpdb->posts;

    // Check if FULLTEXT index already exists
    $existing_indexes = $wpdb->get_results(
        "SHOW INDEX FROM {$posts_table} WHERE Index_type = 'FULLTEXT'"
    );

    $has_fulltext = false;
    foreach ($existing_indexes as $index) {
        if ($index->Column_name === 'post_content') {
            $has_fulltext = true;
            break;
        }
    }

    // Add FULLTEXT index if it doesn't exist
    if (!$has_fulltext) {
        $wpdb->query(
            "ALTER TABLE {$posts_table}
             ADD FULLTEXT INDEX ps_content_fulltext (post_content)"
        );
    }
}
