<?php
declare(strict_types=1);

namespace PSAI;

if (!defined('ABSPATH')) exit;

final class EmbeddingService
{
    /** @var array<string,int> Map models → vector dimensions (update as needed). */
    private const MODEL_DIMS = [
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
    ];

    /** HTTP defaults for remote calls (overridden by settings where present). */
    private const HTTP_TIMEOUT_EMBEDDING_DEFAULT = 30; // s
    private const HTTP_TIMEOUT_QDRANT_DEFAULT = 10; // s
    private const HTTP_USER_AGENT = 'PostSecret-EmbeddingService/1.0 (+WordPress)';

    /** Env/const fallbacks for Qdrant (legacy). */
    private const OPT_QDRANT_URL = 'PS_QDRANT_URL';
    private const OPT_QDRANT_API_KEY = 'PS_QDRANT_API_KEY';

    // ─────────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Generate, store, and index embedding (canonical in MySQL, best-effort mirror to Qdrant).
     */
    public static function generate_and_store(int $secret_id, array $payload, string $api_key, string $model = 'text-embedding-3-small'): bool
    {
        try {
            // Allow last-mile overrides (e.g., staged rollouts).
            /** @var string $model */
            $model = apply_filters('psai/embedding/model', $model, $secret_id, $payload);

            // If API key wasn't supplied, use settings.
            if ($api_key === '') {
                $api_key = (string)self::opt('API_KEY', '');
                if ($api_key === '') {
                    $msg = 'Embedding failed: OpenAI API key not configured in settings';
                    error_log("[EmbeddingService] {$msg} for secret {$secret_id}");
                    psai_set_last_error($secret_id, $msg);
                    return false;
                }
            }

            $input = self::build_embedding_input($payload);
            if ($input === '') {
                $msg = 'Embedding failed: Empty input (no topics/feelings/text extracted)';
                error_log("[EmbeddingService] {$msg} for secret {$secret_id}");
                psai_set_last_error($secret_id, $msg);
                return false;
            }

            $embedding = self::generate_embedding($api_key, $model, $input);
            if ($embedding === null) {
                $msg = 'Embedding failed: OpenAI API call returned no data (check API key, quota, and network)';
                error_log("[EmbeddingService] {$msg} for secret {$secret_id}");
                psai_set_last_error($secret_id, $msg);
                return false;
            }

            $embedding = self::normalize_vector($embedding);

            // Canonical: store in MySQL
            $ok = self::store_embedding($secret_id, $model, $embedding, $payload);
            if (!$ok) {
                $msg = 'Embedding failed: Database storage error';
                error_log("[EmbeddingService] {$msg} for secret {$secret_id}");
                psai_set_last_error($secret_id, $msg);
                return false;
            }

            // Mirror to Qdrant (fast ANN), best-effort and non-blocking, only if enabled
            if (self::qdrant_enabled()) {
                $qdrantPayload = [
                    'status' => 'public', // adjust at query time if needed
                    'topics' => $payload['topics'] ?? [],
                    'feelings' => $payload['feelings'] ?? [],
                    'meanings' => $payload['meanings'] ?? [],
                    'vibe' => $payload['vibe'] ?? [],
                    'style' => $payload['style'] ?? 'unknown',
                    'locations' => $payload['locations'] ?? [],
                    'wisdom' => !empty($payload['wisdom']) ? (string)$payload['wisdom'] : '',
                ];
                /** @var array $qdrantPayload */
                $qdrantPayload = apply_filters('psai/embedding/qdrant-payload', $qdrantPayload, $secret_id, $payload);

                $qdrant_ok = self::qdrant_upsert($secret_id, $model, $embedding, $qdrantPayload);
                if (!$qdrant_ok) {
                    // Non-fatal - Qdrant is best-effort, but log it and store warning
                    $msg = "Embedding stored in MySQL but Qdrant sync failed (check QDRANT_URL and QDRANT_API_KEY)";
                    error_log("[EmbeddingService] Qdrant upsert failed for secret {$secret_id} (non-fatal)");

                    // Store warning so user can see it
                    $existing_error = get_post_meta($secret_id, '_ps_last_error', true);
                    if (empty($existing_error)) {
                        psai_set_last_error($secret_id, $msg);
                    }
                }
            }

            do_action('psai/embedding/saved', $secret_id, $model, $embedding, $payload);
            return true;

        } catch (\Throwable $e) {
            $msg = 'Embedding exception: ' . $e->getMessage();
            error_log('[EmbeddingService] Error for secret ' . $secret_id . ': ' . $msg);
            psai_set_last_error($secret_id, substr($msg, 0, 500));
            do_action('psai/embedding/error', $secret_id, $e);
            return false;
        }
    }

