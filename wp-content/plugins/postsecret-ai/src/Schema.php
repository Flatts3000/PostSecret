<?php

namespace PSAI;

if (!defined('ABSPATH')) exit;

class Schema
{
    /** @return array<int, array<string,mixed>> */
    public static function get(): array
    {
        return [
            // 1) OpenAI API
            ['section' => 'api', 'order' => 10, 'key' => 'API_BASE', 'label' => 'API Base URL', 'kind' => 'str', 'default' => '', 'help' => 'Optional OpenAI-compatible base URL (leave empty for api.openai.com)'],
            ['section' => 'api', 'order' => 20, 'key' => 'API_KEY', 'label' => 'API Key', 'kind' => 'str', 'default' => '', 'secret' => true, 'help' => 'Your OpenAI API key'],

            // 2) Qdrant (Vector DB) — moved directly below OpenAI
            ['section' => 'qdrant', 'order' => 10, 'key' => 'QDRANT_ENABLE', 'label' => 'Enable Qdrant', 'kind' => 'bool', 'default' => true, 'help' => 'Use Qdrant for ANN search (falls back to MySQL if disabled).'],
            ['section' => 'qdrant', 'order' => 20, 'key' => 'QDRANT_URL', 'label' => 'Qdrant URL', 'kind' => 'str', 'default' => '', 'help' => 'e.g., http://<ip>:6333 (env: PS_QDRANT_URL)'],
            ['section' => 'qdrant', 'order' => 30, 'key' => 'QDRANT_API_KEY', 'label' => 'Qdrant API Key', 'kind' => 'str', 'default' => '', 'secret' => true, 'help' => 'Sent as header: api-key: <value> (env: PS_QDRANT_API_KEY)'],
            ['section' => 'qdrant', 'order' => 40, 'key' => 'QDRANT_COLLECTION', 'label' => 'Collection Name', 'kind' => 'str', 'default' => 'secrets_text_embedding_3_small', 'help' => 'Target collection name.'],
            ['section' => 'qdrant', 'order' => 50, 'key' => 'QDRANT_VECTOR_SIZE', 'label' => 'Vector Size', 'kind' => 'int', 'default' => 1536, 'min' => 1, 'help' => 'Embedding dimension (e.g., 1536 for text-embedding-3-small).'],
            ['section' => 'qdrant', 'order' => 60, 'key' => 'QDRANT_DISTANCE', 'label' => 'Distance Metric', 'kind' => 'choice', 'default' => 'Cosine', 'choices' => ['Cosine', 'Dot', 'Euclid'], 'help' => 'Must match collection config.'],

            // 3) Model & Generation
            ['section' => 'model', 'order' => 10, 'key' => 'MODEL_PROVIDER', 'label' => 'Model Provider', 'kind' => 'choice', 'default' => 'openai', 'choices' => ['openai'], 'help' => 'Provider (fixed to OpenAI for MVP)'],
            ['section' => 'model', 'order' => 20, 'key' => 'MODEL_NAME', 'label' => 'Model Name', 'kind' => 'str', 'default' => 'gpt-4o-mini', 'help' => 'Vision-capable model'],
            ['section' => 'model', 'order' => 30, 'key' => 'EMBEDDING_MODEL', 'label' => 'Embedding Model', 'kind' => 'choice', 'default' => 'text-embedding-3-small', 'choices' => ['text-embedding-3-small', 'text-embedding-3-large'], 'help' => 'Model for generating embeddings (small=1536d, large=3072d)'],
            ['section' => 'model', 'order' => 40, 'key' => 'TEMPERATURE', 'label' => 'Temperature', 'kind' => 'float', 'default' => 0.2, 'min' => 0.0, 'max' => 2.0, 'help' => 'Creativity (0.0–2.0)'],
            ['section' => 'model', 'order' => 50, 'key' => 'TOP_P', 'label' => 'Top-p', 'kind' => 'float', 'default' => 1.0, 'min' => 0.0, 'max' => 1.0, 'help' => 'Nucleus sampling (0.0–1.0)'],
            ['section' => 'model', 'order' => 60, 'key' => 'MAX_TOKENS', 'label' => 'Max Tokens', 'kind' => 'int', 'default' => 1200, 'min' => 1, 'help' => 'Token limit per call'],

            // 4) Moderation
            ['section' => 'moderation', 'order' => 10, 'key' => 'MODERATION_ENABLE', 'label' => 'Enable Moderation', 'kind' => 'bool', 'default' => false, 'help' => 'Run an additional moderation check'],
            ['section' => 'moderation', 'order' => 20, 'key' => 'MODERATION_MODEL', 'label' => 'Moderation Model', 'kind' => 'str', 'default' => 'omni-moderation-latest', 'help' => 'Model for moderation (when enabled)'],

            // 5) HTTP (timeouts & retries)
            ['section' => 'http', 'order' => 10, 'key' => 'REQUEST_TIMEOUT_SECONDS', 'label' => 'HTTP Timeout (s)', 'kind' => 'int', 'default' => 60, 'min' => 1, 'help' => 'Per-request timeout'],
            ['section' => 'http', 'order' => 20, 'key' => 'REQUEST_MAX_RETRIES', 'label' => 'HTTP Retries', 'kind' => 'int', 'default' => 3, 'min' => 0, 'help' => 'Retries on transient errors'],
            ['section' => 'http', 'order' => 30, 'key' => 'REQUEST_BACKOFF_FACTOR', 'label' => 'HTTP Backoff Factor', 'kind' => 'float', 'default' => 0.5, 'min' => 0.0, 'max' => 10.0, 'help' => 'Delay multiplier between retries'],

            // 6) Logging
            ['section' => 'logging', 'order' => 10, 'key' => 'LOG_LEVEL', 'label' => 'Log Level', 'kind' => 'choice', 'default' => 'INFO', 'choices' => ['DEBUG', 'INFO', 'WARN', 'ERROR'], 'help' => 'Controls plugin logging verbosity'],

            // 7) Encoding (WebP)
            ['section' => 'encoding', 'order' => 10, 'key' => 'WEBP_ENABLE', 'label' => 'Save WebP', 'kind' => 'bool', 'default' => false, 'help' => 'Save WebP copies (requires WebP support on server)'],
            ['section' => 'encoding', 'order' => 20, 'key' => 'WEBP_QUALITY', 'label' => 'WebP Quality', 'kind' => 'int', 'default' => 80, 'min' => 0, 'max' => 100, 'help' => 'Lossy quality (0–100)'],
            ['section' => 'encoding', 'order' => 30, 'key' => 'WEBP_LOSSLESS', 'label' => 'WebP Lossless', 'kind' => 'bool', 'default' => false, 'help' => 'Use lossless compression'],
            ['section' => 'encoding', 'order' => 40, 'key' => 'WEBP_METHOD', 'label' => 'WebP Method', 'kind' => 'int', 'default' => 4, 'min' => 0, 'max' => 6, 'help' => 'Encoder effort (0–6)'],

            // 8) Ingest (Future)
            ['section' => 'ingest', 'order' => 10, 'key' => 'ALLOWED_EXT', 'label' => 'Allowed Extensions', 'kind' => 'str', 'default' => 'jpg,jpeg,png,webp,tif,tiff', 'help' => 'For future folder scanning'],
            ['section' => 'ingest', 'order' => 20, 'key' => 'RECURSIVE', 'label' => 'Recursive', 'kind' => 'bool', 'default' => true, 'help' => 'Scan subdirectories (future)'],
            ['section' => 'ingest', 'order' => 30, 'key' => 'FORCE', 'label' => 'Force Reprocess', 'kind' => 'bool', 'default' => false, 'help' => 'Reprocess even if outputs exist (future)'],

            // 9) Paths (Future)
            ['section' => 'paths', 'order' => 10, 'key' => 'IMAGES_DIR', 'label' => 'Images Directory', 'kind' => 'path', 'default' => 'images', 'help' => 'Folder containing input images (future)', 'path_kind' => 'dir'],
            ['section' => 'paths', 'order' => 20, 'key' => 'OUTPUT_DIR', 'label' => 'Output Directory', 'kind' => 'path', 'default' => 'output', 'help' => 'Folder for classification results (future)', 'path_kind' => 'dir'],

            // 10) Vector Search (tuning)
            ['section' => 'search', 'order' => 10, 'key' => 'ANN_TOP_K', 'label' => 'Top-K', 'kind' => 'int', 'default' => 24, 'min' => 1, 'help' => 'Max results to return'],
            ['section' => 'search', 'order' => 20, 'key' => 'ANN_MIN_SCORE', 'label' => 'Min Similarity', 'kind' => 'float', 'default' => 0.55, 'min' => 0.0, 'max' => 1.0, 'help' => 'Score threshold (0–1)'],
            ['section' => 'search', 'order' => 30, 'key' => 'ANN_HNSW_EF', 'label' => 'HNSW ef_search', 'kind' => 'int', 'default' => 96, 'min' => 8, 'help' => 'Higher = better recall, slower'],
            ['section' => 'search', 'order' => 40, 'key' => 'ANN_TIMEOUT_SECONDS', 'label' => 'Qdrant Timeout (s)', 'kind' => 'int', 'default' => 10, 'min' => 1, 'help' => 'Per-request timeout to Qdrant'],
            ['section' => 'search', 'order' => 50, 'key' => 'UPSERT_BATCH_SIZE', 'label' => 'Upsert Batch Size', 'kind' => 'int', 'default' => 1000, 'min' => 1, 'help' => 'Batch size when mirroring to Qdrant'],
        ];
    }

