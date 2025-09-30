<?php
/**
 * The main template file
 *
 * @package PostSecret
 */

get_header();
?>

<main id="main" class="site-main">
	<?php
	if ( have_posts() ) :
		while ( have_posts() ) :
			the_post();
			get_template_part( 'parts/card' );
		endwhile;

		the_posts_pagination();
	else :
		?>
		<p><?php esc_html_e( 'No secrets found.', 'postsecret' ); ?></p>
		<?php
	endif;
	?>
</main>

<?php
get_footer();
