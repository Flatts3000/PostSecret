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
        <?php
        // Display facets (topics, feelings, meanings)
        $topics = get_post_meta( get_the_ID(), '_ps_topics', true );
        $feelings = get_post_meta( get_the_ID(), '_ps_feelings', true );
        $meanings = get_post_meta( get_the_ID(), '_ps_meanings', true );

        $all_facets = array_merge(
            is_array( $topics ) ? $topics : [],
            is_array( $feelings ) ? $feelings : [],
            is_array( $meanings ) ? $meanings : []
        );

        if ( ! empty( $all_facets ) ) :
            echo '<span class="facet-links">';
            $facet_links = array_map(
                function( $facet ) {
                    return '<span class="facet">' . esc_html( $facet ) . '</span>';
                },
                array_slice( $all_facets, 0, 3 ) // Show first 3
            );
            echo implode( ', ', $facet_links );
            if ( count( $all_facets ) > 3 ) {
                echo ' <span class="facet-more">+' . ( count( $all_facets ) - 3 ) . '</span>';
            }
            echo '</span>';
        endif;
        ?>
    </footer>
</article>
