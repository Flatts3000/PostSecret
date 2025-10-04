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

    // Connection test endpoint (bypasses Qdrant completely)
    register_rest_route('psai/v1', '/test-connection', [
        'methods' => 'GET',
        'permission_callback' => function () {
            return is_user_logged_in() && current_user_can('upload_files');
        },
        'callback' => function () {
            $qdrant_url = (string)opt('QDRANT_URL', '');
            if ($qdrant_url === '') {
                $qdrant_url = getenv('PS_QDRANT_URL') ?: (defined('PS_QDRANT_URL') ? constant('PS_QDRANT_URL') : '');
            }

            $results = [
                'qdrant_url' => $qdrant_url,
                'tests' => [],
            ];

            // Test 1: Basic connectivity
            $test_url = rtrim($qdrant_url, '/') . '/';
            $response = wp_remote_get($test_url, ['timeout' => 10, 'sslverify' => false]);
            $results['tests']['root'] = [
                'url' => $test_url,
                'is_error' => is_wp_error($response),
                'error' => is_wp_error($response) ? $response->get_error_message() : null,
                'code' => is_wp_error($response) ? null : wp_remote_retrieve_response_code($response),
                'body' => is_wp_error($response) ? null : substr(wp_remote_retrieve_body($response), 0, 500),
            ];

            // Test 2: Collections endpoint (with API key)
            $test_url = rtrim($qdrant_url, '/') . '/collections';
            $headers = ['Content-Type' => 'application/json'];
            $api_key = (string)opt('QDRANT_API_KEY', '');
            if ($api_key === '') {
                $api_key = getenv('PS_QDRANT_API_KEY') ?: (defined('PS_QDRANT_API_KEY') ? constant('PS_QDRANT_API_KEY') : '');
            }
            if ($api_key !== '') {
                $headers['api-key'] = $api_key;
            }

            $response = wp_remote_get($test_url, ['timeout' => 10, 'headers' => $headers, 'sslverify' => false]);
            $results['tests']['collections'] = [
                'url' => $test_url,
                'has_api_key' => $api_key !== '',
                'is_error' => is_wp_error($response),
                'error' => is_wp_error($response) ? $response->get_error_message() : null,
                'code' => is_wp_error($response) ? null : wp_remote_retrieve_response_code($response),
                'body' => is_wp_error($response) ? null : substr(wp_remote_retrieve_body($response), 0, 500),
            ];

            return new WP_REST_Response($results, 200);
        },
    ]);

    // IP detection endpoint
    register_rest_route('psai/v1', '/my-ip', [
        'methods' => 'GET',
        'permission_callback' => function () {
            return is_user_logged_in() && current_user_can('upload_files');
        },
        'callback' => function () {
            // Try to detect outgoing IP by making a request to a service
            $response = wp_remote_get('https://api.ipify.org?format=json', ['timeout' => 10]);
            $external_ip = 'unknown';

            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $external_ip = $body['ip'] ?? 'unknown';
            }

            return new WP_REST_Response([
                'server_ip' => $_SERVER['SERVER_ADDR'] ?? 'unknown',
                'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'external_ip' => $external_ip,
                'http_x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'not set',
            ], 200);
        },
    ]);

    // Initialize Qdrant collection
    register_rest_route('psai/v1', '/qdrant-init', [
        'methods' => 'POST',
        'permission_callback' => function () {
            return is_user_logged_in() && current_user_can('manage_options');
        },
        'callback' => function () {
            if (!class_exists('PSAI\\EmbeddingService')) {
                return new WP_Error('missing_service', 'EmbeddingService not available', ['status' => 500]);
            }

            // Use reflection to call private method
            try {
                $model = 'text-embedding-3-small';
                $collection = 'secrets_text_embedding_3_small'; // From settings
                $dim = 1536; // text-embedding-3-small dimension

                // Clear any cached "collection exists" transient to force re-check
                delete_transient("psai_qdrant_has_{$collection}");

                // Try to trigger collection creation by calling the public method
                // We'll create a dummy embedding to trigger the collection creation
                $reflection = new \ReflectionClass('PSAI\\EmbeddingService');
                $method = $reflection->getMethod('qdrant_ensure_collection');
                $method->setAccessible(true);
                $method->invokeArgs(null, [$collection, $dim]);

                return new WP_REST_Response([
                    'success' => true,
                    'message' => "Attempted to initialize collection '{$collection}' with dimension {$dim}",
                    'collection' => $collection,
                    'dimension' => $dim,
                ], 200);

            } catch (\Exception $e) {
                return new WP_Error('init_failed', $e->getMessage(), ['status' => 500]);
            }
        },
    ]);

    // Diagnostic endpoint to check Qdrant configuration
    register_rest_route('psai/v1', '/qdrant-status', [
        'methods' => 'GET',
        'permission_callback' => function () {
            return is_user_logged_in() && current_user_can('upload_files');
        },
        'callback' => function () {
            global $wpdb;

            $qdrant_enabled = (bool)opt('QDRANT_ENABLE', true);
            $qdrant_url = (string)opt('QDRANT_URL', '');
            $env_url = getenv('PS_QDRANT_URL') ?: 'not set';
            $const_url = defined('PS_QDRANT_URL') ? constant('PS_QDRANT_URL') : 'not set';

            // Try to connect to Qdrant
            $final_url = $qdrant_url !== '' ? $qdrant_url : ($env_url !== 'not set' ? $env_url : $const_url);
            $qdrant_accessible = false;
            $qdrant_error = null;

            if ($final_url !== 'not set') {
                $test_url = rtrim($final_url, '/') . '/collections';

                // Get API key if configured
                $headers = ['Content-Type' => 'application/json'];
                $api_key = (string)opt('QDRANT_API_KEY', '');
                if ($api_key === '') {
                    $api_key = getenv('PS_QDRANT_API_KEY') ?: (defined('PS_QDRANT_API_KEY') ? constant('PS_QDRANT_API_KEY') : '');
                }
                if ($api_key !== '') {
                    $headers['api-key'] = $api_key;
                }

                $response = wp_remote_get($test_url, ['timeout' => 5, 'headers' => $headers]);
                if (!is_wp_error($response)) {
                    $code = wp_remote_retrieve_response_code($response);
                    $qdrant_accessible = ($code === 200);
                    if ($code !== 200) {
                        $qdrant_error = "HTTP {$code}: " . wp_remote_retrieve_body($response);
                    }
                } else {
                    $qdrant_error = $response->get_error_message();
                }
            }

            // Check database table structure
            $table = $wpdb->prefix . 'ps_text_embeddings';
            $columns = $wpdb->get_results("DESCRIBE {$table}", ARRAY_A);
            $has_input_hash = false;
            foreach ($columns as $col) {
                if ($col['Field'] === 'input_hash') {
                    $has_input_hash = true;
                    break;
                }
            }

            // Count embeddings in database
            $embedding_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

            return new WP_REST_Response([
                'qdrant_enabled' => $qdrant_enabled,
                'qdrant_url_settings' => $qdrant_url,
                'qdrant_url_env' => $env_url,
                'qdrant_url_const' => $const_url,
                'qdrant_url_final' => $final_url,
                'qdrant_accessible' => $qdrant_accessible,
                'qdrant_error' => $qdrant_error,
                'database_table_exists' => !empty($columns),
                'database_has_input_hash_column' => $has_input_hash,
                'database_embedding_count' => (int)$embedding_count,
            ], 200);
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
        // Log diagnostic info for debugging
        $qdrant_url = getenv('PS_QDRANT_URL') ?: (defined('PS_QDRANT_URL') ? constant('PS_QDRANT_URL') : 'not set');
        $settings_url = (string)opt('QDRANT_URL', '');
        $qdrant_enabled = (bool)opt('QDRANT_ENABLE', true);

        error_log("[PSSearch] Vector search unavailable. Qdrant enabled: " . ($qdrant_enabled ? 'yes' : 'no') .
                  ", Env URL: {$qdrant_url}, Settings URL: {$settings_url}");

        // For query-based search we don't have a cheap MySQL brute-force fallback, so surface 503.
        $error_details = "Qdrant not available. Check: 1) QDRANT_ENABLE in settings, 2) QDRANT_URL configured, 3) Qdrant service running";
        return new WP_Error('search_failed', $error_details, ['status' => 503]);
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

        // Combine facets (topics, feelings, meanings) into tags array
        $topics = (array)(get_post_meta($secret_id, '_ps_topics', true) ?: []);
        $feelings = (array)(get_post_meta($secret_id, '_ps_feelings', true) ?: []);
        $meanings = (array)(get_post_meta($secret_id, '_ps_meanings', true) ?: []);
        $tags = array_values(array_merge($topics, $feelings, $meanings));

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
            'tags' => $tags,
            'primary' => get_post_meta($secret_id, '_ps_primary_hex', true) ?: '',
            'orientation' => get_post_meta($secret_id, '_ps_orientation', true) ?: '',
            'back_id' => $back_id,
            'back_src' => $back_src,
            'back_alt' => $back_alt,
            'link' => get_permalink($secret_id),
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