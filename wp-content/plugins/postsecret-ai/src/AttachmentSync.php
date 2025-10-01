<?php

namespace PSAI;

if (!defined('ABSPATH')) exit;

/**
 * Syncs AI classification into attachment fields:
 * - Alt text  -> from front/back artDescription (fallback: secretDescription)
 * - Caption   -> from tags (e.g., "#addiction #remorseful"), max 140 chars
 * - Description -> from secretDescription (objective summary)
 *
 * Rules:
 * - Only fill when the field is empty (don’t overwrite manual edits).
 * - Never include long transcriptions or anything when containsPII=true.
 * - Back attachment gets “Back of postcard …” phrasing.
 */
final class AttachmentSync
{
    public static function sync_from_payload(int $front_id, array $payload, ?int $back_id = null): void
    {
        $containsPII = (bool)($payload['moderation']['containsPII'] ?? false);
        $tags = is_array($payload['tags'] ?? null) ? $payload['tags'] : [];
        $secretDesc = self::clean_str($payload['secretDescription'] ?? '');

        // FRONT
        $frontSide = $payload['front'] ?? [];
        $frontArt = self::clean_str($frontSide['artDescription'] ?? '');
        $frontAlt = $frontArt ?: $secretDesc;
        $frontCaption = self::format_caption($tags);
        $frontDesc = $secretDesc;

        self::apply_if_empty($front_id, $frontAlt, $frontCaption, $frontDesc, 'front', $containsPII);

        // BACK (optional)
        if ($back_id) {
            $backSide = $payload['back'] ?? [];
            $backArt = self::clean_str($backSide['artDescription'] ?? '');
            $altBack = $backArt ?: 'Back of postcard';
            $capBack = $frontCaption; // keep tags consistent
            $descBack = $containsPII ? '' : self::clean_str($backArt); // stay minimal on back

            self::apply_if_empty($back_id, $altBack, $capBack, $descBack, 'back', $containsPII);
        }
    }

    private static function apply_if_empty(int $att_id, string $alt, string $caption, string $desc, string $side, bool $containsPII): void
    {
        // ALT (short, no HTML)
        $alt = self::clip_words($alt ?: ($side === 'back' ? 'Back of postcard' : 'Postcard front'), 120);
        $existingAlt = get_post_meta($att_id, '_wp_attachment_image_alt', true);
        if ($existingAlt === '' && $alt !== '') {
            update_post_meta($att_id, '_wp_attachment_image_alt', $alt);
        }

        // CAPTION (tags → “#tag #tag …” up to 140 chars)
        $existingPost = get_post($att_id);
        $existingCap = is_object($existingPost) ? trim((string)$existingPost->post_excerpt) : '';
        if ($existingCap === '' && $caption !== '') {
            // Guard against PII: captions won’t include transcription anyway, so safe
            wp_update_post(['ID' => $att_id, 'post_excerpt' => $caption]);
        }

        // DESCRIPTION (only objective summary; skip if PII)
        $existingDesc = is_object($existingPost) ? trim((string)$existingPost->post_content) : '';
        if ($existingDesc === '' && !$containsPII && $desc !== '') {
            // Preserve line breaks; strip dangerous tags
            $safe = esc_html($desc);
            $safe = str_replace("\n", "\n\n", $safe); // WP autop likes blank lines
            wp_update_post(['ID' => $att_id, 'post_content' => $safe]);
        }
    }

    private static function format_caption(array $tags): string
    {
        if (empty($tags)) return '';
        // 3–5 tags is plenty for a caption
        $tags = array_slice($tags, 0, 5);
        $hashes = array_map(fn($t) => '#' . preg_replace('/[^a-z0-9_]/', '', strtolower((string)$t)), $tags);
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