<?php
declare(strict_types=1);

/**
 * EmbeddingService
 * -----------------------------------------------------------------------------
 * Purpose:
 *  - Build deterministic embedding input from classification payload ("facets" + OCR text).
 *  - Generate OpenAI embeddings and store canonically in MySQL.
 *  - Optionally mirror vectors into Qdrant for fast ANN search.
 *  - Provide similarity search fallbacks (Qdrant via companion service or MySQL brute force).
 *
 * Key decisions (why, not what):
 *  - Input construction is minimal and stable to avoid drift in semantic meaning between runs.
 *  - Vectors are L2-normalized to align with cosine distance assumptions in both Qdrant & fallback.
 *  - Collections are per-model to simplify swaps/rollbacks and avoid cross-dimensional collisions.
 *  - Added small, opt-in extensibility surface via WP filters/actions for future changes without edits.
 *  - Defensive logging and early returns keep failure modes explicit without throwing fatals in WP.
 *
 * External behavior:
 *  - Public method signatures preserved.
 *  - Same default model and storage table.
 *  - Qdrant mirroring remains best-effort (no hard dependency).
 *
 * @package PostSecret\Admin
 */

namespace PSAI;

if (!defined('ABSPATH')) {
    exit;
}

final class EmbeddingService
{
    /** @var array<string,int> Map models → vector dimensions (update as needed). */
    private const MODEL_DIMS = [
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
    ];

    /** HTTP defaults for remote calls. */
    private const HTTP_TIMEOUT_EMBEDDING = 30;
    private const HTTP_TIMEOUT_QDRANT = 10;
    private const HTTP_USER_AGENT = 'PostSecret-EmbeddingService/1.0 (+WordPress)';

    /** OpenAI endpoints. */
    private const OPENAI_EMBEDDINGS_URL = 'https://api.openai.com/v1/embeddings';

    /** Options/env keys for Qdrant. */
    private const OPT_QDRANT_URL = 'PS_QDRANT_URL';
    private const OPT_QDRANT_API_KEY = 'PS_QDRANT_API_KEY'; // optional

    /**
     * Generate, store, and index embedding (canonical in MySQL, best-effort mirror to Qdrant).
     *
     * @param int $secret_id WordPress attachment/post ID of the Secret.
     * @param array $payload Normalized classification payload (facets, text, etc.).
     * @param string $api_key OpenAI API key.
     * @param string $model Embedding model ID (default: text-embedding-3-small).
     * @return bool                  True on success, false on any failure path.
     */
    public static function generate_and_store(int $secret_id, array $payload, string $api_key, string $model = 'text-embedding-3-small'): bool
    {
        try {
            // Allow last-mile overrides (e.g., staged rollouts).
            /** @var string $model */
            $model = apply_filters('psai/embedding/model', $model, $secret_id, $payload);

            $input = self::build_embedding_input($payload);
            if ($input === '') {
                error_log("[EmbeddingService] Empty embedding input for secret {$secret_id}; skipping.");
                return false;
            }

            $embedding = self::generate_embedding($api_key, $model, $input);
            if ($embedding === null) {
                return false;
            }

            $embedding = self::normalize_vector($embedding);

            // Canonical: store in MySQL
            $ok = self::store_embedding($secret_id, $model, $embedding, $payload);
            if (!$ok) {
                return false;
            }

            // Mirror to Qdrant (fast ANN), best-effort and non-blocking
            $qdrantPayload = [
                'status' => 'public', // adjust upstream as needed at query time
                'teachesWisdom' => !empty($payload['teachesWisdom']),
                'topics' => $payload['topics'] ?? [],
                'feelings' => $payload['feelings'] ?? [],
                'meanings' => $payload['meanings'] ?? [],
            ];
            /** @var array $qdrantPayload */
            $qdrantPayload = apply_filters('psai/embedding/qdrant-payload', $qdrantPayload, $secret_id, $payload);

            self::qdrant_upsert($secret_id, $model, $embedding, $qdrantPayload);

            /**
             * Fires after a successful embedding generation + storage (regardless of Qdrant status).
             *
             * @param int $secret_id
             * @param string $model
             * @param array $embedding
             * @param array $payload
             */
            do_action('psai/embedding/saved', $secret_id, $model, $embedding, $payload);

            return true;
        } catch (\Throwable $e) {
            error_log('[EmbeddingService] Error for secret ' . $secret_id . ': ' . $e->getMessage());
            /**
             * Fires on embedding failure (any stage).
             *
             * @param int $secret_id
             * @param \Throwable $e
             */
            do_action('psai/embedding/error', $secret_id, $e);
            return false;
        }
    }

