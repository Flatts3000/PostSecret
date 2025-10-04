<?php
/**
 * AJAX handler for lazy-loading facet values.
 *
 * @package PostSecret
 */

use PostSecret\Admin\Services\SearchService;

/**
 * Load more facet values via AJAX.
 */
function ps_load_more_facets() {
	// Verify nonce
	check_ajax_referer( 'ps_facets_nonce', 'nonce' );

	$facet_type = isset( $_POST['facet_type'] ) ? sanitize_text_field( $_POST['facet_type'] ) : '';
	$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
	$limit      = 20; // Load 20 at a time

	if ( ! in_array( $facet_type, array( 'feelings', 'locations', 'meanings', 'style', 'topics', 'vibe' ), true ) ) {
		wp_send_json_error( array( 'message' => 'Invalid facet type' ) );
	}

	// Get cached facet values
	$cache_key    = 'ps_facets_' . $facet_type;
	$cache_group  = 'postsecret_facets';
	$all_facets   = wp_cache_get( $cache_key, $cache_group );

	if ( false === $all_facets ) {
		$search_service = new SearchService();
		$all_facets     = $search_service->get_facet_values( $facet_type );

		// Cache for 1 hour
		wp_cache_set( $cache_key, $all_facets, $cache_group, HOUR_IN_SECONDS );
	}

	// Get the slice for this offset
	$facets = array_slice( $all_facets, $offset, $limit );

	if ( empty( $facets ) ) {
		wp_send_json_success( array(
			'facets'  => array(),
			'has_more' => false,
		) );
	}

	// Format facets for response
	$formatted_facets = array_map( function( $facet ) {
		return array(
			'value' => $facet['value'],
			'count' => $facet['count'],
		);
	}, $facets );

	$has_more = ( $offset + $limit ) < count( $all_facets );

	wp_send_json_success( array(
		'facets'   => $formatted_facets,
		'has_more' => $has_more,
	) );
}

add_action( 'wp_ajax_ps_load_more_facets', 'ps_load_more_facets' );
add_action( 'wp_ajax_nopriv_ps_load_more_facets', 'ps_load_more_facets' );
