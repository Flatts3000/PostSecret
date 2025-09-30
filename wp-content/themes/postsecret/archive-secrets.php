<?php
/**
 * Archive template for secrets.
 *
 * @package PostSecret
 */

get_header();
?>

<main id="primary" class="site-main">
    <h1><?php esc_html_e( 'Secrets Archive', 'postsecret' ); ?></h1>

    <?php if ( have_posts() ) : ?>
        <div class="secrets-archive">
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
        <p><?php esc_html_e( 'No secrets found.', 'postsecret' ); ?></p>
    <?php endif; ?>
</main>

<?php
get_footer();
