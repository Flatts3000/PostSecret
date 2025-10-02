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

// Plugin initialization hook
add_action('plugins_loaded', __NAMESPACE__ . '\\init');

function init() {
    // Service is stateless, no initialization needed
    // Future: register REST endpoints, admin UI, etc.
}
