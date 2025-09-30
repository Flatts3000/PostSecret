<?php
/**
 * Single secret template.
 *
 * @package PostSecret
 */

get_header();
?>

<main id="primary" class="site-main">
    <?php
    while ( have_posts() ) :
        the_post();
        ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <h1 class="entry-title"><?php the_title(); ?></h1>
            <div class="entry-content">
                <?php the_content(); ?>
            </div>
            <footer class="entry-footer">
                <?php the_tags( '<span class="tag-links">', ', ', '</span>' ); ?>
            </footer>
        </article>
    <?php endwhile; ?>
</main>

<?php
get_footer();