    /**
     * Similarity search via QdrantSearchService when present; fallback to MySQL brute force.
     * If the caller used defaults, pull tuned values from settings.
     */
    public static function find_similar(int $secret_id, int $limit = 10, float $min_score = 0.5, array $filters = []): array
    {
        // If the method was called with library defaults, adopt the configured values.
        if ($limit === 10) {
            $limit = (int)self::opt('ANN_TOP_K', 24);
        }
        if (abs($min_score - 0.5) < 1e-9) {
            $min_score = (float)self::opt('ANN_MIN_SCORE', 0.55);
        }

        if (class_exists('PSSearch\\QdrantSearchService')) {
            $results = \PSSearch\QdrantSearchService::find_similar($secret_id, $limit, $min_score, $filters);
            if ($results !== null) {
                return $results;
            }
            return \PSSearch\QdrantSearchService::find_similar_mysql($secret_id, $limit, $min_score);
        }

        return self::find_similar_mysql($secret_id, $limit, $min_score);
    }

    /**
     * Generate an embedding for an arbitrary query string (no DB write).
     *
     * @param string $query Free-text query to embed.
     * @param string $api_key OpenAI API key. If empty, falls back to Settings::API_KEY.
     * @param string $model Embedding model (default: text-embedding-3-small).
     * @return array<int,float>|null  L2-normalized vector or null on failure.
     */
    public static function generate_query_embedding(string $query, string $api_key = '', string $model = 'text-embedding-3-small'): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        // Allow caller to omit the key; pull from Settings if needed.
        if ($api_key === '') {
            $api_key = (string)self::opt('API_KEY', '');
            if ($api_key === '') {
                error_log('[EmbeddingService] No API key available for generate_query_embedding().');
                return null;
            }
        }

        $vec = self::generate_embedding($api_key, $model, $query);
        if ($vec === null) {
            return null;
        }

        return self::normalize_vector($vec);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // OpenAI
    // ─────────────────────────────────────────────────────────────────────────────

    private static function openai_embeddings_url(): string
    {
        $base = (string)self::opt('API_BASE', '');
        $base = trim($base);
        if ($base === '') {
            $base = 'https://api.openai.com/v1';
        }
        return rtrim($base, '/') . '/embeddings';
    }

    private static function embedding_timeout_seconds(): int
    {
        // Reuse HTTP timeout if provided, else internal default.
        return (int)self::opt('REQUEST_TIMEOUT_SECONDS', self::HTTP_TIMEOUT_EMBEDDING_DEFAULT);
    }

    /**
     * Call OpenAI Embeddings API.
     */
    private static function generate_embedding(string $api_key, string $model, string $input): ?array
    {
        $body = ['model' => $model, 'input' => $input];

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'User-Agent' => self::HTTP_USER_AGENT,
            ],
            'timeout' => self::embedding_timeout_seconds(),
            'body' => wp_json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        $res = wp_remote_post(self::openai_embeddings_url(), $args);

        if (is_wp_error($res)) {
            $err_msg = $res->get_error_message();
            error_log('[EmbeddingService] Embedding API WP_Error: ' . $err_msg);
            // Store the network/timeout error
            set_transient('_ps_last_embedding_error', 'Network error: ' . $err_msg, 300);
            return null;
        }

        $code = (int)wp_remote_retrieve_response_code($res);
        $raw = (string)wp_remote_retrieve_body($res);

        if ($code >= 300) {
            error_log('[EmbeddingService] Embedding API HTTP ' . $code . ': ' . substr($raw, 0, 600));

            // Try to parse OpenAI error message
            $json = json_decode($raw, true);
            $openai_error = $json['error']['message'] ?? 'Unknown API error';
            $err_msg = "OpenAI API HTTP {$code}: {$openai_error}";
            set_transient('_ps_last_embedding_error', $err_msg, 300);
            return null;
        }

        $json = json_decode($raw, true);
        $embedding = $json['data'][0]['embedding'] ?? null;

        if (!is_array($embedding) || $embedding === []) {
            error_log('[EmbeddingService] Invalid embedding response: ' . substr($raw, 0, 300));
            set_transient('_ps_last_embedding_error', 'Invalid API response: missing embedding data', 300);
            return null;
        }

        $expected = self::MODEL_DIMS[$model] ?? null;
        if (is_int($expected) && count($embedding) !== $expected) {
            error_log(sprintf(
                '[EmbeddingService] Model "%s" expected dim %d but received %d.',
                $model, $expected, count($embedding)
            ));
        }

