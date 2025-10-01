<?php

namespace PSAI;

if (!defined('ABSPATH')) exit;

final class AdminMetaBox
{
    public static function boot(): void
    {
        add_action('add_meta_boxes', [__CLASS__, 'add']);
        add_filter('manage_upload_columns', [__CLASS__, 'colAdd']);
        add_action('manage_media_custom_column', [__CLASS__, 'colRender'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function add(): void
    {
        add_meta_box('psai_meta', 'PostSecret AI', [__CLASS__, 'render'], 'attachment', 'side', 'high');
    }

    public static function render(\WP_Post $post): void
    {
        $tags = get_post_meta($post->ID, '_ps_tags', true) ?: [];
        $model = get_post_meta($post->ID, '_ps_model', true);
        $pver = get_post_meta($post->ID, '_ps_prompt_version', true);
        $when = get_post_meta($post->ID, '_ps_updated_at', true);
        $err = get_post_meta($post->ID, '_ps_last_error', true);
        $dup = get_post_meta($post->ID, '_ps_duplicate_of', true);
        $near = get_post_meta($post->ID, '_ps_near_duplicate_of', true);
        $pair = get_post_meta($post->ID, '_ps_pair_id', true);
        $payload = get_post_meta($post->ID, '_ps_payload', true);

        $status = 'Queued';
        if ($err) $status = 'Error';
        elseif ($dup) $status = 'Duplicate';
        elseif ($near) $status = 'Near-duplicate';
        elseif ($payload) $status = 'Classified';

        // add near the top, after $status is computed
        $proc_url = wp_nonce_url(
            admin_url('admin-post.php?action=psai_process_now&att=' . (int)$post->ID),
            'psai_process_now_' . (int)$post->ID
        );
        echo '<p><a href="' . esc_url($proc_url) . '" class="button button-secondary">Process now</a></p>';

        echo '<div class="psai-box">';
        echo '<p><strong>Status:</strong> <span class="psai-badge">' . esc_html($status) . '</span></p>';

        if ($tags && is_array($tags)) {
            echo '<p><strong>Tags:</strong><br>';
            foreach ($tags as $t) echo '<span class="psai-chip">' . esc_html($t) . '</span> ';
            echo '</p>';
        }

        echo '<p><strong>Model:</strong> ' . esc_html($model ?: '—') . '<br>';
        echo '<strong>Prompt:</strong> ' . esc_html($pver ?: '—') . '<br>';
        echo '<strong>Updated:</strong> ' . esc_html($when ?: '—') . '</p>';

        $orient = get_post_meta($post->ID, '_ps_orientation', true);
        $primary = get_post_meta($post->ID, '_ps_primary_hex', true);
        $palette = get_post_meta($post->ID, '_ps_palette', true) ?: [];

        echo '<p><strong>Orientation:</strong> ' . esc_html($orient ?: '—') . '</p>';
        if ($primary) {
            echo '<p><strong>Primary:</strong> <span style="display:inline-block;width:14px;height:14px;background:' . esc_attr($primary) . ';border:1px solid #ccd;"></span> ' . esc_html($primary) . '</p>';
        }
        if ($palette) {
            echo '<p><strong>Palette:</strong><br>';
            foreach ($palette as $hex) {
                echo '<span class="psai-chip" style="background:' . esc_attr($hex) . '; color:#000; border:1px solid #ccd;">' . esc_html($hex) . '</span> ';
            }
            echo '</p>';
        }

        if ($pair) {
            $url = get_edit_post_link((int)$pair);
            echo '<p><strong>Paired side:</strong> <a href="' . esc_url($url) . '">View</a></p>';
        }

        if ($dup) {
            $url = get_edit_post_link((int)$dup);
            echo '<p><strong>Duplicate of:</strong> <a href="' . esc_url($url) . '">View original</a></p>';
        } elseif ($near) {
            $url = get_edit_post_link((int)$near);
            echo '<p><strong>Near-duplicate of:</strong> <a href="' . esc_url($url) . '">View candidate</a></p>';
        }

        if ($err) {
            echo '<p><strong>Error:</strong><br><code style="white-space:pre-wrap">' . esc_html($err) . '</code></p>';
        }

        // Raw JSON viewer
        if ($payload) {
            $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            echo '<details><summary>View raw JSON</summary>';
            echo '<textarea readonly style="width:100%;height:220px;font-family:Menlo,Consolas,monospace;">' . esc_textarea($json) . '</textarea>';
            echo '</details>';
        }

        echo '</div>';
    }

    public static function colAdd($cols)
    {
        $cols['psai'] = 'PS';
        return $cols;
    }

    public static function colRender($col, $attach_id)
    {
        if ($col !== 'psai') return;
        $err = get_post_meta($attach_id, '_ps_last_error', true);
        $dup = get_post_meta($attach_id, '_ps_duplicate_of', true);
        $near = get_post_meta($attach_id, '_ps_near_duplicate_of', true);
        $has = get_post_meta($attach_id, '_ps_payload', true);

        if ($err) echo '<span class="psai-dot psai-red" title="Error">!</span>';
        elseif ($dup) echo '<span class="psai-dot psai-gray" title="Duplicate">=</span>';
        elseif ($near) echo '<span class="psai-dot psai-amber" title="Near-duplicate">≈</span>';
        elseif ($has) echo '<span class="psai-dot psai-green" title="Classified">✓</span>';
        else           echo '<span class="psai-dot psai-blue" title="Queued">•</span>';
    }

    public static function assets($hook)
    {
        if ($hook !== 'upload.php' && $hook !== 'post.php') return;
        $css = '
      .psai-badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#e9eff5}
      .psai-chip{display:inline-block;margin:2px 4px 0 0;padding:2px 8px;border-radius:12px;background:#f0f2f4;font-size:12px}
      .psai-dot{display:inline-block;font-weight:700}
      .psai-green{color:#008a20}.psai-blue{color:#2271b1}.psai-gray{color:#777}.psai-amber{color:#b95000}.psai-red{color:#b32d2e}
      .psai-box details{margin-top:6px}
      .psai-box textarea{margin-top:8px}
    ';
        wp_add_inline_style('common', $css);
    }
}

AdminMetaBox::boot();