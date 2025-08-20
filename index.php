<?php
/**
 * Theme Index Section for our theme.
 *
 * @package ExtraChill
 * @since 1.0
 */

get_header(); ?>

	<?php do_action( 'extrachill_before_body_content' ); ?>

	<section id="primary">
		<div id="content" class="clearfix">

			<?php if ( have_posts() ) : ?>

				<?php while ( have_posts() ) : the_post(); 
			?>

					<?php get_template_part( 'content', '' ); ?>
			

				<?php endwhile; ?>

				<?php get_template_part( 'navigation', 'none' ); ?>

			<?php else : ?>

				<?php get_template_part( 'no-results', 'none' ); ?>

			<?php endif; ?>

		</div><!-- #content -->
			</section><!-- #primary -->

	<?php get_sidebar(); ?>

	<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>