    /**
     * Build deterministic input string for the embedding request.
     *
     * Notes:
     *  - We keep labels ("Secret:", "Topics:"...) to anchor sections semantically.
     *  - Text is truncated at ~2000 chars to bound request size while keeping signal.
     *  - Filters allow downstream feature flags to adjust format without editing core.
     *
     * @param array $payload
     * @return string
     */
    private static function build_embedding_input(array $payload): string
    {
        $parts = [];

        // Facets
        if (!empty($payload['secretDescription'])) {
            $parts[] = 'Secret: ' . self::sanitize_space((string)$payload['secretDescription']);
        }
        if (!empty($payload['topics']) && is_array($payload['topics'])) {
            $parts[] = 'Topics: ' . implode(', ', array_map('strval', $payload['topics']));
        }
        if (!empty($payload['feelings']) && is_array($payload['feelings'])) {
            $parts[] = 'Feelings: ' . implode(', ', array_map('strval', $payload['feelings']));
        }
        if (!empty($payload['meanings']) && is_array($payload['meanings'])) {
            $parts[] = 'Meanings: ' . implode(', ', array_map('strval', $payload['meanings']));
        }

        // OCR/Text (front/back)
        $texts = [];
        $front = $payload['front']['text']['fullText'] ?? null;
        $back = $payload['back']['text']['fullText'] ?? null;

        if (is_string($front) && $front !== '') {
            $texts[] = self::sanitize_space($front);
        }
        if (is_string($back) && $back !== '') {
            $texts[] = self::sanitize_space($back);
        }

        if (!empty($texts)) {
            $combined = implode(' ', $texts);
            // 2000 char conservative cap (UTF-8 aware)
            if (mb_strlen($combined, 'UTF-8') > 2000) {
                $combined = mb_substr($combined, 0, 2000, 'UTF-8') . '…';
            }
            $parts[] = 'Text: ' . $combined;
        }

        /** @var array $parts */
        $parts = apply_filters('psai/embedding/input_parts', $parts, $payload);

        return implode('. ', array_filter($parts, static fn($p) => $p !== null && $p !== ''));
    }

    /**
     * Call OpenAI Embeddings API.
     *
     * @param string $api_key
     * @param string $model
     * @param string $input
     * @return array<int,float>|null
     */
    private static function generate_embedding(string $api_key, string $model, string $input): ?array
    {
        $body = [
            'model' => $model,
            'input' => $input,
        ];

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'User-Agent' => self::HTTP_USER_AGENT,
            ],
            'timeout' => self::HTTP_TIMEOUT_EMBEDDING,
            'body' => wp_json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        $res = wp_remote_post(self::OPENAI_EMBEDDINGS_URL, $args);

        if (is_wp_error($res)) {
            error_log('[EmbeddingService] Embedding API error: ' . $res->get_error_message());
            return null;
        }

        $code = (int)wp_remote_retrieve_response_code($res);
        $raw = (string)wp_remote_retrieve_body($res);

        if ($code >= 300) {
            error_log('[EmbeddingService] Embedding API HTTP ' . $code . ': ' . substr($raw, 0, 600));
            return null;
        }

        $json = json_decode($raw, true);
        $embedding = $json['data'][0]['embedding'] ?? null;

        if (!is_array($embedding) || $embedding === []) {
            error_log('[EmbeddingService] Invalid embedding response: ' . substr($raw, 0, 300));
            return null;
        }

