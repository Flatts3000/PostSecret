<?php
/**
 * Front page template.
 *
 * @package PostSecret
 */

get_header();
?>

<main id="primary" class="site-main">
    <h1><?php esc_html_e( 'Welcome to PostSecret', 'postsecret' ); ?></h1>
    <p><?php esc_html_e( 'Discover anonymous secrets shared by people from all around the world.', 'postsecret' ); ?></p>
    <div class="latest-secrets">
        <?php
        $latest_query = new WP_Query(
            [
                'post_type'      => 'post',
                'posts_per_page' => 6,
            ]
        );
        if ( $latest_query->have_posts() ) :
            while ( $latest_query->have_posts() ) :
                $latest_query->the_post();
                get_template_part( 'parts/card', get_post_type() );
            endwhile;
            wp_reset_postdata();
        endif;
        ?>
    </div>
</main>

<?php
get_footer();
