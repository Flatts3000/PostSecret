<?php
/**
 * Content advisory/NSFW interstitial
 *
 * @package PostSecret
 */
?>

<main id="primary" class="ps-advisory-content">
    <article class="ps-message ps-message--advisory">
        <div class="ps-message__icon" aria-hidden="true">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <h1 class="ps-message__title"><?php esc_html_e('Content Advisory', 'postsecret'); ?></h1>
        <p class="ps-message__text">
            <?php esc_html_e('This secret contains content that may not be suitable for all audiences.', 'postsecret'); ?>
        </p>
        <div class="ps-message__actions">
            <form method="post" action="">
                <?php wp_nonce_field('ps_view_advisory', 'ps_advisory_nonce'); ?>
                <input type="hidden" name="ps_confirm_view" value="1" />
                <button type="submit" class="ps-button ps-button--primary">
                    <?php esc_html_e('I Understand, Show Content', 'postsecret'); ?>
                </button>
            </form>
            <a href="<?php echo esc_url(home_url('/')); ?>" class="ps-button ps-button--secondary">
                <?php esc_html_e('Go Back', 'postsecret'); ?>
            </a>
        </div>
    </article>
</main>
