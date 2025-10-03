<?php
/**
 * Gated content template (not published/vetted)
 *
 * @package PostSecret
 */
?>

<main id="primary" class="ps-gated-content">
    <article class="ps-message ps-message--gated">
        <div class="ps-message__icon" aria-hidden="true">
            <i class="fa-solid fa-lock"></i>
        </div>
        <h1 class="ps-message__title"><?php esc_html_e('Secret Not Available', 'postsecret'); ?></h1>
        <p class="ps-message__text">
            <?php esc_html_e('This secret is not currently available for public viewing.', 'postsecret'); ?>
        </p>
        <a href="<?php echo esc_url(home_url('/')); ?>" class="ps-button">
            <?php esc_html_e('Browse Secrets', 'postsecret'); ?>
        </a>
    </article>
</main>
