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
     * @param string $query Search query.
     * @param array  $tags  Tag filters.
     * @param int    $page  Page number.
     * @return array Results.
     */
    public function search( string $query, array $tags = [], int $page = 1 ): array {
        // TODO: Implement actual search logic (WP_Query or custom DB).
        return [];
    }
}
