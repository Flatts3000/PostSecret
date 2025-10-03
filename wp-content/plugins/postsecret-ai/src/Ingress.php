<?php

namespace PSAI;

if (!defined('ABSPATH')) exit;

/**
 * Ingress: handles file sideload → attachment + basic indexing/normalization.
 */
final class Ingress
{
    /**
     * Sideload a file (from $_FILES[..]) into the Media Library,
     * set postcard side, index bytes/dims, and prime meta.
     *
     * @param array $fileArr One entry from $_FILES (e.g. $_FILES['front'])
     * @param string $side 'front' | 'back'
     * @return int|null        New attachment ID or null on failure
     */
    public static function sideload(array $fileArr, string $side): ?int
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $fa = [
            'name' => sanitize_file_name($fileArr['name'] ?? ''),
            'type' => (string)($fileArr['type'] ?? ''),
            'tmp_name' => (string)($fileArr['tmp_name'] ?? ''),
            'error' => (int)($fileArr['error'] ?? 0),
            'size' => (int)($fileArr['size'] ?? 0),
        ];
        if (empty($fa['tmp_name']) || !is_uploaded_file($fa['tmp_name'])) {
            return null;
        }

        $att_id = media_handle_sideload($fa, 0);
        if (is_wp_error($att_id)) {
            return null;
        }
        $att_id = (int)$att_id;

        // Convert to WebP after upload (raw image used for classification first)
        // This happens later in ClassificationService after classification is done
        update_post_meta($att_id, '_ps_needs_webp_conversion', '1');

        // Side + initial flags
        update_post_meta($att_id, '_ps_side', ($side === 'back') ? 'back' : 'front');
        update_post_meta($att_id, '_ps_is_vetted', '0');
        update_post_meta($att_id, '_ps_review_status', 'needs_review'); // neutral default
        delete_post_meta($att_id, '_ps_last_error');
        delete_post_meta($att_id, '_ps_duplicate_of');
        delete_post_meta($att_id, '_ps_near_duplicate_of');

        // Basic index (hash/dims/bytes)
        self::index($att_id);

        // Optional enrichments: orientation + color/palette
        if (class_exists('\PSAI\Metadata') && method_exists('\PSAI\Metadata', 'compute_and_store')) {
            \PSAI\Metadata::compute_and_store($att_id);
        }

        // If you keep caption/alt/description in sync with AI later,
        // they'll be filled by AttachmentSync after classification.

