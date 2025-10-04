<?php
/**
 * Template Name: Time Machine
 *
 * Stub page for Time Machine feature - browse secrets by date.
 *
 * @package PostSecret
 */

// Cache headers
header('Cache-Control: public, max-age=300, s-maxage=600');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Time Machine - <?php bloginfo('name'); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<?php block_template_part('header'); ?>

<main id="primary" class="ps-time-machine" role="main" style="margin-top:0;margin-bottom:0;padding-top:clamp(2rem, 4vw, 4rem);padding-right:clamp(1rem, 3vw, 2rem);padding-bottom:clamp(2rem, 4vw, 4rem);padding-left:clamp(1rem, 3vw, 2rem);max-width:1200px;margin-left:auto;margin-right:auto">

    <article class="ps-stub-content">
        <header class="ps-stub-header">
            <h1 class="ps-stub-title">
                <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                Time Machine
            </h1>
            <p class="ps-stub-subtitle">Browse PostSecret history by date</p>
        </header>

        <div class="ps-stub-body">
            <p>The Time Machine feature is coming soon! This will allow you to explore PostSecret's archive by specific dates and time periods.</p>

            <p>Features planned:</p>
            <ul>
                <li>Browse secrets by specific date</li>
                <li>Jump to random dates in PostSecret history</li>
                <li>View secrets from significant moments</li>
                <li>Timeline visualization of the collection</li>
            </ul>

            <p><a href="/" class="ps-back-link">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                Back to Secrets
            </a></p>
        </div>
    </article>

</main>

<style>
.ps-time-machine {
    min-height: 60vh;
}

.ps-stub-content {
    max-width: 800px;
    margin: 0 auto;
}

.ps-stub-header {
    text-align: center;
    margin-bottom: 3rem;
    padding-bottom: 2rem;
    border-bottom: 2px solid var(--wp--preset--color--accent);
}

.ps-stub-title {
    font-size: clamp(2rem, 4vw, 3rem);
    font-weight: 700;
    margin: 0 0 1rem 0;
    color: var(--wp--preset--color--text);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1rem;
}

.ps-stub-title i {
    color: var(--wp--preset--color--accent);
}

.ps-stub-subtitle {
    font-size: 1.25rem;
    color: var(--wp--preset--color--muted);
    margin: 0;
}

.ps-stub-body {
    font-size: 1.125rem;
    line-height: 1.8;
    color: var(--wp--preset--color--text);
}

.ps-stub-body p {
    margin: 0 0 1.5rem 0;
}

.ps-stub-body ul {
    margin: 0 0 2rem 0;
    padding-left: 2rem;
}

.ps-stub-body li {
    margin-bottom: 0.75rem;
}

.ps-back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--wp--preset--color--text);
    text-decoration: none;
    font-weight: 500;
    transition: color 0.2s ease;
    margin-top: 2rem;
}

.ps-back-link:hover {
    color: var(--wp--preset--color--accent);
}

.ps-back-link:focus-visible {
    outline: 2px solid var(--wp--preset--color--accent);
    outline-offset: 4px;
}
</style>

<?php block_template_part('footer'); ?>

<?php wp_footer(); ?>
</body>
</html>