        // Clear any previous error on success
        delete_transient('_ps_last_embedding_error');

        /** @var array<int,float> $embedding */
        return array_map(static fn($v) => (float)$v, $embedding);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Qdrant Integration (Upsert)
    // ─────────────────────────────────────────────────────────────────────────────

    private static function qdrant_enabled(): bool
    {
        return (bool)self::opt('QDRANT_ENABLE', true);
    }

    /** Resolve Qdrant base URL: settings first, then env/const fallback. */
    private static function qdrant_url(): ?string
    {
        $url = (string)self::opt('QDRANT_URL', '');
        if ($url === '') {
            $url = getenv(self::OPT_QDRANT_URL) ?: (defined(self::OPT_QDRANT_URL) ? constant(self::OPT_QDRANT_URL) : '');
        }
        return is_string($url) && $url !== '' ? rtrim($url, '/') : null;
    }

    /** Optional Qdrant API key. */
    private static function qdrant_api_key(): ?string
    {
        $key = (string)self::opt('QDRANT_API_KEY', '');
        if ($key === '') {
            $key = getenv(self::OPT_QDRANT_API_KEY) ?: (defined(self::OPT_QDRANT_API_KEY) ? constant(self::OPT_QDRANT_API_KEY) : '');
        }
        return is_string($key) && $key !== '' ? $key : null;
    }

    /** Collection name: prefer setting; else per-model safe name. */
    private static function qdrant_collection(string $model): string
    {
        $configured = (string)self::opt('QDRANT_COLLECTION', '');
        if ($configured !== '') {
            return $configured;
        }
        $name = 'secrets_' . preg_replace('/[^a-z0-9]+/i', '_', $model);
        /** @var string $name */
        $name = apply_filters('psai/embedding/qdrant-collection', $name, $model);
        return $name;
    }

    /** Distance metric from settings (Cosine/Dot/Euclid). */
    private static function qdrant_distance(): string
    {
        $d = (string)self::opt('QDRANT_DISTANCE', 'Cosine');
        return in_array($d, ['Cosine', 'Dot', 'Euclid'], true) ? $d : 'Cosine';
    }

    /** Per-request Qdrant timeout. */
    private static function qdrant_timeout_seconds(): int
    {
        return (int)self::opt('ANN_TIMEOUT_SECONDS', self::HTTP_TIMEOUT_QDRANT_DEFAULT);
    }

    /** Ensure vector dim: prefer setting, else from actual vector length. */
    private static function qdrant_vector_size(int $fallback): int
    {
        $sz = (int)self::opt('QDRANT_VECTOR_SIZE', 0);
        return $sz > 0 ? $sz : $fallback;
    }

    /**
     * Upsert a single point into Qdrant (best-effort).
     * @return bool True if upsert succeeded, false otherwise
     */
    private static function qdrant_upsert(int $secret_id, string $model, array $vector, array $payload = []): bool
    {
        $base = self::qdrant_url();
        if ($base === null) return false;

        // Ensure collection exists (cached)
        $collection = self::qdrant_collection($model);
        self::qdrant_ensure_collection($collection, self::qdrant_vector_size(count($vector)));

        $body = [
            'points' => [[
                'id' => $secret_id,
                'vector' => array_values($vector),
                'payload' => array_merge($payload, [
                    'secret_id' => $secret_id,
                    'model_version' => $model,
                ]),
            ]],
        ];

        $result = self::qdrant_request('PUT', "/collections/{$collection}/points?wait=true", $body, self::qdrant_timeout_seconds());
        return $result !== null && ($result['status'] ?? '') === 'ok';
    }

