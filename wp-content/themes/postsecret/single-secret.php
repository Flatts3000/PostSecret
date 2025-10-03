<?php
/**
 * Single Secret Detail Page
 *
 * Rock-solid, modern detail page hitting performance, a11y, safety goals.
 * Server-rendered with policy gates, responsive images, semantic HTML.
 *
 * @package PostSecret
 */

namespace PostSecret\Theme;

// Security: Only render public-safe content for attachments with _ps_side='front'
if (!is_attachment()) {
    wp_redirect(home_url('/'));
    exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<?php block_template_part('header'); ?>

<?php

while (have_posts()) :
    the_post();

    $post_id = get_the_ID();
    $side = get_post_meta($post_id, '_ps_side', true);

    // Only process front-side secrets
    if ($side !== 'front') {
        wp_redirect(home_url('/'));
        exit;
    }

    // Safety gates: Check review status and policy flags
    $review_status = get_post_meta($post_id, '_ps_review_status', true);
    $nsfw_score = (float) get_post_meta($post_id, '_ps_nsfw_score', true);
    $contains_pii = get_post_meta($post_id, '_ps_contains_pii', true);

    // Gate 1: Must be published and vetted
    if (get_post_status() !== 'publish' || $review_status !== 'auto_vetted') {
        get_template_part('template-parts/content', 'gated');
        block_template_part('footer');
        wp_footer();
        echo '</body></html>';
        exit;
    }

    // Gate 2: NSFW threshold (configurable via settings, default 0.4)
    $nsfw_threshold = apply_filters('ps_nsfw_threshold', 0.4);
    if ($nsfw_score > $nsfw_threshold) {
        get_template_part('template-parts/content', 'advisory');
        block_template_part('footer');
        wp_footer();
        echo '</body></html>';
        exit;
    }

    // Fetch all metadata (server-side only, never exposed in client JS)
    $front_id = $post_id;
    $back_id = (int) get_post_meta($post_id, '_ps_pair_id', true);
    $side = get_post_meta($post_id, '_ps_side', true);

    // If this is the back, redirect to front as canonical
    if ($side === 'back' && $back_id) {
        wp_redirect(get_permalink($back_id), 301);
        exit;
    }

    // Facets
    $topics = (array) get_post_meta($front_id, '_ps_topics', true);
    $feelings = (array) get_post_meta($front_id, '_ps_feelings', true);
    $meanings = (array) get_post_meta($front_id, '_ps_meanings', true);
    $teaches_wisdom = get_post_meta($front_id, '_ps_teaches_wisdom', true) === '1';

    // Descriptors
    $orientation = get_post_meta($front_id, '_ps_orientation', true) ?: 'unknown';
    $primary_color = get_post_meta($front_id, '_ps_primary_color', true);
    $art_style = get_post_meta($front_id, '_ps_art_style', true);
    $font_style = get_post_meta($front_id, '_ps_font_style', true);
    $media_type = get_post_meta($front_id, '_ps_media_type', true);

    // Approved text (PII-safe)
    $approved_text = get_the_content();
    $language = get_post_meta($front_id, '_ps_language', true) ?: 'en';

    // Image data
    $image_url = wp_get_attachment_image_url($front_id, 'full');
    $image_meta = wp_get_attachment_metadata($front_id);
    $image_alt = get_post_meta($front_id, '_wp_attachment_image_alt', true);
    $image_width = $image_meta['width'] ?? 800;
    $image_height = $image_meta['height'] ?? 600;

    // Back image if exists
    $has_back = false;
    $back_url = '';
    $back_alt = '';
    if ($back_id && get_post_type($back_id) === 'attachment') {
        $has_back = true;
        $back_url = wp_get_attachment_image_url($back_id, 'full');
        $back_alt = get_post_meta($back_id, '_wp_attachment_image_alt', true) ?: __('Secret postcard (back)', 'postsecret');
    }

    // Metadata (public-safe only)
    $post_date = get_the_date('c');
    $date_display = get_the_date();
    $canonical_url = get_permalink();

    // Cache headers (set via filter, processed by WordPress/CDN)
    header('Cache-Control: public, max-age=300, s-maxage=600'); // 5min browser, 10min CDN

    ?>

    <!-- Main wrapper with constrained layout matching front page -->
    <main id="primary" class="ps-secret-detail wp-block-group" role="main" style="margin-top:0;margin-bottom:0;padding-top:0;padding-right:clamp(1rem, 3vw, 2rem);padding-bottom:0;padding-left:clamp(1rem, 3vw, 2rem)">
        <article id="secret-<?php echo esc_attr($post_id); ?>" <?php post_class('ps-secret'); ?> itemscope itemtype="https://schema.org/CreativeWork">

            <!-- Skip link for a11y -->
            <a href="#ps-secret-content" class="screen-reader-text skip-link"><?php esc_html_e('Skip to secret content', 'postsecret'); ?></a>

            <!-- Header: Title (hidden visually but present for SEO/a11y) -->
            <header class="ps-secret__header">
                <h1 class="ps-secret__title screen-reader-text" itemprop="name"><?php the_title(); ?></h1>
            </header>

            <!-- Main image with lightbox/zoom -->
            <div class="ps-secret__media" data-orientation="<?php echo esc_attr($orientation); ?>">
                <figure class="ps-secret__figure">
                    <button
                        type="button"
                        class="ps-secret__zoom-trigger"
                        data-image-src="<?php echo esc_url($image_url); ?>"
                        data-image-alt="<?php echo esc_attr($image_alt); ?>"
                        aria-label="<?php esc_attr_e('Zoom image', 'postsecret'); ?>"
                        aria-haspopup="dialog"
                    >
                        <?php
                        echo wp_get_attachment_image(
                            $front_id,
                            'full',
                            false,
                            [
                                'class' => 'ps-secret__img',
                                'alt' => $image_alt,
                                'loading' => 'eager', // Above fold
                                'decoding' => 'async',
                                'itemprop' => 'image',
                                'width' => $image_width,
                                'height' => $image_height,
                                // Responsive srcset generated automatically by WP
                            ]
                        );
                        ?>
                        <span class="ps-secret__zoom-hint" aria-hidden="true">
                            <i class="fa-solid fa-magnifying-glass-plus"></i>
                            <?php esc_html_e('Click to zoom', 'postsecret'); ?>
                        </span>
                    </button>

                    <?php if ($has_back) : ?>
                        <button
                            type="button"
                            class="ps-secret__flip-btn"
                            data-back-src="<?php echo esc_url($back_url); ?>"
                            data-back-alt="<?php echo esc_attr($back_alt); ?>"
                            aria-label="<?php esc_attr_e('Show back of postcard', 'postsecret'); ?>"
                        >
                            <i class="fa-solid fa-rotate" aria-hidden="true"></i>
                        </button>
                    <?php endif; ?>
                </figure>
            </div>

            <!-- Content section -->
            <div id="ps-secret-content" class="ps-secret__content">

                <!-- Approved text -->
                <?php if (!empty($approved_text)) : ?>
                    <section class="ps-secret__text" itemprop="description">
                        <h2 class="ps-secret__section-title"><?php esc_html_e('Text', 'postsecret'); ?></h2>
                        <div class="ps-secret__text-content" lang="<?php echo esc_attr($language); ?>">
                            <?php echo wp_kses_post(wpautop($approved_text)); ?>
                        </div>
                        <?php if ($language !== 'en') : ?>
                            <p class="ps-secret__language-label">
                                <i class="fa-solid fa-language" aria-hidden="true"></i>
                                <?php
                                printf(
                                    /* translators: %s: language name */
                                    esc_html__('Language: %s', 'postsecret'),
                                    '<span lang="' . esc_attr($language) . '">' . esc_html(strtoupper($language)) . '</span>'
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <!-- Facets -->
                <?php if (!empty($topics) || !empty($feelings) || !empty($meanings) || $teaches_wisdom) : ?>
                    <section class="ps-secret__facets">
                        <h2 class="ps-secret__section-title"><?php esc_html_e('Themes', 'postsecret'); ?></h2>

                        <?php if (!empty($topics)) : ?>
                            <div class="ps-facet-group ps-facet-group--topics">
                                <h3 class="ps-facet-group__title"><?php esc_html_e('Topics', 'postsecret'); ?></h3>
                                <ul class="ps-facet-list" role="list">
                                    <?php foreach ($topics as $topic) : ?>
                                        <li class="ps-facet ps-facet--topic"><?php echo esc_html($topic); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($feelings)) : ?>
                            <div class="ps-facet-group ps-facet-group--feelings">
                                <h3 class="ps-facet-group__title"><?php esc_html_e('Feelings', 'postsecret'); ?></h3>
                                <ul class="ps-facet-list" role="list">
                                    <?php foreach ($feelings as $feeling) : ?>
                                        <li class="ps-facet ps-facet--feeling"><?php echo esc_html($feeling); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($meanings)) : ?>
                            <div class="ps-facet-group ps-facet-group--meanings">
                                <h3 class="ps-facet-group__title"><?php esc_html_e('Meanings', 'postsecret'); ?></h3>
                                <ul class="ps-facet-list" role="list">
                                    <?php foreach ($meanings as $meaning) : ?>
                                        <li class="ps-facet ps-facet--meaning"><?php echo esc_html($meaning); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if ($teaches_wisdom) : ?>
                            <div class="ps-wisdom-badge">
                                <i class="fa-solid fa-lightbulb" aria-hidden="true"></i>
                                <?php esc_html_e('Teaches Wisdom', 'postsecret'); ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <!-- Descriptors (art/font/media/orientation) -->
                <?php if ($art_style || $font_style || $media_type || $orientation) : ?>
                    <section class="ps-secret__descriptors">
                        <h2 class="ps-secret__section-title"><?php esc_html_e('Style', 'postsecret'); ?></h2>
                        <dl class="ps-descriptor-list">
                            <?php if ($orientation && $orientation !== 'unknown') : ?>
                                <div class="ps-descriptor">
                                    <dt><?php esc_html_e('Orientation', 'postsecret'); ?></dt>
                                    <dd><?php echo esc_html(ucfirst($orientation)); ?></dd>
                                </div>
                            <?php endif; ?>

                            <?php if ($art_style) : ?>
                                <div class="ps-descriptor">
                                    <dt><?php esc_html_e('Art Style', 'postsecret'); ?></dt>
                                    <dd><?php echo esc_html($art_style); ?></dd>
                                </div>
                            <?php endif; ?>

                            <?php if ($font_style) : ?>
                                <div class="ps-descriptor">
                                    <dt><?php esc_html_e('Font Style', 'postsecret'); ?></dt>
                                    <dd><?php echo esc_html($font_style); ?></dd>
                                </div>
                            <?php endif; ?>

                            <?php if ($media_type) : ?>
                                <div class="ps-descriptor">
                                    <dt><?php esc_html_e('Media Type', 'postsecret'); ?></dt>
                                    <dd><?php echo esc_html($media_type); ?></dd>
                                </div>
                            <?php endif; ?>

                            <?php if ($primary_color) : ?>
                                <div class="ps-descriptor">
                                    <dt><?php esc_html_e('Primary Color', 'postsecret'); ?></dt>
                                    <dd>
                                        <span class="ps-color-swatch" style="background-color: <?php echo esc_attr($primary_color); ?>" aria-hidden="true"></span>
                                        <?php echo esc_html($primary_color); ?>
                                    </dd>
                                </div>
                            <?php endif; ?>
                        </dl>
                    </section>
                <?php endif; ?>

                <!-- Metadata (public-safe) -->
                <section class="ps-secret__meta">
                    <h2 class="ps-secret__section-title screen-reader-text"><?php esc_html_e('Metadata', 'postsecret'); ?></h2>
                    <dl class="ps-meta-list">
                        <div class="ps-meta-item">
                            <dt><?php esc_html_e('Published', 'postsecret'); ?></dt>
                            <dd>
                                <time datetime="<?php echo esc_attr($post_date); ?>" itemprop="datePublished">
                                    <?php echo esc_html($date_display); ?>
                                </time>
                            </dd>
                        </div>
                        <div class="ps-meta-item">
                            <dt><?php esc_html_e('Link', 'postsecret'); ?></dt>
                            <dd>
                                <button
                                    type="button"
                                    class="ps-copy-link"
                                    data-url="<?php echo esc_url($canonical_url); ?>"
                                    aria-label="<?php esc_attr_e('Copy link to clipboard', 'postsecret'); ?>"
                                >
                                    <i class="fa-solid fa-link" aria-hidden="true"></i>
                                    <?php esc_html_e('Copy Link', 'postsecret'); ?>
                                </button>
                                <span class="ps-copy-feedback" role="status" aria-live="polite"></span>
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Similar Secrets (feature-flagged, lazy-loaded) -->
                <?php if (apply_filters('ps_enable_similar_secrets', false)) : ?>
                    <section class="ps-similar-secrets" data-secret-id="<?php echo esc_attr($post_id); ?>">
                        <h2 class="ps-secret__section-title"><?php esc_html_e('Similar Secrets', 'postsecret'); ?></h2>
                        <div class="ps-similar-secrets__container" data-loading="true">
                            <p class="ps-loading-indicator">
                                <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
                                <?php esc_html_e('Loading similar secrets...', 'postsecret'); ?>
                            </p>
                        </div>
                    </section>
                <?php endif; ?>

            </div>

            <!-- Footer: Back to browse -->
            <footer class="ps-secret__footer">
                <a href="<?php echo esc_url(home_url('/')); ?>" class="ps-back-link">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                    <?php esc_html_e('Back to Secrets', 'postsecret'); ?>
                </a>
            </footer>

        </article>
    </main>

    <!-- Lightbox (accessible dialog) -->
    <div id="ps-lightbox" class="ps-lightbox" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Image viewer', 'postsecret'); ?>" hidden>
        <div class="ps-lightbox__backdrop"></div>
        <div class="ps-lightbox__content">
            <button type="button" class="ps-lightbox__close" aria-label="<?php esc_attr_e('Close image viewer', 'postsecret'); ?>">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
            <img src="" alt="" class="ps-lightbox__img" />
        </div>
    </div>

    <?php
endwhile;
?>

<?php block_template_part('footer'); ?>

<?php wp_footer(); ?>
</body>
</html>
