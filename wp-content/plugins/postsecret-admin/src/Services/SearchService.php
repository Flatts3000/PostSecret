<?php
/**
 * Search service.
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Services;

class SearchService {
    /**
     * Execute a search query against secrets.
     *
     * @param string $query      Search query.
     * @param array  $facets     Facet filters (topics, feelings, meanings, style, locations, vibe).
     * @param int    $page       Page number.
     * @param int    $per_page   Results per page.
     * @param string $start_date Optional start date (YYYY-MM-DD format).
     * @param string $end_date   Optional end date (YYYY-MM-DD format).
     * @return array Results with posts and pagination info.
     */
    public function search( string $query = '', array $facets = [], int $page = 1, int $per_page = 24, string $start_date = '', string $end_date = '' ): array {
        global $wpdb;

        // Check if we have facet filters or date filters
        $has_facets = false;
        foreach ( [ 'topics', 'feelings', 'meanings', 'style', 'locations', 'vibe' ] as $type ) {
            if ( ! empty( $facets[ $type ] ) ) {
                $has_facets = true;
                break;
            }
        }

        $has_date_filter = ! empty( $start_date ) || ! empty( $end_date );

        // If we have facet or date filters, use optimized JOIN approach
        if ( $has_facets || $has_date_filter ) {
            return $this->search_with_facets( $query, $facets, $page, $per_page, $start_date, $end_date );
        }

        // Otherwise use standard WP_Query for better compatibility
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        // Add text search if query provided
        if ( ! empty( $query ) ) {
            $args['s'] = $query;
        }

        $wp_query = new \WP_Query( $args );

        return [
            'posts'       => $wp_query->posts,
            'total'       => $wp_query->found_posts,
            'total_pages' => $wp_query->max_num_pages,
            'page'        => $page,
            'per_page'    => $per_page,
        ];
    }

    /**
     * Search with facet filters using optimized JOIN approach.
     *
     * @param string $query      Search query.
     * @param array  $facets     Facet filters.
     * @param int    $page       Page number.
     * @param int    $per_page   Results per page.
     * @param string $start_date Optional start date.
     * @param string $end_date   Optional end date.
     * @return array Results with posts and pagination info.
     */
    private function search_with_facets( string $query, array $facets, int $page, int $per_page, string $start_date = '', string $end_date = '' ): array {
        global $wpdb;

        $facet_table = $wpdb->prefix . 'ps_secret_facets';
        $posts_table = $wpdb->posts;
        $postmeta_table = $wpdb->postmeta;

        // Build JOIN clauses for each facet type
        $joins = [];
        $where_clauses = [];
        $join_index = 0;

        foreach ( [ 'topics', 'feelings', 'meanings', 'style', 'locations', 'vibe' ] as $facet_type ) {
            if ( ! empty( $facets[ $facet_type ] ) ) {
                $alias = "f{$join_index}";
                $placeholders = implode( ',', array_fill( 0, count( $facets[ $facet_type ] ), '%s' ) );

                $joins[] = "INNER JOIN {$facet_table} AS {$alias} ON p.ID = {$alias}.secret_id";
                $where_clauses[] = $wpdb->prepare(
                    "{$alias}.facet_type = %s AND {$alias}.facet_value IN ({$placeholders})",
                    array_merge( [ $facet_type ], $facets[ $facet_type ] )
                );

                $join_index++;
            }
        }

        // LEFT JOIN for submission_date (optional field)
        $joins[] = "LEFT JOIN {$postmeta_table} AS pm_subdate ON p.ID = pm_subdate.post_id AND pm_subdate.meta_key = '_ps_submission_date'";

        // Build WHERE clause
        $where = "p.post_type = 'attachment' AND p.post_status = 'inherit'";

        if ( ! empty( $where_clauses ) ) {
            $where .= ' AND (' . implode( ') AND (', $where_clauses ) . ')';
        }

        // Add date range filtering (use submission_date if available, fallback to post_date)
        if ( ! empty( $start_date ) ) {
            $where .= $wpdb->prepare(
                " AND COALESCE(pm_subdate.meta_value, DATE(p.post_date)) >= %s",
                $start_date
            );
        }

        if ( ! empty( $end_date ) ) {
            $where .= $wpdb->prepare(
                " AND COALESCE(pm_subdate.meta_value, DATE(p.post_date)) <= %s",
                $end_date
            );
        }

        // Add text search if provided - use FULLTEXT for better performance
        $order_by = 'p.post_date DESC';
        if ( ! empty( $query ) ) {
            // Use FULLTEXT search with relevance scoring
            $search_term = $wpdb->esc_like( $query );
            $where .= $wpdb->prepare(
                " AND MATCH(p.post_content) AGAINST(%s IN NATURAL LANGUAGE MODE)",
                $search_term
            );
            // Order by relevance when searching, then by date
            $order_by = $wpdb->prepare(
                "MATCH(p.post_content) AGAINST(%s IN NATURAL LANGUAGE MODE) DESC, p.post_date DESC",
                $search_term
            );
        }

        // Count total matching posts
        $count_sql = "SELECT COUNT(DISTINCT p.ID)
                      FROM {$posts_table} AS p
                      " . implode( ' ', $joins ) . "
                      WHERE {$where}";

        $total = (int) $wpdb->get_var( $count_sql );

        // Get paginated results
        $offset = ( $page - 1 ) * $per_page;
        $results_sql = "SELECT DISTINCT p.ID
                        FROM {$posts_table} AS p
                        " . implode( ' ', $joins ) . "
                        WHERE {$where}
                        ORDER BY {$order_by}
                        LIMIT %d OFFSET %d";

        $post_ids = $wpdb->get_col(
            $wpdb->prepare( $results_sql, $per_page, $offset )
        );

        // Load WP_Post objects
        $posts = [];
        if ( ! empty( $post_ids ) ) {
            $posts = array_map( 'get_post', $post_ids );
        }

        return [
            'posts'       => $posts,
            'total'       => $total,
            'total_pages' => ceil( $total / $per_page ),
            'page'        => $page,
            'per_page'    => $per_page,
        ];
    }

    /**
     * Get all unique facet values for filtering UI.
     *
     * Optimized version using ps_secret_facets junction table.
     *
     * @param string $facet_type One of: topics, feelings, meanings, style, locations, vibe.
     * @return array Array of arrays with 'value' and 'count' keys, sorted by count DESC.
     */
    public function get_facet_values( string $facet_type ): array {
        global $wpdb;

        $facet_table = $wpdb->prefix . 'ps_secret_facets';

        // Fast aggregation query with proper indexes
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT facet_value AS value, COUNT(*) AS count
                 FROM {$facet_table}
                 WHERE facet_type = %s
                 GROUP BY facet_value
                 ORDER BY count DESC, facet_value ASC",
                $facet_type
            ),
            ARRAY_A
        );

        return $results;
    }
}
