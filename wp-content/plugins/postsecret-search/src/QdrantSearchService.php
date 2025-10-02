<?php
declare(strict_types=1);

namespace PSSearch;

use PSAI\Settings;

if (!defined('ABSPATH')) exit;

final class QdrantSearchService
{
    /** UA + sane defaults (overridden by Settings where present). */
    private const HTTP_USER_AGENT = 'PostSecret-QdrantSearchService/1.0 (+WordPress)';
    private const HTTP_TIMEOUT_QDRANT_DEFAULT = 10;   // seconds
    private const ANN_HNSW_EF_DEFAULT = 96;
    private const ANN_TOP_K_DEFAULT = 24;
    private const ANN_MIN_SCORE_DEFAULT = 0.55;

    /** Env/constant keys for legacy fallback. */
    private const OPT_QDRANT_URL = 'PS_QDRANT_URL';
    private const OPT_QDRANT_API_KEY = 'PS_QDRANT_API_KEY';

    // ─────────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Find similar secrets using Qdrant vector search.
     *
     * Returns null only when Qdrant is disabled/not configured or hard-fails;
     * callers should then use MySQL fallback.
     */
    public static function find_similar(int $secret_id, int $limit = 10, float $min_score = 0.5, array $filters = []): ?array
    {
        if (!self::qdrant_enabled()) {
            return null;
        }

        $base = self::qdrant_url();
        if ($base === null) {
            return null; // not configured
        }

        $src = self::get_embedding($secret_id);
        if (!$src || empty($src['embedding']) || !is_array($src['embedding'])) {
            return [];
        }

        // If caller kept library defaults, honor tuned Settings.
        if ($limit === 10) {
            $limit = (int)self::opt('ANN_TOP_K', self::ANN_TOP_K_DEFAULT);
        }
        if (abs($min_score - 0.5) < 1e-9) {
            $min_score = (float)self::opt('ANN_MIN_SCORE', self::ANN_MIN_SCORE_DEFAULT);
        }

        $collection = self::qdrant_collection((string)$src['model_version']);
        $filter = self::build_qdrant_filter($filters, $secret_id);
        $top = max(1, (int)$limit + 3); // over-fetch a bit
        $scoreThreshold = max(0.0, min(1.0, (float)$min_score));
        $hnswEf = (int)self::opt('ANN_HNSW_EF', self::ANN_HNSW_EF_DEFAULT);

        $body = [
            'vector' => array_values(array_map(static fn($v) => (float)$v, (array)$src['embedding'])),
            'top' => $top,
            'filter' => $filter,
            'params' => ['hnsw_ef' => $hnswEf],
            'score_threshold' => $scoreThreshold,
        ];

        /** @var array $body */
        $body = apply_filters('psai/qdrant/search_body', $body, $collection, $secret_id);

        $res = self::qdrant_request('POST', "/collections/{$collection}/points/search", $body, self::qdrant_timeout_seconds());
        if (!$res || ($res['status'] ?? '') !== 'ok') {
            return null; // treat as unavailable; let caller fallback
        }

        $hits = $res['result'] ?? [];
        if (!is_array($hits) || $hits === []) {
            return [];
        }

        $out = [];
        foreach ($hits as $h) {
            $id = isset($h['id']) ? (int)$h['id'] : 0;
            if ($id <= 0 || $id === $secret_id) continue;

            $score = (float)($h['score'] ?? 0.0);
            if ($score < $scoreThreshold) continue;

            $out[] = ['secret_id' => $id, 'similarity' => (float)round($score, 4)];
            if (count($out) >= $limit) break;
        }

        return $out;
    }

    /**
     * Search by query embedding vector directly.
     */
    public static function search_by_vector(array $vector, string $model, int $limit = 10, float $min_score = 0.5, array $filters = []): ?array
    {
        if (!self::qdrant_enabled()) {
            return null;
        }

        $base = self::qdrant_url();
        if ($base === null) {
            return null;
        }

        if ($limit === 10) {
            $limit = (int)self::opt('ANN_TOP_K', self::ANN_TOP_K_DEFAULT);
        }
        if (abs($min_score - 0.5) < 1e-9) {
            $min_score = (float)self::opt('ANN_MIN_SCORE', self::ANN_MIN_SCORE_DEFAULT);
        }

        $collection = self::qdrant_collection($model);
        $filter = self::build_qdrant_filter_query($filters);
        $top = max(1, (int)$limit + 3);
        $scoreThreshold = max(0.0, min(1.0, (float)$min_score));
        $hnswEf = (int)self::opt('ANN_HNSW_EF', self::ANN_HNSW_EF_DEFAULT);

        $body = [
            'vector' => array_values(array_map(static fn($v) => (float)$v, $vector)),
            'top' => $top,
            'filter' => $filter,
            'params' => ['hnsw_ef' => $hnswEf],
            'score_threshold' => $scoreThreshold,
        ];

        /** @var array $body */
        $body = apply_filters('psai/qdrant/query_search_body', $body, $collection);

        $res = self::qdrant_request('POST', "/collections/{$collection}/points/search", $body, self::qdrant_timeout_seconds());
        if (!$res || ($res['status'] ?? '') !== 'ok') {
            return null;
        }

        $hits = $res['result'] ?? [];
        if (!is_array($hits) || $hits === []) {
            return [];
        }

        $out = [];
        foreach ($hits as $h) {
            $id = isset($h['id']) ? (int)$h['id'] : 0;
            if ($id <= 0) continue;

            $score = (float)($h['score'] ?? 0.0);
            if ($score < $scoreThreshold) continue;

            $out[] = ['secret_id' => $id, 'similarity' => (float)round($score, 4)];
            if (count($out) >= $limit) break;
        }

        return $out;
    }

