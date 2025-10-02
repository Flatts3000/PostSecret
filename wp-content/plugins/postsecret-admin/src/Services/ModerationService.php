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

    /**
     * Update facets for a secret.
     *
     * @param int   $secret_id Secret attachment ID.
     * @param array $topics    Topics array.
     * @param array $feelings  Feelings array.
     * @param array $meanings  Meanings array.
     * @return bool True on success.
     */
    public function update_facets( int $secret_id, array $topics = [], array $feelings = [], array $meanings = [] ): bool {
        // Normalize and sort each facet array
        $topics   = array_values( array_filter( array_map( 'sanitize_text_field', $topics ) ) );
        $feelings = array_values( array_filter( array_map( 'sanitize_text_field', $feelings ) ) );
        $meanings = array_values( array_filter( array_map( 'sanitize_text_field', $meanings ) ) );

        sort( $topics );
        sort( $feelings );
        sort( $meanings );

        // Update post meta
        update_post_meta( $secret_id, '_ps_topics', $topics );
        update_post_meta( $secret_id, '_ps_feelings', $feelings );
        update_post_meta( $secret_id, '_ps_meanings', $meanings );

        // Also update in payload for consistency
        $payload = get_post_meta( $secret_id, '_ps_payload', true );
        if ( is_array( $payload ) ) {
            $payload['topics']   = $topics;
            $payload['feelings'] = $feelings;
            $payload['meanings'] = $meanings;
            update_post_meta( $secret_id, '_ps_payload', $payload );
        }

        return true;
    }

    /**
     * Get facets for a secret.
     *
     * @param int $secret_id Secret attachment ID.
     * @return array Facets organized by type.
     */
    public function get_facets( int $secret_id ): array {
        return [
            'topics'   => get_post_meta( $secret_id, '_ps_topics', true ) ?: [],
            'feelings' => get_post_meta( $secret_id, '_ps_feelings', true ) ?: [],
            'meanings' => get_post_meta( $secret_id, '_ps_meanings', true ) ?: [],
        ];
    }
}
