<?php

namespace PSAI;

if (!defined('ABSPATH')) exit;

class AdminPromptEditor
{
    public static function render()
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

        $opts = get_option(Settings::OPTION, []) ?: [];
        $custom = $opts['CUSTOM_PROMPT'] ?? '';
        $builtin_version = Prompt::VERSION;
        $is_custom = trim($custom) !== '';

        echo '<div class="wrap">';
        echo '<h1>Prompt Editor</h1>';

        // Show success/error messages
        if (isset($_GET['psai_prompt_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Prompt saved successfully.</p></div>';
        }
        if (isset($_GET['psai_prompt_reset'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Prompt reset to built-in version.</p></div>';
        }

        // Current status
        echo '<div style="background:#f0f6fc;border:1px solid #0969da;border-radius:6px;padding:16px;margin:20px 0;">';
        echo '<p style="margin:0;"><strong>Current prompt:</strong> ';
        if ($is_custom) {
            echo '<span style="color:#cf222e;">Custom prompt</span> (Built-in version: v' . esc_html($builtin_version) . ')';
        } else {
            echo '<span style="color:#1a7f37;">Built-in prompt v' . esc_html($builtin_version) . '</span>';
        }
        echo '</p>';
        echo '</div>';

        // Instructions
        echo '<div style="background:#fff8c5;border:1px solid #d4a72c;border-radius:6px;padding:16px;margin:20px 0;">';
        echo '<p style="margin:0;"><strong>Instructions:</strong></p>';
        echo '<ul style="margin:8px 0 0 0;">';
        echo '<li>Edit the prompt below to customize the AI classification behavior</li>';
        echo '<li>Leave the textarea empty to use the built-in prompt (recommended)</li>';
        echo '<li>Changes take effect immediately for all new classifications</li>';
        echo '<li>Use the "Reset to Built-in" button to restore the default prompt</li>';
        echo '</ul>';
        echo '</div>';

        // Editor form
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('psai_save_prompt');
        echo '<input type="hidden" name="action" value="psai_save_prompt" />';

        echo '<div style="margin:20px 0;">';
        echo '<textarea name="psai_custom_prompt" id="psai-prompt-editor" rows="40" style="width:100%;font-family:\'Courier New\',Consolas,Monaco,monospace;font-size:13px;line-height:1.6;padding:12px;border:1px solid #8c8f94;border-radius:4px;resize:vertical;">';
        if ($is_custom) {
            echo esc_textarea($custom);
        } else {
            // Show built-in prompt as placeholder for reference
            echo esc_textarea(Prompt::TEXT);
        }
        echo '</textarea>';
        echo '</div>';

        echo '<div style="display:flex;gap:12px;align-items:center;">';
        submit_button('Save Custom Prompt', 'primary', 'submit', false);

        // Reset button (separate form)
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0;" onsubmit="return confirm(\'Reset to built-in prompt? Your custom prompt will be cleared.\');">';
        wp_nonce_field('psai_reset_prompt');
        echo '<input type="hidden" name="action" value="psai_reset_prompt" />';
        submit_button('Reset to Built-in', 'secondary', 'submit', false);
        echo '</form>';

        // View built-in button
        echo '<a href="#" id="psai-view-builtin" class="button" style="text-decoration:none;">View Built-in Prompt</a>';
        echo '</div>';

        echo '</form>';

        // Modal for viewing built-in prompt
        echo '<div id="psai-builtin-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:100000;align-items:center;justify-content:center;">';
        echo '<div style="background:#fff;border-radius:8px;padding:24px;max-width:90%;max-height:90%;overflow:auto;position:relative;">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;border-bottom:1px solid #ddd;padding-bottom:12px;">';
        echo '<h2 style="margin:0;">Built-in Prompt (v' . esc_html($builtin_version) . ')</h2>';
        echo '<button id="psai-close-modal" style="background:none;border:none;font-size:24px;cursor:pointer;padding:0;line-height:1;">&times;</button>';
        echo '</div>';
        echo '<pre style="background:#f6f8fa;padding:16px;border-radius:4px;overflow:auto;margin:0;font-family:monospace;font-size:13px;line-height:1.5;white-space:pre-wrap;">';
        echo esc_html(Prompt::TEXT);
        echo '</pre>';
        echo '</div>';
        echo '</div>';

        // JavaScript for modal
        echo '<script>
        (function($) {
            $("#psai-view-builtin").on("click", function(e) {
                e.preventDefault();
                $("#psai-builtin-modal").css("display", "flex");
            });
            $("#psai-close-modal, #psai-builtin-modal").on("click", function(e) {
                if (e.target === this) {
                    $("#psai-builtin-modal").hide();
                }
            });
        })(jQuery);
        </script>';

        echo '</div>';
    }
}
