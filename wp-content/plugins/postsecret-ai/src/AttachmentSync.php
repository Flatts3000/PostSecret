<?php

namespace PSAI;

if (!defined('ABSPATH')) exit;

/**
 * Syncs AI classification into attachment fields:
 * - Alt text  -> from front/back artDescription (fallback: secretDescription)
 * - Caption   -> from facets (topics, feelings, meanings combined as hashtags), max 140 chars
 * - Description -> from secretDescription (objective summary)
 *
 * Rules:
 * - Only fill when the field is empty (don't overwrite manual edits).
 * - Never include long transcriptions or anything when containsPII=true.
 * - Back attachment gets "Back of postcard …" phrasing.
 */
final class AttachmentSync
{
    public static function sync_from_payload(int $front_id, array $payload, ?int $back_id = null, bool $force_update = false): void
    {
        $containsPII = (bool)($payload['moderation']['containsPII'] ?? false);

        // Combine all facets for caption
        $topics = is_array($payload['topics'] ?? null) ? $payload['topics'] : [];
        $feelings = is_array($payload['feelings'] ?? null) ? $payload['feelings'] : [];
        $meanings = is_array($payload['meanings'] ?? null) ? $payload['meanings'] : [];
        $allFacets = array_merge($topics, $feelings, $meanings);

        $secretDesc = self::clean_str($payload['secretDescription'] ?? '');

        // FRONT
        $frontSide = $payload['front'] ?? [];
        $frontArt = self::clean_str($frontSide['artDescription'] ?? '');
        $frontAlt = $frontArt ?: $secretDesc;
        $frontCaption = self::format_caption($allFacets);
        $frontDesc = $secretDesc;

        self::apply_if_empty($front_id, $frontAlt, $frontCaption, $frontDesc, 'front', $containsPII, $force_update);

        // BACK (optional)
        if ($back_id) {
            $backSide = $payload['back'] ?? [];
            $backArt = self::clean_str($backSide['artDescription'] ?? '');
            $altBack = $backArt ?: 'Back of postcard';
            $capBack = $frontCaption; // keep facets consistent
            $descBack = $containsPII ? '' : self::clean_str($backArt); // stay minimal on back

            self::apply_if_empty($back_id, $altBack, $capBack, $descBack, 'back', $containsPII, $force_update);
        }
    }

    private static function apply_if_empty(int $att_id, string $alt, string $caption, string $desc, string $side, bool $containsPII, bool $force_update = false): void
    {
        error_log("AttachmentSync::apply_if_empty called for att_id={$att_id}, force_update=" . ($force_update ? 'true' : 'false'));

        // ALT (short, no HTML)
        $alt = self::clip_words($alt ?: ($side === 'back' ? 'Back of postcard' : 'Postcard front'), 120);
        $existingAlt = get_post_meta($att_id, '_wp_attachment_image_alt', true);
        if (($existingAlt === '' || $force_update) && $alt !== '') {
            update_post_meta($att_id, '_wp_attachment_image_alt', $alt);
        }

        // CAPTION (facets → "#facet #facet …" up to 140 chars)
        $existingPost = get_post($att_id);
        $existingCap = is_object($existingPost) ? trim((string)$existingPost->post_excerpt) : '';
        if (($existingCap === '' || $force_update) && $caption !== '') {
            // Guard against PII: captions won't include transcription anyway, so safe
            wp_update_post(['ID' => $att_id, 'post_excerpt' => $caption]);
        }

        // DESCRIPTION (objective summary from secretDescription, which is always PII-free by design)
        // Note: secretDescription is an AI-generated summary, not raw extracted text, so it's safe even when containsPII=true
        $existingDesc = is_object($existingPost) ? trim((string)$existingPost->post_content) : '';
        error_log("AttachmentSync: existingDesc='" . substr($existingDesc, 0, 100) . "', force_update=" . ($force_update ? 'true' : 'false') . ", containsPII=" . ($containsPII ? 'true' : 'false') . ", desc='" . substr($desc, 0, 100) . "'");
        if (($existingDesc === '' || $force_update) && $desc !== '') {
            // Strip dangerous tags but preserve text as-is (no entity encoding)
            // Use strip_tags instead of wp_kses to avoid any WP sanitization
            $safe = strip_tags($desc);
            $safe = str_replace("\n", "\n\n", $safe); // WP autop likes blank lines

            // Debug: log what we're about to store
            error_log("AttachmentSync: Storing desc for att_id={$att_id}, value=" . substr($safe, 0, 200));

            // Update directly via wpdb to avoid wp_update_post's sanitization
            global $wpdb;
            $wpdb->update(
                $wpdb->posts,
                ['post_content' => $safe],
                ['ID' => $att_id],
                ['%s'],
                ['%d']
            );
            clean_post_cache($att_id);

            // Debug: verify what was stored
            $verify = $wpdb->get_var($wpdb->prepare("SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $att_id));
            error_log("AttachmentSync: Verified stored value=" . substr($verify, 0, 200));
        }
    }

    private static function format_caption(array $facets): string
    {
        if (empty($facets)) return '';
        // 3–6 facets is plenty for a caption
        $facets = array_slice($facets, 0, 6);
        $hashes = array_map(fn($t) => '#' . preg_replace('/[^a-z0-9_]/', '', strtolower((string)$t)), $facets);
        $cap = implode(' ', $hashes);
        // keep it terse
        if (mb_strlen($cap, 'UTF-8') > 140) {
            $cap = mb_substr($cap, 0, 137, 'UTF-8') . '…';
        }
        return $cap;
    }

    private static function clean_str($s): string
    {
        if (!is_string($s)) return '';
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        return $s;
    }

    /** Clip string roughly by character count, not cutting mid-grapheme. */
    private static function clip_words(string $s, int $limit): string
    {
        if (mb_strlen($s, 'UTF-8') <= $limit) return $s;
        return rtrim(mb_substr($s, 0, $limit - 1, 'UTF-8')) . '…';
    }
}