    /**
     * Ensure Qdrant collection exists; create if missing (uses settings for size/distance).
     */
    private static function qdrant_ensure_collection(string $collection, int $dim): void
    {
        $cache_key = "psai_qdrant_has_{$collection}";
        if (get_transient($cache_key)) return;

        error_log("[EmbeddingService] Checking if Qdrant collection '{$collection}' exists...");
        $exists = self::qdrant_request('GET', "/collections/{$collection}", null, 5);
        $ok = is_array($exists) && (($exists['status'] ?? '') === 'ok');

        if (!$ok) {
            error_log("[EmbeddingService] Collection '{$collection}' not found, creating with dimension {$dim}...");
            $create = [
                'vectors' => [
                    'size' => $dim,
                    'distance' => self::qdrant_distance(),
                ],
                'hnsw_config' => [
                    'm' => 16,
                    'ef_construct' => 200,
                ],
                'optimizers_config' => [
                    'default_segment_number' => 2,
                    'indexing_threshold' => 0,  // index immediately (dev-friendly)
                ],
            ];
            $result = self::qdrant_request('PUT', "/collections/{$collection}", $create, 20);

            if ($result === null) {
                error_log("[EmbeddingService] Failed to create collection '{$collection}'");
                set_transient('_ps_last_qdrant_error', "Failed to create collection '{$collection}'", 300);
                return; // Don't cache failure
            }

            if (($result['result'] ?? false) === true || ($result['status'] ?? '') === 'ok') {
                error_log("[EmbeddingService] Successfully created collection '{$collection}'");
            } else {
                error_log("[EmbeddingService] Unexpected response when creating collection: " . wp_json_encode($result));
            }
        } else {
            error_log("[EmbeddingService] Collection '{$collection}' already exists");
        }

        set_transient($cache_key, 1, HOUR_IN_SECONDS);
    }

    /**
     * Minimal Qdrant HTTP client wrapper with optional API key header.
     */
    private static function qdrant_request(string $method, string $path, ?array $body = null, int $timeout = null): ?array
    {
        $base = self::qdrant_url();
        if ($base === null) {
            error_log('[EmbeddingService] Qdrant request skipped: URL not configured');
            return null;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => self::HTTP_USER_AGENT,
        ];

        $apiKey = self::qdrant_api_key();
        if ($apiKey !== null) {
            $headers['api-key'] = $apiKey; // Qdrant standard header
        }

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $timeout ?? self::qdrant_timeout_seconds(),
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $url = $base . $path;
        error_log("[EmbeddingService] Qdrant request: {$method} {$url}");
        $res = wp_remote_request($url, $args);

        if (is_wp_error($res)) {
            $err = $res->get_error_message();
            error_log("[EmbeddingService] Qdrant HTTP error: {$err}");
            set_transient('_ps_last_qdrant_error', $err, 300);
            return null;
        }

        $code = (int)wp_remote_retrieve_response_code($res);
        $raw = (string)wp_remote_retrieve_body($res);

        if ($code >= 300) {
            error_log("[EmbeddingService] Qdrant HTTP {$code}: " . substr($raw, 0, 300));
            set_transient('_ps_last_qdrant_error', "HTTP {$code}: " . substr($raw, 0, 200), 300);
            return null;
        }

        $json = json_decode($raw, true);
        if (is_array($json)) {
            delete_transient('_ps_last_qdrant_error'); // Clear error on success
        }
        return is_array($json) ? $json : null;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // MySQL brute-force fallback
    // ─────────────────────────────────────────────────────────────────────────────

    private static function find_similar_mysql(int $secret_id, int $limit, float $min_similarity): array
    {
        global $wpdb;

        $source = self::get_embedding($secret_id);
        if (!$source) return [];

        $table = $wpdb->prefix . 'ps_text_embeddings';
        $model = (string)$source['model_version'];

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT secret_id, embedding FROM {$table} WHERE secret_id != %d AND model_version = %s",
                $secret_id,
                $model
            ),
            ARRAY_A
        );
        if (!is_array($rows) || $rows === []) return [];

        /** @var array<int,float> $src */
        $src = array_map(static fn($v) => (float)$v, (array)$source['embedding']);

        $res = [];
        foreach ($rows as $row) {
            $vec = json_decode((string)$row['embedding'], true);
            if (!is_array($vec)) continue;

            /** @var array<int,float> $vec */
            $vec = array_map(static fn($v) => (float)$v, $vec);

            $sim = self::cosine_similarity($src, $vec);
            if ($sim >= $min_similarity) {
                $res[] = ['secret_id' => (int)$row['secret_id'], 'similarity' => (float)round($sim, 4)];
            }
        }

