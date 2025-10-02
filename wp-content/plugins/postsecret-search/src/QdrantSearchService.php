<?php
declare(strict_types=1);

/**
 * QdrantSearchService
 * -----------------------------------------------------------------------------
 * Purpose:
 *  - Perform KNN similarity queries against Qdrant for a given Secret.
 *  - Gracefully degrade to MySQL brute-force cosine similarity when Qdrant is unavailable.
 *
 * Key decisions:
 *  - Uses proper Qdrant filter schema:
 *      * payload constraints via "must" (match) and "should" (OR across arrays)
 *      * excludes the source point via "must_not" + has_id
 *  - Over-fetches a few results ("top") then trims to allow client-side score thresholding.
 *  - Adds small extension hooks (filters) so callers can adjust collection name, query params, etc.
 *  - Defensive parsing and logging without throwing fatals inside WordPress.
 *
 * External behavior:
 *  - Public method signatures preserved from original.
 *  - Returns `null` only when Qdrant base URL is not configured / remote fails hard.
 *
 * @package PostSecret\Search
 */

namespace PSSearch;

if (!defined('ABSPATH')) {
    exit;
}

final class QdrantSearchService
{
    /** HTTP defaults and UA. */
    private const HTTP_TIMEOUT_QDRANT = 10;
    private const HTTP_USER_AGENT = 'PostSecret-QdrantSearchService/1.0 (+WordPress)';

    /** Env/constant keys for Qdrant. */
    private const OPT_QDRANT_URL = 'PS_QDRANT_URL';
    private const OPT_QDRANT_API_KEY = 'PS_QDRANT_API_KEY'; // optional

