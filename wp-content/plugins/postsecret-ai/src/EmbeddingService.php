<?php

namespace PSAI;

if (!defined('ABSPATH')) exit;

/**
 * Handles text embedding generation and storage for semantic search.
 *
 * Embeddings are generated from:
 * - Secret description
 * - Topics, feelings, meanings (facets)
 * - Extracted text from front/back
 *
 * Stored in ps_text_embeddings table for efficient retrieval.
 */
final class EmbeddingService
{
    /**
     * Generate and store embedding for a Secret.
     *
     * @param int   $secret_id  Attachment ID
     * @param array $payload    Normalized classification payload
     * @param string $api_key   OpenAI API key
     * @param string $model     Embedding model (default: text-embedding-3-small)
     * @return bool Success
     */
    public static function generate_and_store(int $secret_id, array $payload, string $api_key, string $model = 'text-embedding-3-small'): bool
    {
        try {
            // Construct embedding input from facets and text
            $input = self::build_embedding_input($payload);

            if (empty($input)) {
                return false;
            }

            // Generate embedding via OpenAI API
            $embedding = self::generate_embedding($api_key, $model, $input);

            if (!$embedding) {
                return false;
            }

            // Normalize to unit vector
            $embedding = self::normalize_vector($embedding);

            // Store in database
            return self::store_embedding($secret_id, $model, $embedding);

        } catch (\Throwable $e) {
            error_log('EmbeddingService error for secret ' . $secret_id . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Build embedding input string from classification payload.
     *
     * Format: "Secret: [description]. Topics: [t1, t2]. Feelings: [f1]. Meanings: [m1]. Text: [fullText]"
     *
     * @param array $payload Classification payload
     * @return string Input text for embedding
     */
    private static function build_embedding_input(array $payload): string
    {
        $parts = [];

        // Secret description
        if (!empty($payload['secretDescription'])) {
            $parts[] = 'Secret: ' . $payload['secretDescription'];
        }

        // Topics
        if (!empty($payload['topics'])) {
            $parts[] = 'Topics: ' . implode(', ', $payload['topics']);
        }

        // Feelings
        if (!empty($payload['feelings'])) {
            $parts[] = 'Feelings: ' . implode(', ', $payload['feelings']);
        }

        // Meanings
        if (!empty($payload['meanings'])) {
            $parts[] = 'Meanings: ' . implode(', ', $payload['meanings']);
        }

        // Extracted text (front + back, truncated to ~2000 chars total)
        $texts = [];
        if (!empty($payload['front']['text']['fullText'])) {
            $texts[] = $payload['front']['text']['fullText'];
        }
        if (!empty($payload['back']['text']['fullText'])) {
            $texts[] = $payload['back']['text']['fullText'];
        }
        if (!empty($texts)) {
            $combined = implode(' ', $texts);
            // Truncate if too long (embeddings work best with ~8k tokens max, ~2k chars is safe)
            if (mb_strlen($combined, 'UTF-8') > 2000) {
                $combined = mb_substr($combined, 0, 2000, 'UTF-8') . '…';
            }
            $parts[] = 'Text: ' . $combined;
        }

        return implode('. ', $parts);
    }

    /**
     * Generate embedding via OpenAI API.
     *
     * @param string $api_key  OpenAI API key
     * @param string $model    Embedding model
     * @param string $input    Input text
     * @return array|null Embedding vector or null on failure
     */
    private static function generate_embedding(string $api_key, string $model, string $input): ?array
    {
        $endpoint = 'https://api.openai.com/v1/embeddings';

        $body = [
            'model' => $model,
            'input' => $input,
        ];

        $res = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($res)) {
            error_log('Embedding API error: ' . $res->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($res);
        $raw = wp_remote_retrieve_body($res);

        if ($code >= 300) {
            error_log('Embedding API HTTP ' . $code . ': ' . substr($raw, 0, 500));
            return null;
        }

        $json = json_decode($raw, true);
        $embedding = $json['data'][0]['embedding'] ?? null;

        if (!is_array($embedding) || empty($embedding)) {
            error_log('Invalid embedding response: ' . substr($raw, 0, 200));
            return null;
        }

        return $embedding;
    }

    /**
     * Normalize vector to unit length (L2 normalization).
     *
     * @param array $vector Input vector
     * @return array Normalized vector
     */
    private static function normalize_vector(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(fn($x) => $x * $x, $vector)));

        if ($magnitude == 0) {
            return $vector;
        }

        return array_map(fn($x) => $x / $magnitude, $vector);
    }

    /**
     * Store embedding in database.
     *
     * @param int    $secret_id    Attachment ID
     * @param string $model        Model version
     * @param array  $embedding    Embedding vector
     * @return bool Success
     */
    private static function store_embedding(int $secret_id, string $model, array $embedding): bool
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'ps_text_embeddings';
        $dimension = count($embedding);

        // Convert to JSON for storage
        $embedding_json = wp_json_encode($embedding);

        $result = $wpdb->replace(
            $table_name,
            [
                'secret_id' => $secret_id,
                'model_version' => $model,
                'embedding' => $embedding_json,
                'dimension' => $dimension,
                'updated_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s']
        );

        return $result !== false;
    }

    /**
     * Get embedding for a Secret.
     *
     * @param int $secret_id Attachment ID
     * @return array|null Embedding data or null if not found
     */
    public static function get_embedding(int $secret_id): ?array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'ps_text_embeddings';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE secret_id = %d",
                $secret_id
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        // Decode embedding JSON
        $row['embedding'] = json_decode($row['embedding'], true);

        return $row;
    }