        usort($res, static fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return array_slice($res, 0, $limit);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // DB helpers, math, utils
    // ─────────────────────────────────────────────────────────────────────────────

    public static function get_embedding(int $secret_id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ps_text_embeddings';
        /** @var array<string,mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE secret_id = %d", $secret_id),
            ARRAY_A
        );
        if (!$row) return null;

        $decoded = json_decode((string)($row['embedding'] ?? '[]'), true);
        $row['embedding'] = is_array($decoded) ? $decoded : [];
        return $row;
    }

    private static function store_embedding(int $secret_id, string $model, array $embedding, array $payload): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ps_text_embeddings';

        $inputHash = hash('sha256', wp_json_encode([
            'model' => $model,
            'payload' => [
                'secretDescription' => $payload['secretDescription'] ?? null,
                'topics' => $payload['topics'] ?? [],
                'feelings' => $payload['feelings'] ?? [],
                'meanings' => $payload['meanings'] ?? [],
                'vibe' => $payload['vibe'] ?? [],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT input_hash FROM {$table} WHERE secret_id = %d AND model_version = %s",
                $secret_id,
                $model
            ),
            ARRAY_A
        );

        if (is_array($existing) && ($existing['input_hash'] ?? '') === $inputHash) {
            return true; // unchanged
        }

        $embeddingJson = wp_json_encode($embedding, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $dimension = count($embedding);

        $result = $wpdb->replace(
            $table,
            [
                'secret_id' => $secret_id,
                'model_version' => $model,
                'embedding' => $embeddingJson,
                'dimension' => $dimension,
                'input_hash' => $inputHash,
                'updated_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s']
        );

        if ($result === false) {
            $db_error = $wpdb->last_error ?: 'Unknown database error';
            $msg = "DB write failed for secret {$secret_id}: {$db_error}";
            error_log("[EmbeddingService] {$msg}");
            psai_set_last_error($secret_id, "Embedding DB storage error: {$db_error}");
            return false;
        }
        return true;
    }

    private static function cosine_similarity(array $a, array $b): float
    {
        $na = count($a);
        if ($na === 0 || $na !== count($b)) return 0.0;

        $dot = 0.0;
        $ma = 0.0;
        $mb = 0.0;
        for ($i = 0; $i < $na; $i++) {
            $ai = (float)$a[$i];
            $bi = (float)$b[$i];
            $dot += $ai * $bi;
            $ma += $ai * $ai;
            $mb += $bi * $bi;
        }
        if ($ma <= 0.0 || $mb <= 0.0) return 0.0;
        return $dot / (sqrt($ma) * sqrt($mb));
    }

    private static function normalize_vector(array $vector): array
    {
        $sumSquares = 0.0;
        foreach ($vector as $v) {
            $fv = (float)$v;
            $sumSquares += $fv * $fv;
        }
        if ($sumSquares <= 0.0) return $vector;
        $mag = sqrt($sumSquares);
        foreach ($vector as $i => $v) $vector[$i] = (float)$v / $mag;
        return $vector;
    }

    private static function sanitize_space(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s ?? '') ?? '';
        return trim($s);
    }

    /**
     * Build embedding input text from classification payload.
     * Combines topics, feelings, meanings, and extracted text into a single string.
     */
    private static function build_embedding_input(array $payload): string
    {
        $parts = [];

        // Add description/secret text
        if (!empty($payload['secret'])) {
            $parts[] = 'Secret: ' . self::sanitize_space((string)$payload['secret']);
        }

        // Add text-only facets
        if (!empty($payload['topics']) && is_array($payload['topics'])) {
            $parts[] = 'Topics: ' . implode(', ', array_map('self::sanitize_space', $payload['topics']));
        }

        if (!empty($payload['feelings']) && is_array($payload['feelings'])) {
            $parts[] = 'Feelings: ' . implode(', ', array_map('self::sanitize_space', $payload['feelings']));
        }

        if (!empty($payload['meanings']) && is_array($payload['meanings'])) {
            $parts[] = 'Meanings: ' . implode(', ', array_map('self::sanitize_space', $payload['meanings']));
        }

        // Add image+text facets
        if (!empty($payload['vibe']) && is_array($payload['vibe'])) {
            $parts[] = 'Vibe: ' . implode(', ', array_map('self::sanitize_space', $payload['vibe']));
        }

        if (!empty($payload['style']) && $payload['style'] !== 'unknown') {
            $parts[] = 'Style: ' . self::sanitize_space((string)$payload['style']);
        }

        if (!empty($payload['locations']) && is_array($payload['locations'])) {
            $parts[] = 'Locations: ' . implode(', ', array_map('self::sanitize_space', $payload['locations']));
        }

        // Add wisdom if present
        if (!empty($payload['wisdom'])) {
            $parts[] = 'Wisdom: ' . self::sanitize_space((string)$payload['wisdom']);
        }

        return implode('. ', $parts);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Settings helper
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Read a setting from the single-array option, with a default.
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private static function opt(string $key, $default = null)
    {
        $all = get_option(Settings::OPTION, []);
        if (is_array($all) && array_key_exists($key, $all) && $all[$key] !== '' && $all[$key] !== null) {
            return $all[$key];
        }
        return $default;
    }
}