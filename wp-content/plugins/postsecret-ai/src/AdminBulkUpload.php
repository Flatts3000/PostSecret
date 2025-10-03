<?php
/**
 * PSAI\AdminBulkUpload
 * -----------------------------------------------------------------------------
 * Bulk upload admin page: manual ZIP/image ingest with Start/Pause/Stop controls.
 *
 * Page structure:
 * 1. Header (title + explainer)
 * 2. Upload box (dropzone + file picker)
 * 3. Job list (recent jobs table)
 * 4. Job detail pane (when job opened)
 * 5. Live updates via polling
 *
 * @package PSAI
 */

declare(strict_types=1);

namespace PSAI;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminBulkUpload
{
    /**
     * Render the bulk upload admin page.
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'postsecret-ai'));
        }

        $plugin_version = defined('PSAI_VERSION') ? PSAI_VERSION : '0.0.5';
        $prompt_version = Prompt::VERSION ?? 'unknown';
        $env = get_option(Settings::OPTION, Settings::defaults());
        $model = $env['MODEL_NAME'] ?? 'gpt-4o-mini';

        ?>
        <div class="wrap psai-bulk-upload">
            <!-- Header -->
            <h1><?php esc_html_e('PostSecret Bulk Ingest', 'postsecret-ai'); ?></h1>
            <p class="description">
                <?php esc_html_e('Upload a ZIP or images; then manually Start/Pause/Stop processing.', 'postsecret-ai'); ?>
            </p>

            <!-- Upload Box -->
            <div class="psai-upload-box card">
                <h2><?php esc_html_e('Create New Job', 'postsecret-ai'); ?></h2>

                <form id="psai-bulk-upload-form" method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('psai_bulk_create_job', 'psai_bulk_nonce'); ?>

                    <div class="psai-dropzone" id="psai-dropzone">
                        <div class="psai-dropzone-content">
                            <p class="psai-dropzone-icon">📦</p>
                            <p class="psai-dropzone-text">
                                <?php esc_html_e('Drop ZIP or images here, or click to browse', 'postsecret-ai'); ?>
                            </p>
                            <input
                                type="file"
                                name="psai_bulk_files[]"
                                id="psai_bulk_files"
                                accept=".zip,.jpg,.jpeg,.png,.webp"
                                multiple
                                style="display: none;"
                            />
                        </div>
                        <div id="psai-file-list" class="psai-file-list" style="display: none;"></div>
                    </div>

                    <p class="description">
                        <?php esc_html_e('Supported formats: ZIP archives, JPEG, PNG, WebP. Max file size: 128MB per file.', 'postsecret-ai'); ?>
                    </p>

                    <button type="submit" class="button button-primary" id="psai-create-job-btn" disabled>
                        <?php esc_html_e('Create Job', 'postsecret-ai'); ?>
                    </button>
                    <span class="spinner" id="psai-create-spinner" style="float: none; margin-left: 8px;"></span>
                </form>
            </div>

            <!-- Job List -->
            <div class="psai-job-list card">
                <h2><?php esc_html_e('Recent Jobs', 'postsecret-ai'); ?></h2>
                <div id="psai-jobs-container">
                    <table class="wp-list-table widefat fixed striped" id="psai-jobs-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Job ID', 'postsecret-ai'); ?></th>
                                <th><?php esc_html_e('Created', 'postsecret-ai'); ?></th>
                                <th><?php esc_html_e('Source', 'postsecret-ai'); ?></th>
                                <th><?php esc_html_e('Total Files', 'postsecret-ai'); ?></th>
                                <th><?php esc_html_e('Status', 'postsecret-ai'); ?></th>
                                <th><?php esc_html_e('Progress', 'postsecret-ai'); ?></th>
                                <th><?php esc_html_e('Success', 'postsecret-ai'); ?></th>
                                <th><?php esc_html_e('Failed', 'postsecret-ai'); ?></th>
                                <th><?php esc_html_e('Last Error', 'postsecret-ai'); ?></th>
                                <th><?php esc_html_e('Actions', 'postsecret-ai'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="psai-jobs-tbody">
                            <!-- Populated via AJAX -->
                        </tbody>
                    </table>
                    <div id="psai-jobs-empty" class="psai-empty-state" style="display: none;">
                        <p><?php esc_html_e('No jobs yet—upload a ZIP to create one.', 'postsecret-ai'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Job Detail Pane -->
            <div class="psai-job-detail card" id="psai-job-detail" style="display: none;">
                <div class="psai-job-detail-header">
                    <h2 id="psai-detail-title"><?php esc_html_e('Job Detail', 'postsecret-ai'); ?></h2>
                    <button type="button" class="button" id="psai-close-detail">
                        <?php esc_html_e('Close', 'postsecret-ai'); ?>
                    </button>
                </div>

                <!-- Summary Bar -->
                <div class="psai-job-summary">
                    <div class="psai-summary-item">
                        <span class="psai-label"><?php esc_html_e('Status:', 'postsecret-ai'); ?></span>
                        <span class="psai-status-pill" id="psai-detail-status">—</span>
                    </div>
                    <div class="psai-summary-item">
                        <span class="psai-label"><?php esc_html_e('Progress:', 'postsecret-ai'); ?></span>
                        <span id="psai-detail-progress-text">0 / 0 (0%)</span>
                    </div>
                    <div class="psai-summary-item">
                        <span class="psai-label"><?php esc_html_e('Succeeded:', 'postsecret-ai'); ?></span>
                        <span id="psai-detail-success">0</span>
                    </div>
                    <div class="psai-summary-item">
                        <span class="psai-label"><?php esc_html_e('Failed:', 'postsecret-ai'); ?></span>
                        <span id="psai-detail-failed">0</span>
                    </div>
                    <div class="psai-summary-item">
                        <span class="psai-label"><?php esc_html_e('Throughput:', 'postsecret-ai'); ?></span>
                        <span id="psai-detail-throughput">—</span>
                    </div>
                </div>

                <!-- Progress Bar -->
                <div class="psai-progress-bar-container">
                    <div class="psai-progress-bar">
                        <div class="psai-progress-fill" id="psai-detail-progress-bar" style="width: 0%;"></div>
                    </div>
                    <p class="psai-progress-remaining">
                        <?php esc_html_e('Remaining:', 'postsecret-ai'); ?>
                        <span id="psai-detail-remaining">0</span>
                    </p>
                </div>

                <!-- Controls -->
                <div class="psai-job-controls">
                    <button type="button" class="button button-primary" id="psai-start-btn" data-job-id="">
                        <?php esc_html_e('Start', 'postsecret-ai'); ?>
                    </button>
                    <button type="button" class="button" id="psai-pause-btn" data-job-id="">
                        <?php esc_html_e('Pause', 'postsecret-ai'); ?>
                    </button>
                    <button type="button" class="button" id="psai-stop-btn" data-job-id="">
                        <?php esc_html_e('Stop', 'postsecret-ai'); ?>
                    </button>
                    <button type="button" class="button" id="psai-retry-failed-btn" data-job-id="">
                        <?php esc_html_e('Retry Failed', 'postsecret-ai'); ?>
                    </button>
                    <button type="button" class="button button-link-delete" id="psai-delete-job-btn" data-job-id="">
                        <?php esc_html_e('Delete Job', 'postsecret-ai'); ?>
                    </button>
                    <span class="spinner" id="psai-control-spinner" style="float: none; margin-left: 8px;"></span>
                </div>

                <!-- Job Settings -->
                <div class="psai-job-settings">
                    <h3><?php esc_html_e('Job Settings', 'postsecret-ai'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="psai-batch-size"><?php esc_html_e('Batch Size', 'postsecret-ai'); ?></label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    id="psai-batch-size"
                                    name="batch_size"
                                    value="25"
                                    min="1"
                                    max="100"
                                    class="small-text"
                                />
                                <p class="description">
                                    <?php esc_html_e('Number of items to process per step (default: 25).', 'postsecret-ai'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="psai-max-step-time"><?php esc_html_e('Max Step Time (seconds)', 'postsecret-ai'); ?></label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    id="psai-max-step-time"
                                    name="max_step_time"
                                    value="8"
                                    min="3"
                                    max="30"
                                    class="small-text"
                                />
                                <p class="description">
                                    <?php esc_html_e('Maximum time per step to keep pages responsive (default: 8s).', 'postsecret-ai'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <button type="button" class="button" id="psai-save-settings-btn">
                        <?php esc_html_e('Save Settings', 'postsecret-ai'); ?>
                    </button>
                </div>

                <!-- Recent Errors Panel -->
                <div class="psai-errors-panel">
                    <h3><?php esc_html_e('Recent Errors', 'postsecret-ai'); ?></h3>
                    <div class="psai-errors-container" id="psai-errors-container">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Item ID', 'postsecret-ai'); ?></th>
                                    <th><?php esc_html_e('File Path', 'postsecret-ai'); ?></th>
                                    <th><?php esc_html_e('Status', 'postsecret-ai'); ?></th>
                                    <th><?php esc_html_e('Attempts', 'postsecret-ai'); ?></th>
                                    <th><?php esc_html_e('Last Error', 'postsecret-ai'); ?></th>
                                    <th><?php esc_html_e('Last Updated', 'postsecret-ai'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="psai-errors-tbody">
                                <!-- Populated via AJAX -->
                            </tbody>
                        </table>
                        <div id="psai-errors-empty" class="psai-empty-state" style="display: none;">
                            <p><?php esc_html_e('No errors yet.', 'postsecret-ai'); ?></p>
                        </div>
                    </div>
                    <button type="button" class="button" id="psai-export-errors-btn">
                        <?php esc_html_e('Export Errors CSV', 'postsecret-ai'); ?>
                    </button>
                </div>

                <!-- File Handling Notes -->
                <div class="psai-file-notes">
                    <h3><?php esc_html_e('File Handling', 'postsecret-ai'); ?></h3>
                    <dl>
                        <dt><?php esc_html_e('Staging Folder:', 'postsecret-ai'); ?></dt>
                        <dd id="psai-staging-path">—</dd>

                        <dt><?php esc_html_e('Duplicate Handling:', 'postsecret-ai'); ?></dt>
                        <dd><?php esc_html_e('Files are deduped by SHA-256; already-seen images are skipped.', 'postsecret-ai'); ?></dd>

                        <dt><?php esc_html_e('Quarantine Rule:', 'postsecret-ai'); ?></dt>
                        <dd><?php esc_html_e('Items with repeated failures (3+ attempts) are quarantined.', 'postsecret-ai'); ?></dd>
                    </dl>
                </div>
            </div>

            <!-- Footer -->
            <div class="psai-footer">
                <p>
                    <a href="https://docs.postsecret.com/bulk-upload" target="_blank">
                        <?php esc_html_e('Documentation & Help', 'postsecret-ai'); ?>
                    </a>
                </p>
                <p class="description">
                    <?php
                    printf(
                        /* translators: 1: plugin version, 2: prompt version, 3: model name */
                        esc_html__('PostSecret AI v%1$s | Prompt v%2$s | Model: %3$s', 'postsecret-ai'),
                        esc_html($plugin_version),
                        esc_html($prompt_version),
                        esc_html($model)
                    );
                    ?>
                </p>
            </div>

            <!-- Live region for status updates (accessibility) -->
            <div class="screen-reader-text" role="status" aria-live="polite" id="psai-live-status"></div>
        </div>

        <style>
            .psai-bulk-upload { max-width: 1400px; }
            .psai-bulk-upload .card { padding: 20px; margin: 20px 0; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); }

            /* Upload Box */
            .psai-dropzone { border: 2px dashed #ccd0d4; border-radius: 4px; padding: 40px; text-align: center; background: #f9f9f9; cursor: pointer; transition: all 0.3s; }
            .psai-dropzone:hover { border-color: #2271b1; background: #f0f6fc; }
            .psai-dropzone.dragover { border-color: #2271b1; background: #e5f2ff; }
            .psai-dropzone-icon { font-size: 48px; margin: 0; }
            .psai-dropzone-text { margin: 10px 0 0; color: #646970; }
            .psai-file-list { margin-top: 20px; text-align: left; padding: 10px; background: #fff; border-radius: 4px; }
            .psai-file-item { padding: 8px; border-bottom: 1px solid #f0f0f1; }

            /* Job List */
            .psai-empty-state { text-align: center; padding: 40px; color: #646970; }
            .psai-status-pill { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
            .psai-status-pill.new { background: #f0f0f1; color: #1d2327; }
            .psai-status-pill.running { background: #d5e8f7; color: #0c5289; }
            .psai-status-pill.paused { background: #fcf3cf; color: #8a6d3b; }
            .psai-status-pill.completed { background: #d4edda; color: #155724; }
            .psai-status-pill.failed { background: #f8d7da; color: #721c24; }
            .psai-status-pill.stopped { background: #f0f0f1; color: #646970; }

            /* Job Detail */
            .psai-job-detail { margin-top: 20px; }
            .psai-job-detail-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
            .psai-job-summary { display: flex; gap: 24px; margin-bottom: 20px; flex-wrap: wrap; }
            .psai-summary-item { display: flex; gap: 8px; }
            .psai-label { font-weight: 600; color: #1d2327; }

            /* Progress Bar */
            .psai-progress-bar-container { margin: 20px 0; }
            .psai-progress-bar { height: 24px; background: #f0f0f1; border-radius: 4px; overflow: hidden; margin-bottom: 8px; }
            .psai-progress-fill { height: 100%; background: #2271b1; transition: width 0.3s ease; }
            .psai-progress-remaining { margin: 0; font-size: 13px; color: #646970; }

            /* Controls */
            .psai-job-controls { margin: 20px 0; display: flex; gap: 8px; flex-wrap: wrap; }

            /* Settings */
            .psai-job-settings { margin: 30px 0; padding-top: 20px; border-top: 1px solid #f0f0f1; }
            .psai-job-settings h3 { margin-top: 0; }

            /* Errors Panel */
            .psai-errors-panel { margin: 30px 0; padding-top: 20px; border-top: 1px solid #f0f0f1; }
            .psai-errors-panel h3 { margin-top: 0; }
            .psai-errors-container { max-height: 400px; overflow-y: auto; margin-bottom: 12px; }

            /* File Notes */
            .psai-file-notes { margin: 30px 0; padding-top: 20px; border-top: 1px solid #f0f0f1; }
            .psai-file-notes h3 { margin-top: 0; }
            .psai-file-notes dl { display: grid; grid-template-columns: 200px 1fr; gap: 12px; }
            .psai-file-notes dt { font-weight: 600; }
            .psai-file-notes dd { margin: 0; color: #646970; }

            /* Footer */
            .psai-footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #f0f0f1; text-align: center; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // State
            let currentJobId = null;
            let isProcessing = false;
            let pollInterval = null;

            // Upload box interactions
            const $dropzone = $('#psai-dropzone');
            const $fileInput = $('#psai_bulk_files');
            const $fileList = $('#psai-file-list');
            const $createBtn = $('#psai-create-job-btn');

            $dropzone.on('click', () => $fileInput.trigger('click'));

            $dropzone.on('dragover dragenter', (e) => {
                e.preventDefault();
                e.stopPropagation();
                $dropzone.addClass('dragover');
            });

            $dropzone.on('dragleave dragend drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                $dropzone.removeClass('dragover');
            });

            $dropzone.on('drop', (e) => {
                const files = e.originalEvent.dataTransfer.files;
                $fileInput[0].files = files;
                updateFileList(files);
            });

            $fileInput.on('change', function() {
                updateFileList(this.files);
            });

            function updateFileList(files) {
                if (!files || files.length === 0) {
                    $fileList.hide().empty();
                    $createBtn.prop('disabled', true);
                    return;
                }

                $fileList.empty().show();
                $createBtn.prop('disabled', false);

                Array.from(files).forEach(file => {
                    $fileList.append(`<div class="psai-file-item">📄 ${file.name} (${formatBytes(file.size)})</div>`);
                });
            }

            function formatBytes(bytes) {
                if (bytes === 0) return '0 Bytes';
                const k = 1024;
                const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
            }

            // Load jobs on page load
            loadJobs();

            // Refresh jobs every 5s
            setInterval(loadJobs, 5000);

            function loadJobs() {
                $.get(ajaxurl, { action: 'psai_bulk_list_jobs' }, function(response) {
                    if (response.success && response.data.jobs) {
                        renderJobs(response.data.jobs);
                    }
                });
            }

            function renderJobs(jobs) {
                const $tbody = $('#psai-jobs-tbody');
                const $empty = $('#psai-jobs-empty');

                if (!jobs || jobs.length === 0) {
                    $tbody.parent().hide();
                    $empty.show();
                    return;
                }

                $tbody.parent().show();
                $empty.hide();
                $tbody.empty();

                jobs.forEach(job => {
                    const progress = job.total > 0 ? Math.round((job.processed / job.total) * 100) : 0;
                    const $row = $(`
                        <tr>
                            <td><code>${job.uuid.substring(0, 8)}</code></td>
                            <td>${job.created}</td>
                            <td>${job.source}</td>
                            <td>${job.total}</td>
                            <td><span class="psai-status-pill ${job.status}">${job.status}</span></td>
                            <td>${job.processed} / ${job.total} (${progress}%)</td>
                            <td>${job.success_count}</td>
                            <td>${job.fail_count}</td>
                            <td><small>${job.last_error || '—'}</small></td>
                            <td>
                                <button type="button" class="button button-small psai-open-job" data-job-id="${job.id}">
                                    Open
                                </button>
                            </td>
                        </tr>
                    `);
                    $tbody.append($row);
                });

                // Bind open buttons
                $('.psai-open-job').on('click', function() {
                    openJob($(this).data('job-id'));
                });
            }

            function openJob(jobId) {
                currentJobId = jobId;
                $('#psai-job-detail').show();
                loadJobDetail(jobId);
                startPolling();
            }

            $('#psai-close-detail').on('click', function() {
                $('#psai-job-detail').hide();
                currentJobId = null;
                stopPolling();
            });

            function loadJobDetail(jobId) {
                $.get(ajaxurl, { action: 'psai_bulk_get_job', job_id: jobId }, function(response) {
                    if (response.success && response.data) {
                        renderJobDetail(response.data);
                    }
                });
            }

            function renderJobDetail(job) {
                const progress = job.total > 0 ? Math.round((job.processed / job.total) * 100) : 0;
                const remaining = job.total - job.processed;

                $('#psai-detail-title').text(`Job: ${job.uuid.substring(0, 8)}`);
                $('#psai-detail-status').removeClass().addClass('psai-status-pill ' + job.status).text(job.status);
                $('#psai-detail-progress-text').text(`${job.processed} / ${job.total} (${progress}%)`);
                $('#psai-detail-success').text(job.success_count);
                $('#psai-detail-failed').text(job.fail_count);
                $('#psai-detail-remaining').text(remaining);
                $('#psai-detail-progress-bar').css('width', progress + '%');
                $('#psai-staging-path').text(job.staging_path || '—');

                // Update controls
                const $start = $('#psai-start-btn');
                const $pause = $('#psai-pause-btn');
                const $stop = $('#psai-stop-btn');
                const $retry = $('#psai-retry-failed-btn');
                const $delete = $('#psai-delete-job-btn');

                $start.data('job-id', job.id);
                $pause.data('job-id', job.id);
                $stop.data('job-id', job.id);
                $retry.data('job-id', job.id);
                $delete.data('job-id', job.id);

                // Enable/disable based on status
                $start.prop('disabled', job.status === 'running' || job.status === 'completed');
                $pause.prop('disabled', job.status !== 'running');
                $stop.prop('disabled', job.status === 'stopped' || job.status === 'completed');
                $delete.prop('disabled', job.status === 'running');

                // Load errors
                loadErrors(job.id);

                // Calculate throughput (if running)
                if (job.status === 'running' && job.started_at) {
                    const elapsed = (Date.now() - new Date(job.started_at).getTime()) / 1000 / 60;
                    const throughput = elapsed > 0 ? Math.round(job.processed / elapsed) : 0;
                    $('#psai-detail-throughput').text(throughput + ' items/min');
                } else {
                    $('#psai-detail-throughput').text('—');
                }
            }

            function loadErrors(jobId) {
                $.get(ajaxurl, { action: 'psai_bulk_get_errors', job_id: jobId }, function(response) {
                    if (response.success && response.data.errors) {
                        renderErrors(response.data.errors);
                    }
                });
            }

            function renderErrors(errors) {
                const $tbody = $('#psai-errors-tbody');
                const $empty = $('#psai-errors-empty');

                if (!errors || errors.length === 0) {
                    $tbody.parent().hide();
                    $empty.show();
                    return;
                }

                $tbody.parent().show();
                $empty.hide();
                $tbody.empty();

                errors.forEach(error => {
                    const $row = $(`
                        <tr>
                            <td>${error.id}</td>
                            <td><code>${error.file_path}</code></td>
                            <td>${error.status}</td>
                            <td>${error.attempts}</td>
                            <td><small>${error.last_error || '—'}</small></td>
                            <td>${error.updated_at}</td>
                        </tr>
                    `);
                    $tbody.append($row);
                });
            }

            function startPolling() {
                if (pollInterval) clearInterval(pollInterval);
                pollInterval = setInterval(() => {
                    if (currentJobId) {
                        loadJobDetail(currentJobId);
                    }
                }, 3000);
            }

            function stopPolling() {
                if (pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
            }

            // Control buttons
            $('#psai-start-btn').on('click', function() {
                startJob($(this).data('job-id'));
            });

            $('#psai-pause-btn').on('click', function() {
                pauseJob($(this).data('job-id'));
            });

            $('#psai-stop-btn').on('click', function() {
                stopJob($(this).data('job-id'));
            });

            $('#psai-retry-failed-btn').on('click', function() {
                retryFailed($(this).data('job-id'));
            });

            $('#psai-delete-job-btn').on('click', function() {
                if (confirm('Are you sure you want to delete this job? This cannot be undone.')) {
                    deleteJob($(this).data('job-id'));
                }
            });

            function startJob(jobId) {
                $('#psai-control-spinner').addClass('is-active');
                $.post(ajaxurl, { action: 'psai_bulk_start_job', job_id: jobId }, function(response) {
                    $('#psai-control-spinner').removeClass('is-active');
                    if (response.success) {
                        updateLiveStatus('Job started');
                        loadJobDetail(jobId);
                        processStep(jobId);
                    } else {
                        alert('Error: ' + (response.data || 'Unknown error'));
                    }
                });
            }

            function processStep(jobId) {
                if (!currentJobId || currentJobId !== jobId) return;

                const batchSize = parseInt($('#psai-batch-size').val()) || 25;

                $.post(ajaxurl, {
                    action: 'psai_bulk_step',
                    job_id: jobId,
                    batch_size: batchSize
                }, function(response) {
                    if (response.success) {
                        loadJobDetail(jobId);

                        // Continue if still running
                        if (response.data.status === 'running' && response.data.has_more) {
                            setTimeout(() => processStep(jobId), 500);
                        } else if (response.data.status === 'completed') {
                            updateLiveStatus('Job completed');
                        }
                    } else {
                        updateLiveStatus('Error processing step: ' + (response.data || 'Unknown error'));
                    }
                });
            }

            function pauseJob(jobId) {
                $('#psai-control-spinner').addClass('is-active');
                $.post(ajaxurl, { action: 'psai_bulk_pause_job', job_id: jobId }, function(response) {
                    $('#psai-control-spinner').removeClass('is-active');
                    if (response.success) {
                        updateLiveStatus('Job paused');
                        loadJobDetail(jobId);
                    }
                });
            }

            function stopJob(jobId) {
                $('#psai-control-spinner').addClass('is-active');
                $.post(ajaxurl, { action: 'psai_bulk_stop_job', job_id: jobId }, function(response) {
                    $('#psai-control-spinner').removeClass('is-active');
                    if (response.success) {
                        updateLiveStatus('Job stopped');
                        loadJobDetail(jobId);
                    }
                });
            }

            function retryFailed(jobId) {
                $('#psai-control-spinner').addClass('is-active');
                $.post(ajaxurl, { action: 'psai_bulk_retry_failed', job_id: jobId }, function(response) {
                    $('#psai-control-spinner').removeClass('is-active');
                    if (response.success) {
                        updateLiveStatus('Failed items requeued');
                        loadJobDetail(jobId);
                    }
                });
            }

            function deleteJob(jobId) {
                $('#psai-control-spinner').addClass('is-active');
                $.post(ajaxurl, { action: 'psai_bulk_delete_job', job_id: jobId }, function(response) {
                    $('#psai-control-spinner').removeClass('is-active');
                    if (response.success) {
                        updateLiveStatus('Job deleted');
                        $('#psai-job-detail').hide();
                        currentJobId = null;
                        stopPolling();
                        loadJobs();
                    }
                });
            }

            function updateLiveStatus(message) {
                $('#psai-live-status').text(message);
            }

            // Save settings
            $('#psai-save-settings-btn').on('click', function() {
                if (!currentJobId) return;

                const settings = {
                    batch_size: parseInt($('#psai-batch-size').val()) || 25,
                    max_step_time: parseInt($('#psai-max-step-time').val()) || 8
                };

                $.post(ajaxurl, {
                    action: 'psai_bulk_save_settings',
                    job_id: currentJobId,
                    settings: settings
                }, function(response) {
                    if (response.success) {
                        updateLiveStatus('Settings saved');
                    }
                });
            });

            // Export errors
            $('#psai-export-errors-btn').on('click', function() {
                if (!currentJobId) return;
                window.location.href = ajaxurl + '?action=psai_bulk_export_errors&job_id=' + currentJobId;
            });
        });
        </script>
        <?php
    }
}
