<?php
/**
 * Taxonomy service.
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Services;

class TaxonomyService {
    /**
     * Merge two tags into one.
     *
     * @param string $source Source tag.
     * @param string $target Target tag.
     * @return bool True on success.
     */
    public function merge_tags( string $source, string $target ): bool {
        // TODO: Implement tag merge logic.
        return true;
    }

    /**
     * Create an alias for a tag.
     *
     * @param string $alias Alias.
     * @param string $canonical Canonical tag.
     * @return bool True on success.
     */
    public function create_alias( string $alias, string $canonical ): bool {
        // TODO: Implement alias creation logic.
        return true;
    }
}