    /**
     * Find similar Secrets using cosine similarity.
     *
     * @param int $secret_id     Source Secret ID
     * @param int $limit         Number of results
     * @param float $min_similarity Minimum similarity threshold (0.0-1.0)
     * @return array Array of [secret_id, similarity] sorted by similarity desc
     */
    public static function find_similar(int $secret_id, int $limit = 10, float $min_similarity = 0.5): array
    {
        global $wpdb;

        $source = self::get_embedding($secret_id);
        if (!$source) {
            return [];
        }

        $table_name = $wpdb->prefix . 'ps_text_embeddings';

        // Get all embeddings (except source)
        // For large datasets, you'd want to use a vector DB or approximate NN
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT secret_id, embedding FROM $table_name WHERE secret_id != %d AND model_version = %s",
                $secret_id,
                $source['model_version']
            ),
            ARRAY_A
        );

        $source_vector = $source['embedding'];
        $results = [];

        foreach ($rows as $row) {
            $target_vector = json_decode($row['embedding'], true);
            $similarity = self::cosine_similarity($source_vector, $target_vector);

            if ($similarity >= $min_similarity) {
                $results[] = [
                    'secret_id' => (int)$row['secret_id'],
                    'similarity' => round($similarity, 4),
                ];
            }
        }

        // Sort by similarity descending
        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Calculate cosine similarity between two vectors.
     *
     * @param array $a First vector
     * @param array $b Second vector
     * @return float Similarity score (0.0-1.0)
     */
    private static function cosine_similarity(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $mag_a = 0.0;
        $mag_b = 0.0;

        for ($i = 0; $i < count($a); $i++) {
            $dot += $a[$i] * $b[$i];
            $mag_a += $a[$i] * $a[$i];
            $mag_b += $b[$i] * $b[$i];
        }

        $mag_a = sqrt($mag_a);
        $mag_b = sqrt($mag_b);

        if ($mag_a == 0 || $mag_b == 0) {
            return 0.0;
        }

        return $dot / ($mag_a * $mag_b);
    }

    /**
     * Delete embedding for a Secret.
     *
     * @param int $secret_id Attachment ID
     * @return bool Success
     */
    public static function delete_embedding(int $secret_id): bool
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'ps_text_embeddings';

        $result = $wpdb->delete(
            $table_name,
            ['secret_id' => $secret_id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Get embedding statistics.
     *
     * @return array Stats: total count, model versions, etc.
     */
    public static function get_stats(): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'ps_text_embeddings';

        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

        $models = $wpdb->get_results(
            "SELECT model_version, COUNT(*) as count FROM $table_name GROUP BY model_version",
            ARRAY_A
        );

        return [
            'total' => (int)$total,
            'by_model' => $models,
        ];
    }
}
