<?php
/**
 * Plugin Name: PostSecret Search
 * Description: Semantic similarity search via Qdrant vector database
 * Version: 1.0.0
 * Requires PHP: 8.1
 */

namespace PSSearch;

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/src/QdrantSearchService.php';

// Register REST endpoints directly
add_action('rest_api_init', function() {
    \PSSearch\register_rest_routes();
});

/**
 * Register REST API routes for semantic search.
 */
function register_rest_routes() {
    // Test endpoint to verify plugin is loaded
    register_rest_route('psai/v1', '/search-test', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function() {
            return new \WP_REST_Response(['status' => 'ok', 'message' => 'Search plugin loaded'], 200);
        },
    ]);

    // POST /wp-json/psai/v1/semantic-search
    register_rest_route('psai/v1', '/semantic-search', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'args' => [
            'query' => [
                'type' => 'string',
                'required' => true,
                'validate_callback' => function($param) {
                    return is_string($param) && strlen(trim($param)) >= 3;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'limit' => [
                'type' => 'integer',
                'default' => 24,
                'minimum' => 1,
                'maximum' => 60,
            ],
            'min_score' => [
                'type' => 'number',
                'default' => 0.5,
                'minimum' => 0.0,
                'maximum' => 1.0,
            ],
        ],
        'callback' => __NAMESPACE__ . '\\handle_semantic_search',
    ]);
}

/**
 * Handle semantic search request.
 *
 * @param \WP_REST_Request $request
 * @return \WP_REST_Response|\WP_Error
 */
function handle_semantic_search(\WP_REST_Request $request) {
    $query = trim($request->get_param('query'));
    $limit = (int)$request->get_param('limit');
    $min_score = (float)$request->get_param('min_score');

    // Get OpenAI API key
    $api_key = getenv('OPENAI_API_KEY') ?: (defined('OPENAI_API_KEY') ? constant('OPENAI_API_KEY') : null);
    if (!$api_key) {
        return new \WP_Error('no_api_key', 'OpenAI API key not configured', ['status' => 500]);
    }

    // Generate embedding for query using EmbeddingService
    if (!class_exists('PSAI\\EmbeddingService')) {
        return new \WP_Error('missing_dependency', 'EmbeddingService not available', ['status' => 500]);
    }

    $model = 'text-embedding-3-small';
    $embedding = \PSAI\EmbeddingService::generate_query_embedding($query, $api_key, $model);

    if ($embedding === null) {
        return new \WP_Error('embedding_failed', 'Failed to generate embedding for query', ['status' => 500]);
    }

    // Search using Qdrant
    $filters = []; // No filters for now - add ['status' => 'public'] when ready
    $results = QdrantSearchService::search_by_vector($embedding, $model, $limit, $min_score, $filters);

    if ($results === null) {
        return new \WP_Error('search_failed', 'Vector search unavailable', ['status' => 503]);
    }

    if (empty($results)) {
        return new \WP_REST_Response([
            'query' => $query,
            'total' => 0,
            'items' => [],
        ], 200);
    }

    // Fetch full secret data for results
    $items = [];
    foreach ($results as $result) {
        $secret_id = $result['secret_id'];
        $similarity = $result['similarity'];

        // Get attachment data
        $src = wp_get_attachment_image_src($secret_id, 'secret-card');
        if (!$src) {
            $src = [wp_get_attachment_url($secret_id), 0, 0, true];
        }

        // Get back side data if exists
        $back_id = (int)(get_post_meta($secret_id, '_ps_pair_id', true) ?: 0) ?: null;
        $back_src = null;
        $back_alt = null;
        if ($back_id) {
            $back_image = wp_get_attachment_image_src($back_id, 'secret-card');
            if ($back_image) {
                $back_src = $back_image[0];
            } else {
                $back_src = wp_get_attachment_url($back_id);
            }
            $back_alt = get_post_meta($back_id, '_wp_attachment_image_alt', true) ?: '';
        }

        $items[] = [
            'id' => $secret_id,
            'similarity' => $similarity,
            'src' => $src[0],
            'width' => (int)$src[1],
            'height' => (int)$src[2],
            'alt' => get_post_meta($secret_id, '_wp_attachment_image_alt', true) ?: '',
            'caption' => get_post_field('post_excerpt', $secret_id) ?: '',
            'excerpt' => get_post_field('post_content', $secret_id) ?: '',
            'date' => get_post_datetime($secret_id)?->format('c'),
            'tags' => array_values((array)get_post_meta($secret_id, '_ps_tags', true) ?: []),
            'primary' => get_post_meta($secret_id, '_ps_primary_hex', true) ?: '',
            'orientation' => get_post_meta($secret_id, '_ps_orientation', true) ?: '',
            'back_id' => $back_id,
            'back_src' => $back_src,
            'back_alt' => $back_alt,
            'link' => get_attachment_link($secret_id),
        ];
    }

    return new \WP_REST_Response([
        'query' => $query,
        'total' => count($items),
        'items' => $items,
    ], 200);
}