        // Optional dimension check (warn only to avoid breaking if provider shifts)
        $expected = self::MODEL_DIMS[$model] ?? null;
        if (is_int($expected) && count($embedding) !== $expected) {
            error_log(sprintf(
                '[EmbeddingService] Model "%s" expected dim %d but received %d.',
                $model,
                $expected,
                count($embedding)
            ));
        }

        // Normalize numeric type
        /** @var array<int,float> $embedding */
        $embedding = array_map(static fn($v) => (float)$v, $embedding);

        return $embedding;
    }

    /**
     * L2-normalize a dense vector. No-op for zero magnitude.
     *
     * @param array<int,float> $vector
     * @return array<int,float>
     */
    private static function normalize_vector(array $vector): array
    {
        $sumSquares = 0.0;
        foreach ($vector as $v) {
            $fv = (float)$v;
            $sumSquares += $fv * $fv;
        }

        if ($sumSquares <= 0.0) {
            return $vector;
        }

        $mag = sqrt($sumSquares);
        foreach ($vector as $i => $v) {
            $vector[$i] = (float)$v / $mag;
        }
        return $vector;
    }

    /**
     * Store embedding row in MySQL (REPLACE for idempotency).
     *
     * Columns expected (see migrations):
     *  - secret_id (PK), model_version, embedding (JSON), dimension (int), input_hash (char(64)), updated_at (datetime)
     *
     * @param int $secret_id
     * @param string $model
     * @param array<int,float> $embedding
     * @param array $payload
     * @return bool
     */
    private static function store_embedding(int $secret_id, string $model, array $embedding, array $payload): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ps_text_embeddings';

        // Optional idempotency: if input hash unchanged, short-circuit write.
        $inputHash = hash('sha256', wp_json_encode([
            'model' => $model,
            'payload' => [
                'secretDescription' => $payload['secretDescription'] ?? null,
                'topics' => $payload['topics'] ?? [],
                'feelings' => $payload['feelings'] ?? [],
                'meanings' => $payload['meanings'] ?? [],
                'frontText' => $payload['front']['text']['fullText'] ?? null,
                'backText' => $payload['back']['text']['fullText'] ?? null,
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

        if (is_array($existing) && isset($existing['input_hash']) && $existing['input_hash'] === $inputHash) {
            // No change; avoid unnecessary write.
            return true;
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
            error_log("[EmbeddingService] DB write failed for secret {$secret_id}.");
            return false;
        }

        return true;
    }

    /**
     * Fetch embedding record by secret ID.
     *
     * @param int $secret_id
     * @return array<string,mixed>|null ['secret_id','model_version','embedding'=>array,'dimension','updated_at'...]
     */
    public static function get_embedding(int $secret_id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ps_text_embeddings';

        /** @var array<string,mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE secret_id = %d", $secret_id),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        $decoded = json_decode((string)($row['embedding'] ?? '[]'), true);
        $row['embedding'] = is_array($decoded) ? $decoded : [];

        return $row;
    }

    /**
     * Similarity search via QdrantSearchService when present; fallback to MySQL brute force.
     *
     * @param int $secret_id
     * @param int $limit
     * @param float $min_score Cosine similarity threshold (0..1).
     * @param array $filters Optional metadata filters (e.g., ['status'=>'public']).
     * @return array<int,array{secret_id:int,similarity:float}>
     */
    public static function find_similar(int $secret_id, int $limit = 10, float $min_score = 0.5, array $filters = []): array
    {
        if (class_exists('PSSearch\\QdrantSearchService')) {
            $results = \PSSearch\QdrantSearchService::find_similar($secret_id, $limit, $min_score, $filters);
            if ($results !== null) {
                return $results;
            }
            // Fallback path implemented in the companion service for consistency
            return \PSSearch\QdrantSearchService::find_similar_mysql($secret_id, $limit, $min_score);
        }

        // Legacy internal fallback
        return self::find_similar_mysql($secret_id, $limit, $min_score);
    }

    // === QDRANT INTEGRATION (Upsert only) ===================================

    /**
     * Resolve Qdrant base URL from env/constant.
     *
     * @return string|null
     */
    private static function qdrant_url(): ?string
    {
        $url = getenv(self::OPT_QDRANT_URL) ?: (defined(self::OPT_QDRANT_URL) ? constant(self::OPT_QDRANT_URL) : null);
        return is_string($url) && $url !== '' ? rtrim($url, '/') : null;
    }

    /**
     * Optional Qdrant API key (if your instance enforces auth).
     *
     * @return string|null
     */
    private static function qdrant_api_key(): ?string
    {
        $key = getenv(self::OPT_QDRANT_API_KEY) ?: (defined(self::OPT_QDRANT_API_KEY) ? constant(self::OPT_QDRANT_API_KEY) : null);
        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Per-model collection name (sanitize to avoid invalid chars).
     *
     * @param string $model
     * @return string
     */
    private static function qdrant_collection(string $model): string
    {
        $name = 'secrets_' . preg_replace('/[^a-z0-9]+/i', '_', $model);
        /** @var string $name */
        $name = apply_filters('psai/embedding/qdrant-collection', $name, $model);
        return $name;
    }

    /**
     * Upsert a single point into Qdrant (best-effort).
     *
     * @param int $secret_id
     * @param string $model
     * @param array<int,float> $vector
     * @param array<string,mixed> $payload
     * @return void
     */
    private static function qdrant_upsert(int $secret_id, string $model, array $vector, array $payload = []): void
    {
        $base = self::qdrant_url();
        if ($base === null) {
            return;
        }

        // Ensure collection exists (cached to avoid repeated calls)
        $collection = self::qdrant_collection($model);
        self::qdrant_ensure_collection($collection, count($vector));

        $body = [
            'points' => [[
                'id' => $secret_id, // stable integer id
                'vector' => array_values($vector),
                'payload' => array_merge($payload, [
                    'secret_id' => $secret_id,
                    'model_version' => $model,
                ]),
            ]],
        ];

        self::qdrant_request('PUT', "/collections/{$collection}/points?wait=true", $body, self::HTTP_TIMEOUT_QDRANT);
    }

    /**
     * Ensure Qdrant collection exists; create if missing.
     *
     * @param string $collection
     * @param int $dim
     * @return void
     */
    private static function qdrant_ensure_collection(string $collection, int $dim): void
    {
        $cache_key = "psai_qdrant_has_{$collection}";
        if (get_transient($cache_key)) {
            return;
        }

        $exists = self::qdrant_request('GET', "/collections/{$collection}", null, 5);
        $ok = is_array($exists) && (($exists['status'] ?? '') === 'ok');

        if (!$ok) {
            $create = [
                'vectors' => ['size' => $dim, 'distance' => 'Cosine'],
                'hnsw_config' => ['m' => 16, 'ef_construct' => 200],
                'optimizers_config' => [
                    'default_segment_number' => 2,
                    'indexing_threshold' => 0,  // Index immediately on every change (good for dev)
                ],
            ];
            self::qdrant_request('PUT', "/collections/{$collection}", $create, 20);
        }

        set_transient($cache_key, 1, HOUR_IN_SECONDS);
    }

    /**
     * Minimal Qdrant HTTP client wrapper with optional API key header.
     *
     * @param string $method
     * @param string $path
     * @param array<string,mixed>|null $body
     * @param int $timeout
     * @return array<string,mixed>|null
     */
    private static function qdrant_request(string $method, string $path, ?array $body = null, int $timeout = self::HTTP_TIMEOUT_QDRANT): ?array
    {
        $base = self::qdrant_url();
        if ($base === null) {
            return null;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => self::HTTP_USER_AGENT,
        ];

        $apiKey = self::qdrant_api_key();
        if ($apiKey !== null) {
            // Qdrant supports multiple auth mechanisms; "api-key" is common for cloud/self-hosted.
            $headers['api-key'] = $apiKey;
        }

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $timeout,
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $url = $base . $path;
        $res = wp_remote_request($url, $args);

        if (is_wp_error($res)) {
            error_log('[EmbeddingService] Qdrant HTTP error: ' . $res->get_error_message());
            return null;
        }

        $code = (int)wp_remote_retrieve_response_code($res);
        $raw = (string)wp_remote_retrieve_body($res);

        if ($code >= 300) {
            error_log("[EmbeddingService] Qdrant HTTP {$code}: " . substr($raw, 0, 300));
            return null;
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    // === MySQL brute-force fallback (legacy) =================================

    /**
     * Brute-force cosine similarity over stored embeddings for the same model.
     * Note: This is intended only as a compatibility fallback.
     *
     * @param int $secret_id
     * @param int $limit
     * @param float $min_similarity
     * @return array<int,array{secret_id:int,similarity:float}>
     */
    private static function find_similar_mysql(int $secret_id, int $limit, float $min_similarity): array
    {
        global $wpdb;

        $source = self::get_embedding($secret_id);
        if (!$source) {
            return [];
        }

        $table = $wpdb->prefix . 'ps_text_embeddings';
        $model = (string)$source['model_version'];

        // Pull embeddings for same model; exclude self.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT secret_id, embedding FROM {$table} WHERE secret_id != %d AND model_version = %s",
                $secret_id,
                $model
            ),
            ARRAY_A
        );

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        /** @var array<int,float> $src */
        $src = array_map(static fn($v) => (float)$v, (array)$source['embedding']);

        $res = [];
        foreach ($rows as $row) {
            $vec = json_decode((string)$row['embedding'], true);
            if (!is_array($vec)) {
                continue;
            }
            /** @var array<int,float> $vec */
            $vec = array_map(static fn($v) => (float)$v, $vec);

            $sim = self::cosine_similarity($src, $vec);
            if ($sim >= $min_similarity) {
                $res[] = [
                    'secret_id' => (int)$row['secret_id'],
                    'similarity' => (float)round($sim, 4),
                ];
            }
        }

        usort($res, static fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return array_slice($res, 0, $limit);
    }

    /**
     * Cosine similarity between two equal-length vectors.
     *
     * @param array<int,float> $a
     * @param array<int,float> $b
     * @return float
     */
    private static function cosine_similarity(array $a, array $b): float
    {
        $na = count($a);
        if ($na === 0 || $na !== count($b)) {
            return 0.0;
        }

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

        if ($ma <= 0.0 || $mb <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($ma) * sqrt($mb));
    }

    // === Utilities ==========================================================

    /**
     * Generate embedding for a search query (no storage).
     *
     * @param string $query User search query text.
     * @param string $api_key OpenAI API key.
     * @param string $model Embedding model ID.
     * @return array<int,float>|null Normalized embedding vector or null on failure.
     */
    public static function generate_query_embedding(string $query, string $api_key, string $model = 'text-embedding-3-small'): ?array
    {
        try {
            $query = trim($query);
            if ($query === '') {
                return null;
            }

            $embedding = self::generate_embedding($api_key, $model, $query);
            if ($embedding === null) {
                return null;
            }

            return self::normalize_vector($embedding);
        } catch (\Throwable $e) {
            error_log('[EmbeddingService] Query embedding error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Service stats (row counts per model).
     *
     * @return array{total:int,by_model:array<int,array{model_version:string,count:string|int}>}
     */
    public static function get_stats(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ps_text_embeddings';

        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $models = $wpdb->get_results("SELECT model_version, COUNT(*) as count FROM {$table} GROUP BY model_version", ARRAY_A);

        return [
            'total' => $total,
            'by_model' => is_array($models) ? $models : [],
        ];
    }

    /**
     * Normalize whitespace and trim (helps reduce noisy input).
     *
     * @param string $s
     * @return string
     */
    private static function sanitize_space(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s ?? '') ?? '';
        return trim($s);
    }
}