    /**
     * MySQL brute-force fallback (kept as-is).
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
            $vecRaw = json_decode((string)($row['embedding'] ?? '[]'), true);
            if (!is_array($vecRaw)) continue;

            /** @var array<int,float> $vec */
            $vec = array_map(static fn($v) => (float)$v, $vecRaw);

            $sim = self::cosine_similarity($src, $vec);
            if ($sim >= $min_similarity) {
                $res[] = ['secret_id' => (int)$row['secret_id'], 'similarity' => (float)round($sim, 4)];
            }
        }

        usort($res, static fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return array_slice($res, 0, max(0, (int)$limit));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────────

    /** Is Qdrant turned on in Settings? */
    private static function qdrant_enabled(): bool
    {
        return (bool)self::opt('QDRANT_ENABLE', true);
    }

    /** Base URL: Settings first, then env/const. */
    private static function qdrant_url(): ?string
    {
        $url = (string)self::opt('QDRANT_URL', '');
        if ($url === '') {
            $url = getenv(self::OPT_QDRANT_URL) ?: (defined(self::OPT_QDRANT_URL) ? constant(self::OPT_QDRANT_URL) : '');
        }
        return is_string($url) && $url !== '' ? rtrim($url, '/') : null;
    }

    /** API key: Settings first, then env/const. */
    private static function qdrant_api_key(): ?string
    {
        $key = (string)self::opt('QDRANT_API_KEY', '');
        if ($key === '') {
            $key = getenv(self::OPT_QDRANT_API_KEY) ?: (defined(self::OPT_QDRANT_API_KEY) ? constant(self::OPT_QDRANT_API_KEY) : '');
        }
        return is_string($key) && $key !== '' ? $key : null;
    }

    /** Collection: prefer configured `QDRANT_COLLECTION`, else per-model. */
    private static function qdrant_collection(string $model): string
    {
        $configured = (string)self::opt('QDRANT_COLLECTION', '');
        if ($configured !== '') return $configured;

        $name = 'secrets_' . preg_replace('/[^a-z0-9]+/i', '_', $model);
        /** @var string $name */
        $name = apply_filters('psai/qdrant/collection', $name, $model);
        return $name;
    }

    /** Qdrant timeout seconds from Settings. */
    private static function qdrant_timeout_seconds(): int
    {
        return (int)self::opt('ANN_TIMEOUT_SECONDS', self::HTTP_TIMEOUT_QDRANT_DEFAULT);
    }

    /**
     * Build Qdrant filter from simple associative array and exclude source ID.
     */
    private static function build_qdrant_filter(array $filters, int $excludeId): ?array
    {
        $filter = self::build_qdrant_filter_query($filters);
        if ($filter === null) $filter = [];
        $filter['must_not'] = [['has_id' => ['values' => [$excludeId]]]];
        return $filter === [] ? null : $filter;
    }

    /**
     * Build Qdrant filter for queries (no exclusion).
     */
    private static function build_qdrant_filter_query(array $filters): ?array
    {
        $must = [];
        $should = [];

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') continue;

            if (is_array($value)) {
                $vals = array_values(array_filter($value, static fn($v) => $v !== null && $v !== ''));
                if ($vals === []) continue;
                $shouldMatches = array_map(
                    static fn($vv) => ['key' => $key, 'match' => ['value' => $vv]],
                    $vals
                );
                $should = array_merge($should, $shouldMatches);
            } else {
                $must[] = ['key' => $key, 'match' => ['value' => $value]];
            }
        }

        $filter = [];
        if ($must !== []) $filter['must'] = $must;
        if ($should !== []) $filter['should'] = $should;

        return $filter === [] ? null : $filter;
    }

    /**
     * Minimal Qdrant HTTP wrapper with optional api-key header.
     */
    private static function qdrant_request(string $method, string $path, ?array $body = null, int $timeout = null): ?array
    {
        $base = self::qdrant_url();
        if ($base === null) return null;

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
            'timeout' => $timeout ?? self::qdrant_timeout_seconds(),
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
     * Fetch canonical embedding record for a Secret.
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
        if (!$row) return null;

        $decoded = json_decode((string)($row['embedding'] ?? '[]'), true);
        $row['embedding'] = is_array($decoded) ? $decoded : [];
        return $row;
    }

    /** Cosine similarity (for MySQL fallback). */
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

    // ─────────────────────────────────────────────────────────────────────────────
    // Settings helper
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Read a setting from the single-array option, with a default.
     * (Intentionally duplicated here to keep the service self-contained.)
     *
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