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
                <?php
                // Display all facets organized by type
                $topics = get_post_meta( get_the_ID(), '_ps_topics', true );
                $feelings = get_post_meta( get_the_ID(), '_ps_feelings', true );
                $meanings = get_post_meta( get_the_ID(), '_ps_meanings', true );

                if ( ! empty( $topics ) && is_array( $topics ) ) :
                    echo '<div class="facet-group facet-topics">';
                    echo '<strong>' . esc_html__( 'Topics:', 'postsecret' ) . '</strong> ';
                    echo '<span class="facet-list">' . esc_html( implode( ', ', $topics ) ) . '</span>';
                    echo '</div>';
                endif;

                if ( ! empty( $feelings ) && is_array( $feelings ) ) :
                    echo '<div class="facet-group facet-feelings">';
                    echo '<strong>' . esc_html__( 'Feelings:', 'postsecret' ) . '</strong> ';
                    echo '<span class="facet-list">' . esc_html( implode( ', ', $feelings ) ) . '</span>';
                    echo '</div>';
                endif;

                if ( ! empty( $meanings ) && is_array( $meanings ) ) :
                    echo '<div class="facet-group facet-meanings">';
                    echo '<strong>' . esc_html__( 'Meanings:', 'postsecret' ) . '</strong> ';
                    echo '<span class="facet-list">' . esc_html( implode( ', ', $meanings ) ) . '</span>';
                    echo '</div>';
                endif;
                ?>
            </footer>
        </article>
    <?php endwhile; ?>
</main>

<?php
get_footer();