        return $att_id;
    }

    /**
     * Index the underlying file for quick duplicate/size checks.
     * Stores: _ps_sha256, _ps_w, _ps_h, _ps_size
     */
    public static function index(int $att_id): void
    {
        $path = get_attached_file($att_id);
        if (!$path || !file_exists($path)) return;

        $sha = @hash_file('sha256', $path) ?: '';
        update_post_meta($att_id, '_ps_sha256', $sha);

        $w = 0;
        $h = 0;
        $info = @getimagesize($path);
        if (is_array($info)) {
            $w = (int)($info[0] ?? 0);
            $h = (int)($info[1] ?? 0);
        }
        update_post_meta($att_id, '_ps_w', $w);
        update_post_meta($att_id, '_ps_h', $h);
        update_post_meta($att_id, '_ps_size', (int)(@filesize($path) ?: 0));
    }

    /**
     * If another attachment already has the same _ps_sha256,
     * label current as exact duplicate.
     *
     * @return bool true if a duplicate was found and marked.
     */
    public static function mark_exact_duplicate(int $att_id): bool
    {
        $sha = get_post_meta($att_id, '_ps_sha256', true);
        if (!$sha) return false;

        $q = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_query' => [
                ['key' => '_ps_sha256', 'value' => $sha],
            ],
            'post__not_in' => [$att_id],
        ]);

        if ($q->have_posts()) {
            update_post_meta($att_id, '_ps_duplicate_of', (int)$q->posts[0]);
            return true;
        }
        return false;
    }

    /**
     * Pair two attachments together (both directions) and ensure side labels.
     */
    public static function pair(?int $front_id, ?int $back_id): void
    {
        if ($front_id && $back_id) {
            update_post_meta($front_id, '_ps_pair_id', $back_id);
            update_post_meta($back_id, '_ps_pair_id', $front_id);
            update_post_meta($front_id, '_ps_side', 'front');
            update_post_meta($back_id, '_ps_side', 'back');
        }
    }

    /**
     * Normalize flags from a previously saved AI payload.
     * Useful for "Process now" or any repair action.
     */
    public static function normalize_from_existing_payload(int $att_id): void
    {
        $payload = get_post_meta($att_id, '_ps_payload', true);
        if (!is_array($payload)) return;

        $side = get_post_meta($att_id, '_ps_side', true);
        if ($side !== 'front' && $side !== 'back') {
            update_post_meta($att_id, '_ps_side', 'front');
        }

        $review = $payload['moderation']['reviewStatus'] ?? 'auto_vetted';
        update_post_meta($att_id, '_ps_review_status', $review);
        update_post_meta($att_id, '_ps_is_vetted', ($review === 'auto_vetted') ? '1' : '0');

        // Recompute orientation/color if helper exists
        if (class_exists('\PSAI\Metadata') && method_exists('\PSAI\Metadata', 'compute_and_store')) {
            \PSAI\Metadata::compute_and_store($att_id);
        }
    }

    /**
     * Convert attachment to WebP format.
     *
     * @param int $att_id Attachment ID
     * @return bool Success
     */
    public static function convert_to_webp(int $att_id): bool
    {
        $file_path = get_attached_file($att_id);
        if (!$file_path || !file_exists($file_path)) {
            return false;
        }

        // Skip if already WebP
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if ($ext === 'webp') {
            delete_post_meta($att_id, '_ps_needs_webp_conversion');
            return true;
        }

        // Load image editor
        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            return false;
        }

        // Get image dimensions
        $size = $editor->get_size();
        $width = (int)($size['width'] ?? 0);
        $height = (int)($size['height'] ?? 0);

        // Set quality for WebP (85 is a good balance)
        $editor->set_quality(85);

        // Generate WebP filename
        $dir = dirname($file_path);
        $basename = wp_basename($file_path, '.' . $ext);
        $webp_path = trailingslashit($dir) . $basename . '.webp';

        // Save as WebP
        $saved = $editor->save($webp_path, 'image/webp');
        if (is_wp_error($saved) || empty($saved['path'])) {
            return false;
        }

        // Delete original file
        @unlink($file_path);

        // Update attachment metadata
        update_attached_file($att_id, $saved['path']);

        // Update post MIME type
        wp_update_post([
            'ID' => $att_id,
            'post_mime_type' => 'image/webp',
        ]);

        // Update attachment metadata with new dimensions/size
        $metadata = [
            'width' => $width,
            'height' => $height,
            'file' => wp_basename($saved['path']),
            'filesize' => filesize($saved['path']),
        ];

        // Generate thumbnails for WebP
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($att_id, $saved['path']);
        wp_update_attachment_metadata($att_id, $metadata);

        // Re-index with new hash
        self::index($att_id);

        // Mark conversion complete
        delete_post_meta($att_id, '_ps_needs_webp_conversion');
        update_post_meta($att_id, '_ps_converted_to_webp', '1');
        update_post_meta($att_id, '_ps_original_format', $ext);

        return true;
    }
}

/**
 * Store normalized payload + versioning on the canonical (front) attachment.
 * Also sets cheap filter fields for REST/meta_query.
 */
