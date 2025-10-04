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
        // Core metas
        $topics = get_post_meta($post->ID, '_ps_topics', true) ?: [];
        $feelings = get_post_meta($post->ID, '_ps_feelings', true) ?: [];
        $meanings = get_post_meta($post->ID, '_ps_meanings', true) ?: [];
        $vibe = get_post_meta($post->ID, '_ps_vibe', true) ?: [];
        $style = get_post_meta($post->ID, '_ps_style', true) ?: 'unknown';
        $locations = get_post_meta($post->ID, '_ps_locations', true) ?: [];
        $wisdom = get_post_meta($post->ID, '_ps_wisdom', true) ?: '';
        $model = get_post_meta($post->ID, '_ps_model', true);
        $pver = get_post_meta($post->ID, '_ps_prompt_version', true);
        $when = get_post_meta($post->ID, '_ps_updated_at', true);
        $payload = get_post_meta($post->ID, '_ps_payload', true);

        // Health / linkage
        $err = get_post_meta($post->ID, '_ps_last_error', true);
        $dup = get_post_meta($post->ID, '_ps_duplicate_of', true);
        $near = get_post_meta($post->ID, '_ps_near_duplicate_of', true);
        $pair = get_post_meta($post->ID, '_ps_pair_id', true);

        // Triage flags
        $side = get_post_meta($post->ID, '_ps_side', true) ?: '—';
        $review = get_post_meta($post->ID, '_ps_review_status', true) ?: '—';
        $vetted = get_post_meta($post->ID, '_ps_is_vetted', true);
        $vetted = ($vetted === '1' || $vetted === 1 || $vetted === true) ? 'yes' : 'no';

        // Presentation status
        $status = 'Queued';
        if ($err) $status = 'Error';
        elseif ($dup) $status = 'Duplicate';
        elseif ($near) $status = 'Near-duplicate';
        elseif ($payload) $status = 'Classified';

        // Actions: Re-classify only
        $reclassify_url = wp_nonce_url(
            admin_url('admin-post.php?action=psai_reclassify&att=' . (int)$post->ID),
            'psai_reclassify_' . (int)$post->ID
        );
        echo '<p>';
        echo '<a href="' . esc_url($reclassify_url) . '" class="button button-secondary" onclick="return confirm(\'Re-run AI classification? This will overwrite existing data.\')">Re-classify</a>';
        echo '</p>';

        echo '<div class="psai-box">';

        // Header status
        echo '<p><strong>Status:</strong> <span class="psai-badge">' . esc_html($status) . '</span></p>';

        // Triage summary (side / review / vetted)
        echo '<p style="margin-top:10px"><strong>Side:</strong> ' . esc_html($side) . '<br>';
        echo '<strong>Review:</strong> <span class="psai-pill psai-rv-' . esc_attr($review) . '">' . esc_html($review) . '</span><br>';
        echo '<strong>Vetted:</strong> ' . esc_html($vetted) . '</p>';

        // Text-only facets
        if ($topics && is_array($topics)) {
            echo '<p><strong>Topics:</strong><br>';
            foreach ($topics as $t) echo '<span class="psai-chip psai-chip-topic">' . esc_html($t) . '</span> ';
            echo '</p>';
        }
        if ($feelings && is_array($feelings)) {
            echo '<p><strong>Feelings:</strong><br>';
            foreach ($feelings as $f) echo '<span class="psai-chip psai-chip-feeling">' . esc_html($f) . '</span> ';
            echo '</p>';
        }
        if ($meanings && is_array($meanings)) {
            echo '<p><strong>Meanings:</strong><br>';
            foreach ($meanings as $m) echo '<span class="psai-chip psai-chip-meaning">' . esc_html($m) . '</span> ';
            echo '</p>';
        }

        // Image+text facets
        if ($vibe && is_array($vibe) && count($vibe) > 0) {
            echo '<p><strong>Vibe:</strong><br>';
            foreach ($vibe as $v) echo '<span class="psai-chip psai-chip-vibe">' . esc_html($v) . '</span> ';
            echo '</p>';
        }
        if ($style && $style !== 'unknown') {
            echo '<p><strong>Style:</strong> <span class="psai-chip psai-chip-style">' . esc_html($style) . '</span></p>';
        }
        if ($locations && is_array($locations) && count($locations) > 0) {
            echo '<p><strong>Locations:</strong><br>';
            foreach ($locations as $loc) echo '<span class="psai-chip psai-chip-location">' . esc_html($loc) . '</span> ';
            echo '</p>';
        }

        // Wisdom
        if ($wisdom && trim($wisdom) !== '') {
            echo '<p><strong>Wisdom:</strong><br>';
            echo '<em style="color:#78350f;background:#fef3c7;padding:4px 8px;border-radius:4px;display:inline-block;">' . esc_html($wisdom) . '</em>';
            echo '</p>';
        }

        // Model / prompt / timestamp
        echo '<p><strong>Model:</strong> ' . esc_html($model ?: '—') . '<br>';
        echo '<strong>Prompt:</strong> ' . esc_html($pver ?: '—') . '<br>';
        echo '<strong>Updated:</strong> ' . esc_html($when ?: '—') . '</p>';

        // Embedding info
        $embedding = \PSAI\EmbeddingService::get_embedding($post->ID);
        if ($embedding) {
            $dim = $embedding['dimension'];
            $model_ver = esc_html($embedding['model_version']);
            $updated = esc_html($embedding['updated_at']);
            echo '<p><strong>Embedding:</strong> ' . $dim . 'd vector<br>';
            echo '<strong>Model:</strong> ' . $model_ver . '<br>';
            echo '<strong>Generated:</strong> ' . $updated . '</p>';
        } else {
            echo '<p><strong>Embedding:</strong> <span style="color:#b95000;">Not generated</span></p>';
        }

        // Quick visual metadata
        $orient = get_post_meta($post->ID, '_ps_orientation', true);
        $primary = get_post_meta($post->ID, '_ps_primary_hex', true);
        $palette = get_post_meta($post->ID, '_ps_palette', true) ?: [];

        echo '<p><strong>Orientation:</strong> ' . esc_html($orient ?: '—') . '</p>';
        if ($primary) {
            echo '<p><strong>Primary:</strong> <span style="display:inline-block;width:14px;height:14px;background:' . esc_attr($primary) . ';border:1px solid #ccd;"></span> ' . esc_html($primary) . '</p>';
        }
        if ($palette) {
            echo '<p><strong>Palette:</strong><br>';
            foreach ((array)$palette as $hex) {
                $hex = (string)$hex;
                echo '<span class="psai-chip" style="background:' . esc_attr($hex) . '; color:#000; border:1px solid #ccd;">' . esc_html($hex) . '</span> ';
            }
            echo '</p>';
        }

        // Pair / duplicates
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

        // Error (if any)
        if ($err) {
            echo '<p><strong>Error:</strong><br><code style="white-space:pre-wrap;background:#fee;padding:8px;display:block;border-radius:3px;">' . esc_html($err) . '</code></p>';

            // Show link to debug logs if available
            $debug_url = rest_url('psai/v1/debug-log?lines=100');
            echo '<p style="margin-top:8px;"><a href="' . esc_url($debug_url) . '" target="_blank" class="button button-small">View Debug Logs</a></p>';

            // Check if there's a transient error with more details
            $transient_err = get_transient('_ps_last_embedding_error');
            if ($transient_err && $transient_err !== $err) {
                echo '<details style="margin-top:8px;"><summary style="cursor:pointer;color:#b32d2e;">Additional error details</summary>';
                echo '<code style="white-space:pre-wrap;background:#fff4e6;padding:8px;display:block;border-radius:3px;margin-top:4px;">' . esc_html($transient_err) . '</code>';
                echo '</details>';
            }
        }

        // Show Qdrant sync warning if embedding exists but Qdrant failed
        $qdrant_err = get_transient('_ps_last_qdrant_error');
        if ($embedding && $qdrant_err) {
            echo '<p><strong>Qdrant Sync Warning:</strong><br>';
            echo '<code style="white-space:pre-wrap;background:#fff4e6;padding:8px;display:block;border-radius:3px;">';
            echo 'Embedding stored in MySQL but not synced to Qdrant: ' . esc_html($qdrant_err);
            echo '</code></p>';
        }

        // Show diagnostic button for troubleshooting
        $diag_url = rest_url('psai/v1/qdrant-status');
        echo '<p style="margin-top:8px;">';
        echo '<a href="' . esc_url($diag_url) . '" target="_blank" class="button button-small">Check Qdrant Status</a> ';

        // Add "Initialize Qdrant" button
        echo '<button type="button" class="button button-small psai-init-qdrant" style="margin-left:4px;">Initialize Qdrant Collection</button>';
        echo '</p>';

        // Add inline JavaScript for the button
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            var btn = document.querySelector(".psai-init-qdrant");
            if (btn) {
                btn.addEventListener("click", function() {
                    if (!confirm("Initialize Qdrant collection? This is safe to run multiple times.")) return;
                    btn.disabled = true;
                    btn.textContent = "Initializing...";

                    fetch("' . esc_js(rest_url('psai/v1/qdrant-init')) . '", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                            "X-WP-Nonce": "' . esc_js(wp_create_nonce('wp_rest')) . '"
                        }
                    })
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        btn.disabled = false;
                        if (data.success) {
                            btn.textContent = "✓ Collection Initialized";
                            btn.style.background = "#00a32a";
                            btn.style.color = "#fff";
                            alert("Qdrant collection initialized successfully!");
                        } else {
                            btn.textContent = "Initialize Qdrant Collection";
                            alert("Error: " + (data.message || "Unknown error"));
                        }
                    })
                    .catch(function(err) {
                        btn.disabled = false;
                        btn.textContent = "Initialize Qdrant Collection";
                        alert("Request failed: " + err.message);
                    });
                });
            }
        });
        </script>';

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

        if ($err) echo '<span class="psai-dot psai-red"   title="Error">!</span>';
        elseif ($dup) echo '<span class="psai-dot psai-gray"  title="Duplicate">=</span>';
        elseif ($near) echo '<span class="psai-dot psai-amber" title="Near-duplicate">≈</span>';
        elseif ($has) echo '<span class="psai-dot psai-green" title="Classified">✓</span>';
        else           echo '<span class="psai-dot psai-blue"  title="Queued">•</span>';
    }

    public static function assets($hook)
    {
        if ($hook !== 'upload.php' && $hook !== 'post.php') return;

        $css = '
        .psai-badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#e9eff5}
        .psai-chip{display:inline-block;margin:2px 4px 0 0;padding:2px 8px;border-radius:12px;background:#f0f2f4;font-size:12px}
        .psai-chip-topic{background:#e6f3ff;color:#0c4a6e}
        .psai-chip-feeling{background:#fff4e6;color:#78350f}
        .psai-chip-meaning{background:#f0fdf4;color:#14532d}
        .psai-chip-vibe{background:#f3e8ff;color:#581c87}
        .psai-chip-style{background:#fef3e2;color:#78350f}
        .psai-chip-location{background:#e0f2fe;color:#0c4a6e}
        .psai-dot{display:inline-block;font-weight:700}
        .psai-green{color:#008a20}.psai-blue{color:#2271b1}.psai-gray{color:#777}.psai-amber{color:#b95000}.psai-red{color:#b32d2e}
        .psai-box details{margin-top:6px}
        .psai-box textarea{margin-top:8px}

        /* review status pill colors */
        .psai-pill{display:inline-block;padding:2px 8px;border-radius:999px;background:#eef3f8;font-size:12px}
        .psai-rv-auto_vetted{background:#e6f8ec;color:#165f2d}
        .psai-rv-needs_review{background:#fff3e6;color:#7a3e00}
        .psai-rv-reject_candidate{background:#fdeaea;color:#7d1c1c}
        ';

        // Use a core style handle so inline CSS prints in admin
        wp_add_inline_style('common', $css);
    }
}

AdminMetaBox::boot();