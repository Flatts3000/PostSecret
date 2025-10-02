<?php
/**
 * Secret card partial - Catalog-style layout.
 * Thumbnail on left, content on right, red arrow cue.
 *
 * @package PostSecret
 */

?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'ps-catalog-card' ); ?>>
    <a href="<?php the_permalink(); ?>" class="ps-catalog-card__link ps-arrow">
        <div class="ps-catalog-card__inner">
            <?php if ( has_post_thumbnail() ) : ?>
                <div class="ps-catalog-card__thumbnail">
                    <?php the_post_thumbnail( 'medium' ); ?>
                </div>
            <?php endif; ?>

            <div class="ps-catalog-card__content">
                <header class="ps-catalog-card__header">
                    <h3 class="ps-catalog-card__title">
                        <?php the_title(); ?>
                    </h3>
                </header>

                <?php
                // Display facets (topics, feelings, meanings) as chips
                $topics = get_post_meta( get_the_ID(), '_ps_topics', true );
                $feelings = get_post_meta( get_the_ID(), '_ps_feelings', true );
                $meanings = get_post_meta( get_the_ID(), '_ps_meanings', true );

                $all_facets = array_merge(
                    is_array( $topics ) ? $topics : [],
                    is_array( $feelings ) ? $feelings : [],
                    is_array( $meanings ) ? $meanings : []
                );

                if ( ! empty( $all_facets ) ) :
                    ?>
                    <div class="ps-catalog-card__facets">
                        <?php
                        $visible_facets = array_slice( $all_facets, 0, 3 );
                        foreach ( $visible_facets as $facet ) :
                            ?>
                            <span class="ps-facet-chip"><?php echo esc_html( $facet ); ?></span>
                        <?php endforeach; ?>
                        <?php if ( count( $all_facets ) > 3 ) : ?>
                            <span class="ps-facet-chip ps-facet-chip--more">+<?php echo count( $all_facets ) - 3; ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="ps-catalog-card__excerpt">
                    <?php
                    $excerpt = get_the_excerpt();
                    echo esc_html( wp_trim_words( $excerpt, 20, '...' ) );
                    ?>
                </div>
            </div>
        </div>
    </a>
</article>