function psai_store_result(int $att_id, array $payload, string $model): void
{
    $promptVer = \PSAI\Prompt::VERSION . '#sha256:' . substr(hash('sha256', \PSAI\Prompt::TEXT), 0, 8);

    // Extract and normalize facets
    $topics = array_values(array_filter(array_map('strval', $payload['topics'] ?? [])));
    $feelings = array_values(array_filter(array_map('strval', $payload['feelings'] ?? [])));
    $meanings = array_values(array_filter(array_map('strval', $payload['meanings'] ?? [])));
    sort($topics);
    sort($feelings);
    sort($meanings);

    update_post_meta($att_id, '_ps_payload', $payload);
    update_post_meta($att_id, '_ps_topics', $topics);
    update_post_meta($att_id, '_ps_feelings', $feelings);
    update_post_meta($att_id, '_ps_meanings', $meanings);
    update_post_meta($att_id, '_ps_teaches_wisdom', (bool)($payload['teachesWisdom'] ?? false) ? '1' : '0');
    update_post_meta($att_id, '_ps_model', $model);
    update_post_meta($att_id, '_ps_prompt_version', $promptVer);
    update_post_meta($att_id, '_ps_updated_at', wp_date('c'));

    // Vetted flags (store as strings for WP meta_query)
    $review = $payload['moderation']['reviewStatus'] ?? 'auto_vetted';
    update_post_meta($att_id, '_ps_review_status', $review);               // auto_vetted | needs_review | reject_candidate
    update_post_meta($att_id, '_ps_is_vetted', ($review === 'auto_vetted') ? '1' : '0');

    // Optional: sync attachment Alt/Caption/Description from payload (if you use it)
    if (class_exists('\PSAI\AttachmentSync')) {
        \PSAI\AttachmentSync::sync_from_payload($att_id, $payload, null);
    }
}

/**
 * (Optional) Add/refresh a simple manifest file for exports.
 */
function psai_update_manifest(int $att_id, array $payload): void
{
    $u = wp_upload_dir();
    $dir = trailingslashit($u['basedir']) . 'postsecret-ai';
    wp_mkdir_p($dir);
    $path = $dir . '/manifest.json';

    $existing = file_exists($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    $items = $existing['items'] ?? [];

    $file = wp_basename(get_attached_file($att_id));
    $entry = ['sourceImage' => $file, 'json' => $att_id . '.json'];
    if (!empty($payload['topics'])) $entry['topics'] = array_values((array)$payload['topics']);
    if (!empty($payload['feelings'])) $entry['feelings'] = array_values((array)$payload['feelings']);
    if (!empty($payload['meanings'])) $entry['meanings'] = array_values((array)$payload['meanings']);

    // upsert by sourceImage
    $by = [];
    foreach ($items as $it) {
        if (!empty($it['sourceImage'])) $by[$it['sourceImage']] = $it;
    }
    $by[$file] = $entry;

    file_put_contents($path, json_encode(['items' => array_values($by)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Convert an attachment to a JPEG data URL (scaled down for tokens/bandwidth).
 *
 * @return string data:image/jpeg;base64,... (throws on failure)
 */
function psai_make_data_url(int $att_id, int $maxDim = 1600, int $quality = 85): string
{
    $path = get_attached_file($att_id);
    if (!$path || !file_exists($path)) {
        throw new \RuntimeException('Attachment file not found.');
    }

    // Use WP image editor to normalize and resize
    $editor = wp_get_image_editor($path);
    if (is_wp_error($editor)) {
        $raw = @file_get_contents($path);
        if ($raw === false) throw new \RuntimeException('Failed to read image.');
        $mime = wp_check_filetype($path)['type'] ?: 'image/jpeg';
        return 'data:' . $mime . ';base64,' . base64_encode($raw);
    }

    // Constrain for model-friendly size
    $size = $editor->get_size();
    $w = (int)($size['width'] ?? 0);
    $h = (int)($size['height'] ?? 0);
    if ($w > $maxDim || $h > $maxDim) {
        $editor->resize($maxDim, $maxDim, false);
    }
    $editor->set_quality($quality);

    // Save temp JPEG → base64
    // Use WordPress temp dir, fallback to system temp
    $tmpDir = function_exists('wp_tempnam')
        ? dirname(wp_tempnam('psai'))
        : (function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir());
    $tmpJpg = tempnam($tmpDir, 'psai_') . '.jpg';
    $saved = $editor->save($tmpJpg, 'image/jpeg');
    if (is_wp_error($saved) || empty($saved['path'])) {
        $raw = @file_get_contents($path);
        if ($raw === false) throw new \RuntimeException('Failed to read image.');
        $mime = wp_check_filetype($path)['type'] ?: 'image/jpeg';
        return 'data:' . $mime . ';base64,' . base64_encode($raw);
    }

    $bytes = @file_get_contents($saved['path']);
    @unlink($saved['path']);
    if ($bytes === false) throw new \RuntimeException('Failed to read temp JPEG.');
    return 'data:image/jpeg;base64,' . base64_encode($bytes);
}