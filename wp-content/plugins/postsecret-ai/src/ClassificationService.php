<?php
/**
 * PSAI\ClassificationService
 * -----------------------------------------------------------------------------
 * Centralized orchestration for the full classification workflow:
 * - Classify via AI
 * - Normalize schema
 * - Store results
 * - Sync attachment metadata
 * - Generate embeddings
 * - Compute image metadata
 * - Update manifest
 *
 * This service eliminates duplication across:
 * - psai_process_pair_event
 * - admin_post_psai_process_now
 * - admin_post_psai_reclassify
 * - Future bulk upload handlers
 *
 * @package PSAI
 */

declare(strict_types=1);

namespace PSAI;

if (!defined('ABSPATH')) {
    exit;
}

final class ClassificationService
{
    /**
     * Classify and fully process a front/back pair.
     *
     * @param int $front_id Front attachment ID (required)
     * @param int|null $back_id Back attachment ID (optional)
     * @param bool $force Force re-classification even if payload exists
     * @return array{success: bool, error?: string, payload?: array}
     */
    public static function classify_and_store(int $front_id, ?int $back_id = null, bool $force = false): array
    {
        // Precondition checks
        if (!$front_id || get_post_type($front_id) !== 'attachment') {
            return ['success' => false, 'error' => 'Invalid front attachment ID.'];
        }

        // Check for duplicate
        if (get_post_meta($front_id, '_ps_duplicate_of', true)) {
            psai_set_last_error($front_id, 'Skipped: duplicate image.');
            return ['success' => false, 'error' => 'Duplicate image.'];
        }

        // Get configuration
        $env = get_option(Settings::OPTION, Settings::defaults());
        $api = $env['API_KEY'] ?? '';
        $model = $env['MODEL_NAME'] ?? 'gpt-4o-mini';
        $embed_model = $env['EMBEDDING_MODEL'] ?? 'text-embedding-3-small';

        if (!$api) {
            $error = 'Missing OpenAI API key.';
            psai_set_last_error([$front_id, $back_id], $error);
            return ['success' => false, 'error' => $error];
        }

        try {
            // Check if we need to classify (or force re-classification)
            $payload = get_post_meta($front_id, '_ps_payload', true);
            if (!is_array($payload) || $force) {
                // Generate data URLs (not dependent on public URLs)
                $frontSrc = psai_make_data_url($front_id);
                $backSrc = $back_id ? psai_make_data_url($back_id) : null;

                // Classify + normalize to schema
                $payload = Classifier::classify($api, $model, $frontSrc, $backSrc);
                $payload = SchemaGuard::normalize($payload);

                // Store result (sets facets, model, prompt version, vetted flags)
                psai_store_result($front_id, $payload, $model);

                // Sync media fields (Alt/Caption/Description)
                AttachmentSync::sync_from_payload($front_id, $payload, $back_id, $force);

                // Generate and store embedding
                $embedding_ok = EmbeddingService::generate_and_store($front_id, $payload, $api, $embed_model);
                if (!$embedding_ok) {
                    // Non-fatal: classification succeeded but embedding failed
                    $embed_error = get_post_meta($front_id, '_ps_last_error', true)
                        ?: 'Embedding generation failed.';
                    // Keep going - we still have a valid classification
                }

                // Optional export manifest
                psai_update_manifest($front_id, $payload);
            }

            // Always normalize flags + compute metadata (even if classification wasn't needed)
            Ingress::normalize_from_existing_payload($front_id);

            // Enrich orientation/color on both sides
            Metadata::compute_and_store($front_id);
            if ($back_id) {
                Metadata::compute_and_store($back_id);
            }

            // Mirror some fields onto back (paired) for convenience
            if ($back_id) {
                update_post_meta($back_id, '_ps_pair_id', $front_id);
                update_post_meta($back_id, '_ps_side', 'back');
                update_post_meta($back_id, '_ps_payload', $payload);

                // Mirror facets
                $topics = get_post_meta($front_id, '_ps_topics', true);
                $feelings = get_post_meta($front_id, '_ps_feelings', true);
                $meanings = get_post_meta($front_id, '_ps_meanings', true);
                $vibe = get_post_meta($front_id, '_ps_vibe', true);
                $style = get_post_meta($front_id, '_ps_style', true);
                $locations = get_post_meta($front_id, '_ps_locations', true);

                update_post_meta($back_id, '_ps_topics', $topics);
                update_post_meta($back_id, '_ps_feelings', $feelings);
                update_post_meta($back_id, '_ps_meanings', $meanings);
                update_post_meta($back_id, '_ps_vibe', $vibe);
                update_post_meta($back_id, '_ps_style', $style);
                update_post_meta($back_id, '_ps_locations', $locations);

                // Sync facets to junction table for back attachment
                \psai_sync_facets_to_table($back_id, [
                    'topics' => $topics ?: [],
                    'feelings' => $feelings ?: [],
                    'meanings' => $meanings ?: [],
                    'vibe' => $vibe ?: [],
                    'style' => $style ? [$style] : [],
                    'locations' => $locations ?: [],
                ]);

                update_post_meta($back_id, '_ps_model', get_post_meta($front_id, '_ps_model', true));
                update_post_meta($back_id, '_ps_prompt_version', get_post_meta($front_id, '_ps_prompt_version', true));
                update_post_meta($back_id, '_ps_updated_at', wp_date('c'));
                $rs = get_post_meta($front_id, '_ps_review_status', true);
                update_post_meta($back_id, '_ps_review_status', $rs);
                update_post_meta($back_id, '_ps_is_vetted', $rs === 'auto_vetted' ? '1' : '0');
            }

            // Convert to WebP after classification (so AI sees original format)
            if (get_post_meta($front_id, '_ps_needs_webp_conversion', true)) {
                $webp_success = Ingress::convert_to_webp($front_id);
                if (!$webp_success) {
                    // Non-fatal: log error but keep flag for retry
                    error_log("WebP conversion failed for front attachment {$front_id}");
                }
            }
            if ($back_id && get_post_meta($back_id, '_ps_needs_webp_conversion', true)) {
                $webp_success = Ingress::convert_to_webp($back_id);
                if (!$webp_success) {
                    error_log("WebP conversion failed for back attachment {$back_id}");
                }
            }

            // Clear any previous errors on success
            psai_clear_last_error([$front_id, $back_id]);

            return ['success' => true, 'payload' => $payload];

        } catch (\Throwable $e) {
            $msg = substr($e->getMessage(), 0, 500);
            psai_set_last_error([$front_id, $back_id], $msg);
            return ['success' => false, 'error' => $msg];
        }
    }

    /**
     * Process a single attachment (discovers pair if needed).
     *
     * @param int $att_id Attachment ID (can be front or back)
     * @param bool $force Force re-classification
     * @return array{success: bool, error?: string, payload?: array}
     */
    public static function process_attachment(int $att_id, bool $force = false): array
    {
        // If this is the back, flip to the front as canonical
        $maybePair = (int)get_post_meta($att_id, '_ps_pair_id', true);
        $side = get_post_meta($att_id, '_ps_side', true);
        $front_id = ($side === 'back' && $maybePair) ? $maybePair : $att_id;

        // Get back_id if it exists
        $back_id = null;
        if ($side === 'front') {
            $back_id = (int)get_post_meta($front_id, '_ps_pair_id', true) ?: null;
        } elseif ($side === 'back') {
            $back_id = $att_id;
        }

        return self::classify_and_store($front_id, $back_id, $force);
    }
}
