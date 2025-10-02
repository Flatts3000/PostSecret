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
     * @param string $query    Search query.
     * @param array  $facets   Facet filters (topics, feelings, meanings).
     * @param int    $page     Page number.
     * @param int    $per_page Results per page.
     * @return array Results with posts and pagination info.
     */
    public function search( string $query = '', array $facets = [], int $page = 1, int $per_page = 24 ): array {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        // Build meta query for facets
        $meta_query = [];

        if ( ! empty( $facets['topics'] ) ) {
            $meta_query[] = [
                'key'     => '_ps_topics',
                'value'   => $facets['topics'],
                'compare' => 'IN',
            ];
        }

        if ( ! empty( $facets['feelings'] ) ) {
            $meta_query[] = [
                'key'     => '_ps_feelings',
                'value'   => $facets['feelings'],
                'compare' => 'IN',
            ];
        }

        if ( ! empty( $facets['meanings'] ) ) {
            $meta_query[] = [
                'key'     => '_ps_meanings',
                'value'   => $facets['meanings'],
                'compare' => 'IN',
            ];
        }

        if ( ! empty( $meta_query ) ) {
            $meta_query['relation'] = 'AND';
            $args['meta_query']     = $meta_query;
        }

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
     * Get all unique facet values for filtering UI.
     *
     * @param string $facet_type One of: topics, feelings, meanings.
     * @return array Sorted unique values with counts.
     */
    public function get_facet_values( string $facet_type ): array {
        global $wpdb;

        $meta_key = '_ps_' . $facet_type;
        $results  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_value, COUNT(*) as count
                FROM {$wpdb->postmeta}
                WHERE meta_key = %s
                GROUP BY meta_value
                ORDER BY count DESC, meta_value ASC",
                $meta_key
            )
        );

        $facets = [];
        foreach ( $results as $row ) {
            $values = maybe_unserialize( $row->meta_value );
            if ( is_array( $values ) ) {
                foreach ( $values as $value ) {
                    if ( ! isset( $facets[ $value ] ) ) {
                        $facets[ $value ] = 0;
                    }
                    $facets[ $value ] += (int) $row->count;
                }
            }
        }

        arsort( $facets );
        return $facets;
    }
}
