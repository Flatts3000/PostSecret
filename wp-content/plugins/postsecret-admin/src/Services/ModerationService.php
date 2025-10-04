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
     * @param array $vibe      Vibe array (optional).
     * @param array $locations Locations array (optional).
     * @param string $style    Style value (optional).
     * @return bool True on success.
     */
    public function update_facets( int $secret_id, array $topics = [], array $feelings = [], array $meanings = [], array $vibe = [], array $locations = [], string $style = '' ): bool {
        // Normalize and sort each facet array
        $topics   = array_values( array_filter( array_map( 'sanitize_text_field', $topics ) ) );
        $feelings = array_values( array_filter( array_map( 'sanitize_text_field', $feelings ) ) );
        $meanings = array_values( array_filter( array_map( 'sanitize_text_field', $meanings ) ) );
        $vibe     = array_values( array_filter( array_map( 'sanitize_text_field', $vibe ) ) );
        $locations = array_values( array_filter( array_map( 'sanitize_text_field', $locations ) ) );
        $style    = sanitize_text_field( $style );

        sort( $topics );
        sort( $feelings );
        sort( $meanings );
        sort( $vibe );
        sort( $locations );

        // Update post meta
        update_post_meta( $secret_id, '_ps_topics', $topics );
        update_post_meta( $secret_id, '_ps_feelings', $feelings );
        update_post_meta( $secret_id, '_ps_meanings', $meanings );
        update_post_meta( $secret_id, '_ps_vibe', $vibe );
        update_post_meta( $secret_id, '_ps_locations', $locations );
        if ( ! empty( $style ) ) {
            update_post_meta( $secret_id, '_ps_style', $style );
        }

        // Also update in payload for consistency
        $payload = get_post_meta( $secret_id, '_ps_payload', true );
        if ( is_array( $payload ) ) {
            $payload['topics']   = $topics;
            $payload['feelings'] = $feelings;
            $payload['meanings'] = $meanings;
            $payload['vibe']     = $vibe;
            $payload['locations'] = $locations;
            if ( ! empty( $style ) ) {
                $payload['style'] = $style;
            }
            update_post_meta( $secret_id, '_ps_payload', $payload );
        }

        // Sync to facet junction table for optimized search
        global $wpdb;
        $table_name = $wpdb->prefix . 'ps_secret_facets';

        // Delete existing facets for this secret
        $wpdb->delete( $table_name, [ 'secret_id' => $secret_id ], [ '%d' ] );

        // Insert new facets
        $facets_to_sync = [
            'topics'    => $topics,
            'feelings'  => $feelings,
            'meanings'  => $meanings,
            'vibe'      => $vibe,
            'locations' => $locations,
        ];

        if ( ! empty( $style ) ) {
            $facets_to_sync['style'] = [ $style ];
        }

        foreach ( $facets_to_sync as $facet_type => $values ) {
            if ( ! empty( $values ) && is_array( $values ) ) {
                foreach ( $values as $value ) {
                    if ( ! empty( $value ) ) {
                        $wpdb->insert(
                            $table_name,
                            [
                                'secret_id'  => $secret_id,
                                'facet_type' => $facet_type,
                                'facet_value' => $value,
                            ],
                            [ '%d', '%s', '%s' ]
                        );
                    }
                }
            }
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
            'topics'    => get_post_meta( $secret_id, '_ps_topics', true ) ?: [],
            'feelings'  => get_post_meta( $secret_id, '_ps_feelings', true ) ?: [],
            'meanings'  => get_post_meta( $secret_id, '_ps_meanings', true ) ?: [],
            'vibe'      => get_post_meta( $secret_id, '_ps_vibe', true ) ?: [],
            'locations' => get_post_meta( $secret_id, '_ps_locations', true ) ?: [],
            'style'     => get_post_meta( $secret_id, '_ps_style', true ) ?: '',
        ];
    }
}
