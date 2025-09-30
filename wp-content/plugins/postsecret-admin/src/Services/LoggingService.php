<?php
/**
 * Logging service.
 *
 * @package PostSecret\Admin
 */

namespace PostSecret\Admin\Services;

class LoggingService {
    /**
     * Append a log entry.
     *
     * @param int    $actor_id User ID of actor.
     * @param string $action   Action performed.
     * @param array  $context  Context data.
     */
    public function log( int $actor_id, string $action, array $context = [] ): void {
        // TODO: Implement audit logging, perhaps writing to custom DB table.
    }
}
