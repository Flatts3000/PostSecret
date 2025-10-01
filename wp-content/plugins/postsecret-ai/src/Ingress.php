<?php

namespace PSAI;

if (!defined('ABSPATH')) exit;

final class Ingress
{
    /** Sideload $_FILES[...] to an attachment, set side meta, return attachment ID or null */
    public static function sideload(array $fileArr, string $side): ?int
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $fa = [
            'name' => sanitize_file_name($fileArr['name'] ?? ''),
            'type' => $fileArr['type'] ?? '',
            'tmp_name' => $fileArr['tmp_name'] ?? '',
            'error' => $fileArr['error'] ?? 0,
            'size' => (int)($fileArr['size'] ?? 0),
        ];
        if (empty($fa['tmp_name']) || !is_uploaded_file($fa['tmp_name'])) return null;

        $att_id = media_handle_sideload($fa, 0);
        if (is_wp_error($att_id)) return null;

        update_post_meta($att_id, '_ps_side', ($side === 'back') ? 'back' : 'front');
        self::index($att_id);
        return (int)$att_id;
    }

    /** Compute sha256 (+ dims) for simple duplicate checks */
    public static function index(int $att_id): void
    {
        $path = get_attached_file($att_id);
        if (!$path || !file_exists($path)) return;

        $sha = @hash_file('sha256', $path) ?: '';
        update_post_meta($att_id, '_ps_sha256', $sha);

        [$w, $h] = @getimagesize($path) ?: [0, 0];
        update_post_meta($att_id, '_ps_w', (int)$w);
        update_post_meta($att_id, '_ps_h', (int)$h);
        update_post_meta($att_id, '_ps_size', (int)(@filesize($path) ?: 0));
    }

    /** Mark _ps_duplicate_of if another attachment has the same sha256 */
    public static function mark_exact_duplicate(int $att_id): bool
    {
        $sha = get_post_meta($att_id, '_ps_sha256', true);
        if (!$sha) return false;
        $q = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_query' => [['key' => '_ps_sha256', 'value' => $sha]],
            'post__not_in' => [$att_id],
        ]);
        if ($q->have_posts()) {
            update_post_meta($att_id, '_ps_duplicate_of', (int)$q->posts[0]);
            return true;
        }
        return false;
    }

    /** Pair two attachments together (both directions) */
    public static function pair(?int $front_id, ?int $back_id): void
    {
        if ($front_id && $back_id) {
            update_post_meta($front_id, '_ps_pair_id', $back_id);
            update_post_meta($back_id, '_ps_pair_id', $front_id);
            update_post_meta($front_id, '_ps_side', 'front');
            update_post_meta($back_id, '_ps_side', 'back');
        }
    }
}

/** Store normalized payload + versioning on the canonical (front) attachment */
function psai_store_result(int $att_id, array $payload, string $model): void
{
    $promptVer = \PSAI\Prompt::VERSION . '#sha256:' . substr(hash('sha256', \PSAI\Prompt::TEXT), 0, 8);
    $tags = array_values(array_filter(array_map('strval', $payload['tags'] ?? [])));
    sort($tags);

    update_post_meta($att_id, '_ps_payload', $payload);
    update_post_meta($att_id, '_ps_tags', $tags);
    update_post_meta($att_id, '_ps_model', $model);
    update_post_meta($att_id, '_ps_prompt_version', $promptVer);
    update_post_meta($att_id, '_ps_updated_at', wp_date('c'));
}

/** (Optional) Add/refresh a simple manifest file for exports */
function psai_update_manifest(int $att_id, array $payload): void
{
    $u = wp_upload_dir();
    $dir = trailingslashit($u['basedir']) . 'postsecret-ai';
    wp_mkdir_p($dir);
    $path = $dir . '/manifest.json';

    $existing = [];
    if (file_exists($path)) {
        $existing = json_decode(file_get_contents($path), true) ?: [];
    }
    $items = $existing['items'] ?? [];

    $file = wp_basename(get_attached_file($att_id));
    $entry = ['sourceImage' => $file, 'json' => $att_id . '.json'];
    if (!empty($payload['tags'])) $entry['tags'] = $payload['tags'];

    // upsert by sourceImage
    $by = [];
    foreach ($items as $it) if (!empty($it['sourceImage'])) $by[$it['sourceImage']] = $it;
    $by[$file] = $entry;

    file_put_contents($path, json_encode(['items' => array_values($by)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

namespace PSAI;

if (!function_exists('psai_make_data_url')) {
    /**
     * Convert an attachment to a JPEG data URL (scaled down for tokens/bandwidth).
     * @return string data:image/jpeg;base64,...  (throws on failure)
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
            // Fallback: read raw and try to base64 (may be large)
            $raw = @file_get_contents($path);
            if ($raw === false) throw new \RuntimeException('Failed to read image.');
            $mime = wp_check_filetype($path)['type'] ?: 'image/jpeg';
            return 'data:' . $mime . ';base64,' . base64_encode($raw);
        }

        // Constrain to a reasonable size for LMMs
        $size = $editor->get_size();
        $w = (int)($size['width'] ?? 0);
        $h = (int)($size['height'] ?? 0);
        if ($w > $maxDim || $h > $maxDim) {
            $editor->resize($maxDim, $maxDim, false);
        }
        $editor->set_quality($quality);

        // Save to a temp JPEG and base64 it
        $tmp = wp_tempnam('psai');
        // ensure .jpg so the editor writes JPEG
        $tmpJpg = $tmp . '.jpg';
        $saved = $editor->save($tmpJpg, 'image/jpeg');
        if (is_wp_error($saved) || empty($saved['path'])) {
            // fallback to original bytes
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
}