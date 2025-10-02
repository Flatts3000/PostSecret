<?php
/**
 * Plugin Name: PostSecret Search
 * Description: Semantic similarity search via Qdrant vector database
 * Version: 1.0.0
 * Requires PHP: 8.1
 */

namespace PSSearch;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use PSAI\Settings;

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/src/QdrantSearchService.php';

/**
 * Register REST endpoints.
 */
add_action('rest_api_init', function () {
    \PSSearch\register_rest_routes();
});

/**
 * Register REST API routes for semantic search.
 */
function register_rest_routes()
{
    // Quick smoke test
    register_rest_route('psai/v1', '/search-test', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            return new WP_REST_Response(['status' => 'ok', 'message' => 'Search plugin loaded'], 200);
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
                'validate_callback' => function ($param) {
                    return is_string($param) && strlen(trim($param)) >= 3;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            // Defaults come from Settings (ANN_TOP_K / ANN_MIN_SCORE), but we still declare schema defaults here.
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
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function handle_semantic_search(WP_REST_Request $request)
{
    $query = trim((string)$request->get_param('query'));

    // Pull tuned defaults from Settings if caller passed the route defaults.
    $limitReq = (int)$request->get_param('limit');
    $minScoreReq = (float)$request->get_param('min_score');

    $limit = $limitReq === 24 ? (int)opt('ANN_TOP_K', 24) : $limitReq;
    $min_score = abs($minScoreReq - 0.5) < 1e-9 ? (float)opt('ANN_MIN_SCORE', 0.55) : $minScoreReq;

    // Resolve OpenAI API key: Settings → env → const
    $api_key = (string)opt('API_KEY', '');
    if ($api_key === '') {
        $api_key = getenv('OPENAI_API_KEY') ?: (defined('OPENAI_API_KEY') ? constant('OPENAI_API_KEY') : '');
    }
    if ($api_key === '') {
        return new WP_Error('no_api_key', 'OpenAI API key not configured', ['status' => 500]);
    }

    // Need EmbeddingService for query embeddings
    if (!class_exists('PSAI\\EmbeddingService')) {
        return new WP_Error('missing_dependency', 'EmbeddingService not available', ['status' => 500]);
    }

    // Embedding model (keep default, but allow override via filter)
    $model = apply_filters('psai/search/embedding_model', 'text-embedding-3-small', $request);

    // Build a reasonable default filter; callers can override/extend via filter.
    $filters = ['status' => 'public'];
    /** @var array $filters */
    $filters = apply_filters('psai/search/default_filters', $filters, $request);

    // Generate embedding for the query
    $embedding = \PSAI\EmbeddingService::generate_query_embedding($query, $api_key, $model);
    if ($embedding === null) {
        return new WP_Error('embedding_failed', 'Failed to generate embedding for query', ['status' => 500]);
    }

    // Perform ANN search in Qdrant (returns null if Qdrant disabled/unavailable)
    $results = QdrantSearchService::search_by_vector($embedding, $model, $limit, $min_score, $filters);
    if ($results === null) {
        // For query-based search we don't have a cheap MySQL brute-force fallback, so surface 503.
        return new WP_Error('search_failed', 'Vector search unavailable', ['status' => 503]);
    }

    if (empty($results)) {
        return new WP_REST_Response([
            'query' => $query,
            'total' => 0,
            'items' => [],
        ], 200);
    }

    // Fetch light attachment metadata for each result
    $items = [];
    foreach ($results as $result) {
        $secret_id = (int)$result['secret_id'];
        $similarity = (float)$result['similarity'];

        // front image src
        $src = wp_get_attachment_image_src($secret_id, 'secret-card');
        if (!$src) {
            $fallback = wp_get_attachment_url($secret_id);
            $src = [$fallback ?: '', 0, 0, true];
        }

        // paired "back" image (optional)
        $back_id = (int)(get_post_meta($secret_id, '_ps_pair_id', true) ?: 0) ?: null;
        $back_src = null;
        $back_alt = null;
        if ($back_id) {
            $back_image = wp_get_attachment_image_src($back_id, 'secret-card');
            $back_src = $back_image ? $back_image[0] : (wp_get_attachment_url($back_id) ?: null);
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
            'tags' => array_values((array)(get_post_meta($secret_id, '_ps_tags', true) ?: [])),
            'primary' => get_post_meta($secret_id, '_ps_primary_hex', true) ?: '',
            'orientation' => get_post_meta($secret_id, '_ps_orientation', true) ?: '',
            'back_id' => $back_id,
            'back_src' => $back_src,
            'back_alt' => $back_alt,
            'link' => get_attachment_link($secret_id),
        ];
    }

    return new WP_REST_Response([
        'query' => $query,
        'total' => count($items),
        'items' => $items,
    ], 200);
}

/**
 * Small helper to read settings (single-array option) with a default.
 * Kept in this file to avoid a hard dependency beyond the option name.
 *
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function opt(string $key, $default = null)
{
    $all = get_option(Settings::OPTION, []);
    if (is_array($all) && array_key_exists($key, $all) && $all[$key] !== '' && $all[$key] !== null) {
        return $all[$key];
    }
    return $default;
}