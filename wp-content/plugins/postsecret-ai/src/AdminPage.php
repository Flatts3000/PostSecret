<?php

namespace PSAI;

if (!defined('ABSPATH')) exit;

class AdminPage
{
    public static function render()
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

        $err = isset($_GET['psai_err']) ? sanitize_text_field($_GET['psai_err']) : '';
        $done = isset($_GET['psai_done']);

        echo '<div class="wrap"><h1>PostSecret AI</h1>';

        if (isset($_GET['settings-updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }
        settings_errors('postsecret-ai'); // shows any add_settings_error() messages

        // Notices
        if ($err === 'no_key') {
            echo '<div class="notice notice-error"><p>Add your OpenAI API key in Settings below.</p></div>';
        } elseif ($err === 'no_image') {
            echo '<div class="notice notice-error"><p>Provide an image URL.</p></div>';
        } elseif ($err === 'call_failed') {
            $msg = get_transient('psai_last_error');
            if ($msg) echo '<div class="notice notice-error"><p>Classifier error: ' . esc_html($msg) . '</p></div>';
        } elseif ($done) {
            echo '<div class="notice notice-success"><p>Classification complete.</p></div>';
        }

        // Classify form
        ?>
        <h2>Classify a Single Image</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('psai_classify'); ?>
            <input type="hidden" name="action" value="psai_classify"/>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="psai_image_url">Image URL</label></th>
                    <td>
                        <input type="url" name="psai_image_url" id="psai_image_url" placeholder="https://example.com/path/to/image.jpg" style="width:520px" required/>
                        <p class="description">Paste a direct URL to an image in your Media Library (or anywhere reachable).</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Run Classifier'); ?>
        </form>
        <?php

        // Results (if any)
        $last = get_transient('psai_last_result');
        if ($last) {
            echo '<hr /><h2>Last Result</h2>';
            echo '<p><strong>Image:</strong> <a target="_blank" href="' . esc_url($last['image_url']) . '">' . esc_html($last['image_url']) . '</a></p>';
            echo '<p><strong>Model:</strong> ' . esc_html($last['model']) . '</p>';
            if (!empty($last['payload']['approvedText'])) {
                echo '<p><strong>Approved Text:</strong><br /><em style="white-space:pre-wrap">' . esc_html($last['payload']['approvedText']) . '</em></p>';
            }
            if (!empty($last['payload']['tags'])) {
                echo '<p><strong>Tags:</strong> ' . esc_html(implode(', ', $last['payload']['tags'])) . '</p>';
            }
            echo '<details><summary>Raw JSON payload</summary><pre style="background:#111;color:#eee;padding:12px;overflow:auto;">'
                    . esc_html(json_encode($last['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                    . '</pre></details>';
        }

        // Settings stub
        echo '<hr /><h2>Settings</h2>';
        echo '<form method="post" action="options.php">';
        settings_fields(PSAI_SLUG);
        do_settings_sections(PSAI_SLUG);
        submit_button('Save Settings');
        echo '</form>';

        echo '</div>';
    }
}