<?php
/**
 * Secret card partial.
 *
 * @package PostSecret
 */

?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'secret-card' ); ?>>
    <?php if ( has_post_thumbnail() ) : ?>
        <div class="secret-image">
            <a href="<?php the_permalink(); ?>">
                <?php the_post_thumbnail( 'large' ); ?>
            </a>
        </div>
    <?php endif; ?>
    <header class="secret-header">
        <h2 class="secret-title">
            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
        </h2>
    </header>
    <div class="secret-excerpt">
        <?php the_excerpt(); ?>
    </div>
    <footer class="secret-footer">
        <?php the_tags( '<span class="tag-links">', ', ', '</span>' ); ?>
    </footer>
</article>
