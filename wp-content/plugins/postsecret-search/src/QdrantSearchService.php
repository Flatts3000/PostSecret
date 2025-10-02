<?php

namespace PSSearch;

if (!defined('ABSPATH')) exit;

final class QdrantSearchService
{
    /**
     * Find similar secrets using Qdrant vector search.
     *
     * @param int $secret_id Source secret
     * @param int $limit Max results to return
     * @param float $min_score Minimum similarity score (0..1)
     * @param array $filters Optional payload filters (e.g., ['status' => 'public'])
     * @return array|null Array of results or null if Qdrant unavailable
     */
    public static function find_similar(int $secret_id, int $limit = 10, float $min_score = 0.5, array $filters = []): ?array
    {
        $base = self::qdrant_url();
        if (!$base) return null;

        // Get embedding from MySQL canonical store
        $src = self::get_embedding($secret_id);
        if (!$src) return [];

        $collection = self::qdrant_collection($src['model_version']);

        // Build Qdrant filter
        $must = [];
        foreach ($filters as $k => $v) {
            if (is_array($v)) { // array match
                $must[] = ['key' => $k, 'values_count' => ['gte' => 1], 'should' => array_map(fn($vv) => ['key' => $k, 'match' => ['value' => $vv]], $v)];
            } else {
                $must[] = ['key' => $k, 'match' => ['value' => $v]];
            }
        }
        // Exclude self
        $must[] = ['key' => 'secret_id', 'match' => ['except' => [$secret_id]]];

        $body = [
            'vector' => array_values($src['embedding']),
            'top' => max(1, $limit + 3), // over-fetch a bit then trim
            'filter' => $must ? ['must' => $must] : null,
            'params' => ['hnsw_ef' => 96], // trade recall/latency during dev
            'score_threshold' => max(0.0, min(1.0, $min_score)),
        ];

        $res = self::qdrant_request('POST', "/collections/{$collection}/points/search", $body, 10);
        if (!$res || ($res['status'] ?? '') !== 'ok') return null;

        $hits = $res['result'] ?? [];

        $out = [];
        foreach ($hits as $h) {
            $id = (int)($h['id'] ?? 0);
            if (!$id || $id === $secret_id) continue;
            $score = (float)($h['score'] ?? 0.0);
            if ($score < $min_score) continue;
            $out[] = ['secret_id' => $id, 'similarity' => round($score, 4)];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /**
     * MySQL fallback for similarity search (brute-force cosine).
     *
     * @param int $secret_id
     * @param int $limit
     * @param float $min_similarity
     * @return array
     */
    public static function find_similar_mysql(int $secret_id, int $limit, float $min_similarity): array
    {
        global $wpdb;

        $source = self::get_embedding($secret_id);
        if (!$source) return [];

        $table = $wpdb->prefix . 'ps_text_embeddings';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT secret_id, embedding FROM $table WHERE secret_id != %d AND model_version = %s",
                $secret_id,
                $source['model_version']
            ),
            ARRAY_A
        );

        $src = $source['embedding'];
        $res = [];
        foreach ($rows as $row) {
            $vec = json_decode($row['embedding'], true);
            $sim = self::cosine_similarity($src, $vec);
            if ($sim >= $min_similarity) {
                $res[] = ['secret_id' => (int)$row['secret_id'], 'similarity' => round($sim, 4)];
            }
        }
        usort($res, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return array_slice($res, 0, $limit);
    }

    // === Internal Helpers ===

    private static function get_embedding(int $secret_id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ps_text_embeddings';

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE secret_id = %d", $secret_id),
            ARRAY_A
        );
        if (!$row) return null;
        $row['embedding'] = json_decode($row['embedding'], true);
        return $row;
    }

    private static function qdrant_url(): ?string
    {
        $url = getenv('PS_QDRANT_URL') ?: (defined('PS_QDRANT_URL') ? PS_QDRANT_URL : null);
        return $url ?: null;
    }

    private static function qdrant_collection(string $model): string
    {
        // Keep separate collections per model for easier swaps
        return 'secrets_' . preg_replace('/[^a-z0-9]+/i', '_', $model);
    }

    private static function qdrant_request(string $method, string $path, ?array $body = null, int $timeout = 10): ?array
    {
        $base = self::qdrant_url();
        if (!$base) return null;

        $args = [
            'method' => $method,
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => $timeout,
        ];
        if ($body !== null) $args['body'] = wp_json_encode($body);

        $res = wp_remote_request(rtrim($base, '/') . $path, $args);
        if (is_wp_error($res)) {
            error_log('Qdrant http error: ' . $res->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($res);
        $raw = wp_remote_retrieve_body($res);
        if ($code >= 300) {
            error_log("Qdrant HTTP {$code}: " . substr($raw, 0, 300));
            return null;
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    private static function cosine_similarity(array $a, array $b): float
    {
        if (count($a) !== count($b)) return 0.0;
        $dot = 0.0;
        $ma = 0.0;
        $mb = 0.0;
        $n = count($a);
        for ($i = 0; $i < $n; $i++) {
            $ai = (float)$a[$i];
            $bi = (float)$b[$i];
            $dot += $ai * $bi;
            $ma += $ai * $ai;
            $mb += $bi * $bi;
        }
        if ($ma == 0.0 || $mb == 0.0) return 0.0;
        return $dot / (sqrt($ma) * sqrt($mb));
    }
}
