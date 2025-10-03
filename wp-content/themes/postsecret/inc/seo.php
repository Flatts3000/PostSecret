<?php
/**
 * SEO & Social Meta helpers
 *
 * Handles meta descriptions, OpenGraph, Twitter Cards, and structured data
 * for Secret detail pages. Respects safety gates (no meta for gated content).
 *
 * @package PostSecret
 */

namespace PostSecret\Theme\Inc;

/**
 * Output comprehensive meta tags for Secret detail pages.
 */
function output_secret_meta() {
    // Only run on attachment pages for front-side secrets
    if (!is_attachment()) {
        return;
    }

    $post_id = get_the_ID();
    $side = get_post_meta($post_id, '_ps_side', true);

    if ($side !== 'front') {
        return;
    }

    $review_status = get_post_meta($post_id, '_ps_review_status', true);
    $nsfw_score = (float) get_post_meta($post_id, '_ps_nsfw_score', true);
    $nsfw_threshold = apply_filters('ps_nsfw_threshold', 0.4);

    // Safety: No meta tags for gated/NSFW content (prevents indexing/sharing)
    $is_vetted = get_post_meta($post_id, '_ps_is_vetted', true) === '1';
    if (!$is_vetted || $review_status !== 'auto_vetted' || $nsfw_score > $nsfw_threshold) {
        echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
        return;
    }

    // Get metadata
    $title = get_the_title();
    $canonical_url = get_permalink();
    $excerpt = get_the_excerpt();
    $description = wp_trim_words($excerpt, 30, '...');

    // Image
    $front_id = $post_id;
    $image_url = wp_get_attachment_image_url($front_id, 'large'); // Large size for social
    $image_meta = wp_get_attachment_metadata($front_id);
    $image_width = $image_meta['width'] ?? 1200;
    $image_height = $image_meta['height'] ?? 630;
    $image_alt = get_post_meta($front_id, '_wp_attachment_image_alt', true);

    // Facets for keywords
    $topics = (array) get_post_meta($front_id, '_ps_topics', true);
    $feelings = (array) get_post_meta($front_id, '_ps_feelings', true);
    $keywords = implode(', ', array_merge($topics, $feelings));

    // Dates
    $published_date = get_the_date('c');
    $modified_date = get_the_modified_date('c');

    ?>
    <!-- SEO Meta -->
    <meta name="description" content="<?php echo esc_attr($description); ?>" />
    <?php if (!empty($keywords)) : ?>
    <meta name="keywords" content="<?php echo esc_attr($keywords); ?>" />
    <?php endif; ?>
    <link rel="canonical" href="<?php echo esc_url($canonical_url); ?>" />

    <!-- Open Graph (Facebook, LinkedIn) -->
    <meta property="og:type" content="article" />
    <meta property="og:title" content="<?php echo esc_attr($title); ?>" />
    <meta property="og:description" content="<?php echo esc_attr($description); ?>" />
    <meta property="og:url" content="<?php echo esc_url($canonical_url); ?>" />
    <meta property="og:site_name" content="<?php echo esc_attr(get_bloginfo('name')); ?>" />
    <meta property="og:locale" content="<?php echo esc_attr(get_locale()); ?>" />
    <?php if ($image_url) : ?>
    <meta property="og:image" content="<?php echo esc_url($image_url); ?>" />
    <meta property="og:image:width" content="<?php echo esc_attr($image_width); ?>" />
    <meta property="og:image:height" content="<?php echo esc_attr($image_height); ?>" />
    <?php if ($image_alt) : ?>
    <meta property="og:image:alt" content="<?php echo esc_attr($image_alt); ?>" />
    <?php endif; ?>
    <?php endif; ?>
    <meta property="article:published_time" content="<?php echo esc_attr($published_date); ?>" />
    <meta property="article:modified_time" content="<?php echo esc_attr($modified_date); ?>" />

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="<?php echo esc_attr($title); ?>" />
    <meta name="twitter:description" content="<?php echo esc_attr($description); ?>" />
    <?php if ($image_url) : ?>
    <meta name="twitter:image" content="<?php echo esc_url($image_url); ?>" />
    <?php if ($image_alt) : ?>
    <meta name="twitter:image:alt" content="<?php echo esc_attr($image_alt); ?>" />
    <?php endif; ?>
    <?php endif; ?>

    <!-- JSON-LD Structured Data -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "CreativeWork",
        "name": <?php echo wp_json_encode($title); ?>,
        "description": <?php echo wp_json_encode($description); ?>,
        "url": <?php echo wp_json_encode($canonical_url); ?>,
        <?php if ($image_url) : ?>
        "image": {
            "@type": "ImageObject",
            "url": <?php echo wp_json_encode($image_url); ?>,
            "width": <?php echo esc_js($image_width); ?>,
            "height": <?php echo esc_js($image_height); ?>
            <?php if ($image_alt) : ?>
            , "description": <?php echo wp_json_encode($image_alt); ?>
            <?php endif; ?>
        },
        <?php endif; ?>
        "datePublished": <?php echo wp_json_encode($published_date); ?>,
        "dateModified": <?php echo wp_json_encode($modified_date); ?>,
        "publisher": {
            "@type": "Organization",
            "name": <?php echo wp_json_encode(get_bloginfo('name')); ?>,
            "url": <?php echo wp_json_encode(home_url('/')); ?>
        },
        "inLanguage": <?php echo wp_json_encode(get_post_meta($front_id, '_ps_language', true) ?: 'en'); ?>,
        "accessMode": ["textual", "visual"],
        "accessibilityFeature": ["alternativeText", "structuredContent"]
        <?php if (!empty($keywords)) : ?>
        , "keywords": <?php echo wp_json_encode($keywords); ?>
        <?php endif; ?>
    }
    </script>
    <?php
}
add_action('wp_head', __NAMESPACE__ . '\\output_secret_meta');

/**
 * Add preconnect hints for performance.
 */
function resource_hints($urls, $relation_type) {
    if ($relation_type === 'preconnect') {
        // Add CDN/image hosts here if needed
        $urls[] = [
            'href' => 'https://cdnjs.cloudflare.com',
            'crossorigin' => 'anonymous',
        ];
    }
    return $urls;
}
add_filter('wp_resource_hints', __NAMESPACE__ . '\\resource_hints', 10, 2);
