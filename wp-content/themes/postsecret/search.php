<?php
/**
 * Search results template.
 *
 * @package PostSecret
 */

get_header();
?>

<main id="primary" class="site-main">
    <h1><?php printf( esc_html__( 'Search Results for: %s', 'postsecret' ), get_search_query() ); ?></h1>

    <?php if ( have_posts() ) : ?>
        <div class="search-results">
            <?php
            while ( have_posts() ) :
                the_post();
                get_template_part( 'parts/card', get_post_type() );
            endwhile;
            ?>
        </div>

        <div class="pagination">
            <?php
            the_posts_pagination(
                [
                    'prev_text' => __( 'Previous', 'postsecret' ),
                    'next_text' => __( 'Next', 'postsecret' ),
                ]
            );
            ?>
        </div>
    <?php else : ?>
        <p><?php esc_html_e( 'Nothing found.', 'postsecret' ); ?></p>
    <?php endif; ?>
</main>

<?php
get_footer();
