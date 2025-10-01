<?php

namespace PSAI;

if (!defined('ABSPATH')) exit;

/**
 * Admin UI: Single postcard upload (Front required, Back optional).
 * Submits to admin-post.php?action=psai_upload_single
 */
final class AdminSingleUpload
{
    public static function render(): void
    {
        if (!current_user_can('upload_files')) wp_die('Not allowed', 403);

        $notice = isset($_GET['psai_msg']) ? sanitize_text_field($_GET['psai_msg']) : '';

        echo '<div class="wrap"><h1>Upload a Postcard</h1>';
        echo '<p>Front image is required. Back is optional. If provided, both sides will be classified together in one request.</p>';

        if ($notice === 'ok') {
            echo '<div class="notice notice-success is-dismissible"><p>Uploaded. Processing queued.</p></div>';
        } elseif ($notice === 'dupe') {
            echo '<div class="notice notice-warning is-dismissible"><p>Upload complete. One or more files matched existing images (duplicates were not re-classified).</p></div>';
        } elseif ($notice === 'err') {
            echo '<div class="notice notice-error is-dismissible"><p>Upload failed. Check file types/size and try again.</p></div>';
        }

        $action = esc_url(admin_url('admin-post.php'));
        $nonce = wp_create_nonce('psai_upload_single');
        $accept = 'image/jpeg,image/png,image/webp,image/tiff';
        $maxMb = 25; // client-side soft cap; server may allow more
        ?>
        <style>
            .psai-card {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 16px;
                max-width: 940px;
            }

            .psai-grid {
                display: grid;
                grid-template-columns:1fr 1fr;
                gap: 16px;
            }

            .psai-slot {
                border: 2px dashed #c3c4c7;
                border-radius: 8px;
                padding: 16px;
                text-align: center;
                background: #fafafa;
                position: relative;
                min-height: 260px;
            }

            .psai-slot.drag {
                background: #f0f6ff;
                border-color: #2271b1;
            }

            .psai-badge {
                position: absolute;
                top: 8px;
                left: 8px;
                font-size: 12px;
                font-weight: 600;
                background: #e9eff5;
                color: #1d2327;
                padding: 2px 8px;
                border-radius: 999px;
            }

            .psai-slot input[type="file"] {
                margin-top: 36px;
            }

            .psai-preview {
                margin-top: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 150px;
                background: #fff;
                border: 1px solid #eef0f2;
                border-radius: 6px;
                overflow: hidden;
            }

            .psai-preview img {
                max-width: 100%;
                max-height: 240px;
                display: block;
            }

            .psai-meta {
                font-size: 12px;
                color: #50575e;
                margin-top: 6px;
                min-height: 18px;
            }

            .psai-help {
                color: #646970;
                font-size: 12px;
            }

            .psai-actions {
                margin-top: 16px;
                display: flex;
                align-items: center;
                gap: 12px;
            }

            @media (max-width: 1000px) {
                .psai-grid {
                    grid-template-columns:1fr;
                }
            }
        </style>

        <div class="psai-card">
            <form id="psai-upload-form" method="post" action="<?php echo $action; ?>" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="action" value="psai_upload_single">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">

                <div class="psai-grid">
                    <!-- FRONT (required) -->
                    <div class="psai-slot" data-slot="front" tabindex="0" aria-label="Front image dropzone">
                        <span class="psai-badge">Front (required)</span>
                        <input type="file" id="psai_front" name="psai_front" accept="<?php echo esc_attr($accept); ?>" required>
                        <div class="psai-preview" id="psai_front_preview">
                            <span class="psai-help">Drop image here or choose a file</span>
                        </div>
                        <div class="psai-meta" id="psai_front_meta"></div>
                    </div>

                    <!-- BACK (optional) -->
                    <div class="psai-slot" data-slot="back" tabindex="0" aria-label="Back image dropzone">
                        <span class="psai-badge">Back (optional)</span>
                        <input type="file" id="psai_back" name="psai_back" accept="<?php echo esc_attr($accept); ?>">
                        <div class="psai-preview" id="psai_back_preview">
                            <span class="psai-help">Drop image here or choose a file</span>
                        </div>
                        <div class="psai-meta" id="psai_back_meta"></div>
                    </div>
                </div>

                <p class="psai-help" style="margin-top:10px;">Allowed: JPG, PNG, WEBP, TIFF. Max ~<?php echo (int)$maxMb; ?> MB per file. Backs are always classified when provided.</p>

                <div class="psai-actions">
                    <?php submit_button('Upload & Queue Classification', 'primary', 'submit', false); ?>
                    <span class="psai-help" id="psai_status_help">Both sides will be sent together in one classification request.</span>
                </div>
            </form>
        </div>

        <script>
            (function () {
                const MAX_MB = <?php echo (int)$maxMb; ?>;
                const form = document.getElementById('psai-upload-form');
                const frontInput = document.getElementById('psai_front');
                const backInput = document.getElementById('psai_back');

                function isImage(file) {
                    return /^image\/(jpeg|png|webp|tiff?)$/i.test(file.type) || /\.(jpe?g|png|webp|tif?f)$/i.test(file.name);
                }

                function tooBig(file) {
                    return (file.size / (1024 * 1024)) > MAX_MB;
                }

                function renderPreview(file, previewEl, metaEl) {
                    if (!file) {
                        previewEl.innerHTML = '<span class="psai-help">Drop image here or choose a file</span>';
                        metaEl.textContent = '';
                        return;
                    }
                    if (!isImage(file)) {
                        previewEl.innerHTML = '<span class="psai-help" style="color:#b32d2e;">Unsupported file type</span>';
                        metaEl.textContent = '';
                        return;
                    }
                    if (tooBig(file)) {
                        previewEl.innerHTML = '<span class="psai-help" style="color:#b32d2e;">File too large</span>';
                        metaEl.textContent = '';
                        return;
                    }
                    const url = URL.createObjectURL(file);
                    previewEl.innerHTML = '';
                    const img = new Image();
                    img.onload = () => URL.revokeObjectURL(url);
                    img.src = url;
                    previewEl.appendChild(img);
                    metaEl.textContent = file.name + ' • ' + (file.size / 1024 / 1024).toFixed(2) + ' MB';
                }

                function hookDropzone(slot) {
                    const dz = document.querySelector('.psai-slot[data-slot="' + slot + '"]');
                    const input = document.getElementById('psai_' + slot);
                    const preview = document.getElementById('psai_' + slot + '_preview');
                    const meta = document.getElementById('psai_' + slot + '_meta');

                    ['dragenter', 'dragover'].forEach(ev => dz.addEventListener(ev, e => {
                        e.preventDefault();
                        e.stopPropagation();
                        dz.classList.add('drag');
                    }));
                    ['dragleave', 'drop'].forEach(ev => dz.addEventListener(ev, e => {
                        e.preventDefault();
                        e.stopPropagation();
                        dz.classList.remove('drag');
                    }));
                    dz.addEventListener('drop', e => {
                        const file = e.dataTransfer.files && e.dataTransfer.files[0];
                        if (file) {
                            input.files = e.dataTransfer.files;
                            renderPreview(file, preview, meta);
                        }
                    });
                    input.addEventListener('change', () => {
                        const file = input.files && input.files[0];
                        renderPreview(file, preview, meta);
                    });
                }

                hookDropzone('front');
                hookDropzone('back');

                form.addEventListener('submit', function (e) {
                    const f = frontInput.files && frontInput.files[0];
                    if (!f) {
                        e.preventDefault();
                        alert('Front image is required.');
                        frontInput.focus();
                        return;
                    }
                    if (!isImage(f) || tooBig(f)) {
                        e.preventDefault();
                        alert('Front image must be a supported type and under ' + MAX_MB + ' MB.');
                        frontInput.focus();
                        return;
                    }
                    const b = backInput.files && backInput.files[0];
                    if (b && (!isImage(b) || tooBig(b))) {
                        e.preventDefault();
                        alert('Back image must be a supported type and under ' + MAX_MB + ' MB.');
                        backInput.focus();
                        return;
                    }
                });
            })();
        </script>
        <?php
        echo '</div>';
    }
}