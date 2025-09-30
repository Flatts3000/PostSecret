<?php
/**
 * Moderation service.
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Services;

class ModerationService {
    /**
     * Approve a secret.
     *
     * @param int $secret_id Secret post ID.
     * @return bool True on success.
     */
    public function approve( int $secret_id ): bool {
        // TODO: Implement approval logic.
        return true;
    }

    /**
     * Unpublish a secret.
     *
     * @param int $secret_id Secret post ID.
     * @return bool True on success.
     */
    public function unpublish( int $secret_id ): bool {
        // TODO: Implement unpublish logic.
        return true;
    }
}
