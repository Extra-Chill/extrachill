<?php
/**
 * Main index template for post loops and fallback displays
 *
 * Displays post cards using modular template parts and native pagination.
 * Uses action hooks for extensible content sections.
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

					<?php get_template_part( 'inc/archives/post-card' ); ?>
			

				<?php endwhile; ?>

				<?php extrachill_pagination(null, 'index'); ?>

			<?php else : ?>

				<?php extrachill_no_results(); ?>

			<?php endif; ?>

		</div><!-- #content -->
			</section><!-- #primary -->

	<?php get_sidebar(); ?>

	<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>