    /** Titles + descriptions for sections (rendered by Settings::register) */
    public static function sections(): array
    {
        return [
            'api' => ['title' => 'OpenAI API', 'desc' => 'Connection settings for the OpenAI API.'],
            'qdrant' => ['title' => 'Qdrant (Vector DB)', 'desc' => 'Connection and collection settings for Qdrant.'], // ← moved up
            'model' => ['title' => 'Model & Generation', 'desc' => 'Choose the model and its generation parameters.'],
            'moderation' => ['title' => 'Moderation', 'desc' => 'Optional post-classification moderation.'],
            'http' => ['title' => 'HTTP (Timeouts & Retries)', 'desc' => 'Network behavior for API requests.'],
            'logging' => ['title' => 'Logging', 'desc' => 'Control plugin logging verbosity.'],
            'encoding' => ['title' => 'Encoding (WebP)', 'desc' => 'Optional WebP export (server support required).'],
            'ingest' => ['title' => 'Ingest (Future)', 'desc' => 'Reserved for future folder scanning.'],
            'paths' => ['title' => 'Paths (Future)', 'desc' => 'Reserved for future file-system workflows.'],
            'search' => ['title' => 'Vector Search', 'desc' => 'Approximate nearest neighbor (ANN) tuning.'],
        ];
    }
}