    /**
     * Find similar secrets using Qdrant vector search.
     *
     * @param int $secret_id Source secret.
     * @param int $limit Max results to return (trimmed after fetch).
     * @param float $min_score Minimum similarity score (0..1).
     * @param array $filters Optional payload filters, e.g. ['status' => 'public', 'topics' => ['grief','family']].
     * @return array<int,array{secret_id:int,similarity:float}>|null
     */
    public static function find_similar(int $secret_id, int $limit = 10, float $min_score = 0.5, array $filters = []): ?array
    {
        $base = self::qdrant_url();
        if ($base === null) {
            return null; // Qdrant not configured
        }

        // Fetch canonical embedding from MySQL.
        $src = self::get_embedding($secret_id);
        if (!$src || empty($src['embedding']) || !is_array($src['embedding'])) {
            return [];
        }

        $collection = self::qdrant_collection((string)$src['model_version']);

        // Build Qdrant filter: translate simple associative array -> Qdrant "must/should" predicates.
        $filter = self::build_qdrant_filter($filters, $secret_id);

        // Slightly over-fetch to allow trimming after thresholding.
        $top = max(1, (int)$limit + 3);
        $scoreThreshold = max(0.0, min(1.0, (float)$min_score));

        $body = [
            'vector' => array_values(array_map(static fn($v) => (float)$v, (array)$src['embedding'])),
            'top' => $top,
            'filter' => $filter,
            'params' => ['hnsw_ef' => 96], // Balanced recall/latency during development.
            'score_threshold' => $scoreThreshold,
        ];

        /**
         * Allow callers to adjust raw Qdrant search body before the request.
         *
         * @param array $body
         * @param string $collection
         * @param int $secret_id
         */
        $body = apply_filters('psai/qdrant/search_body', $body, $collection, $secret_id);

        $res = self::qdrant_request('POST', "/collections/{$collection}/points/search", $body, self::HTTP_TIMEOUT_QDRANT);
        if (!$res || ($res['status'] ?? '') !== 'ok') {
            // If Qdrant is configured but failing, treat as unavailable to allow caller fallback.
            return null;
        }

        $hits = $res['result'] ?? [];
        if (!is_array($hits) || $hits === []) {
            return [];
        }

        $out = [];
        foreach ($hits as $h) {
            $id = isset($h['id']) ? (int)$h['id'] : 0;
            if ($id <= 0 || $id === $secret_id) {
                continue;
            }
            $score = (float)($h['score'] ?? 0.0);
            if ($score < $scoreThreshold) {
                continue;
            }
            $out[] = [
                'secret_id' => $id,
                'similarity' => (float)round($score, 4),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * MySQL fallback for similarity search (brute-force cosine).
     *
     * @param int $secret_id
     * @param int $limit
     * @param float $min_similarity
     * @return array<int,array{secret_id:int,similarity:float}>
     */
    public static function find_similar_mysql(int $secret_id, int $limit, float $min_similarity): array
    {
        global $wpdb;

        $source = self::get_embedding($secret_id);
        if (!$source || empty($source['embedding']) || !is_array($source['embedding'])) {
            return [];
        }

        $table = $wpdb->prefix . 'ps_text_embeddings';
        $model = (string)$source['model_version'];

        // Same-model comparisons only; exclude self.
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
            $vecRaw = json_decode((string)($row['embedding'] ?? '[]'), true);
            if (!is_array($vecRaw)) {
                continue;
            }
            /** @var array<int,float> $vec */
            $vec = array_map(static fn($v) => (float)$v, $vecRaw);

            $sim = self::cosine_similarity($src, $vec);
            if ($sim >= $min_similarity) {
                $res[] = [
                    'secret_id' => (int)$row['secret_id'],
                    'similarity' => (float)round($sim, 4),
                ];
            }
        }

        usort($res, static fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return array_slice($res, 0, max(0, (int)$limit));
    }

    // === Internal Helpers ===================================================

    /**
     * Fetch canonical embedding record for a Secret.
     *
     * @param int $secret_id
     * @return array<string,mixed>|null
     */
    private static function get_embedding(int $secret_id): ?array
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
     * Build a Qdrant filter object from simple associative filters.
     *
     * Supports:
     *  - Scalar equality: ['status' => 'public'] => must match
     *  - Array "OR":     ['topics' => ['grief','family']] => should of matches on the same key
     * Also excludes the source vector by id.
     *
     * @param array<string,mixed> $filters
     * @param int $excludeId
     * @return array<string,mixed>|null
     */
    private static function build_qdrant_filter(array $filters, int $excludeId): ?array
    {
        $must = [];
        $should = [];

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_array($value)) {
                // OR semantics across provided values for the same key.
                $value = array_values(array_filter($value, static fn($v) => $v !== null && $v !== ''));
                if ($value === []) {
                    continue;
                }
                $shouldMatches = array_map(
                    static fn($vv) => ['key' => $key, 'match' => ['value' => $vv]],
                    $value
                );
                // Group into a single should clause; Qdrant treats multiple should as OR.
                $should = array_merge($should, $shouldMatches);
            } else {
                $must[] = ['key' => $key, 'match' => ['value' => $value]];
            }
        }

        $filter = [];
        if ($must !== []) {
            $filter['must'] = $must;
        }
        if ($should !== []) {
            $filter['should'] = $should;
        }

        // Exclude the source point.
        $filter['must_not'] = [
            ['has_id' => ['values' => [$excludeId]]],
        ];

        return $filter === [] ? null : $filter;
    }

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
     * Optional Qdrant API key (if instance enforces auth).
     *
     * @return string|null
     */
    private static function qdrant_api_key(): ?string
    {
        $key = getenv(self::OPT_QDRANT_API_KEY) ?: (defined(self::OPT_QDRANT_API_KEY) ? constant(self::OPT_QDRANT_API_KEY) : null);
        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Derive per-model collection name (sanitized).
     *
     * @param string $model
     * @return string
     */
    private static function qdrant_collection(string $model): string
    {
        $name = 'secrets_' . preg_replace('/[^a-z0-9]+/i', '_', $model);
        /** @var string $name */
        $name = apply_filters('psai/qdrant/collection', $name, $model);
        return $name;
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
            error_log('[QdrantSearchService] HTTP error: ' . $res->get_error_message());
            return null;
        }

        $code = (int)wp_remote_retrieve_response_code($res);
        $raw = (string)wp_remote_retrieve_body($res);

        if ($code >= 300) {
            error_log("[QdrantSearchService] HTTP {$code}: " . substr($raw, 0, 300));
            return null;
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
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
}