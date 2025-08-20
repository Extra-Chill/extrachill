<?php
/**
 * Template Name: Page Builder Template
 *
 * Displays the Page Builder Template provided via the theme.
 * Suitable for page builder plugins
 *
 * @package    ExtraChill
 * @since      1.0
 */

get_header(); ?>

<?php do_action( 'extrachill_before_body_content' ); ?>

<section id="primary">
	<div id="content" class="pagebuilder-content clearfix">
		<?php
		while ( have_posts() ) : the_post();

			the_content();

		endwhile;
		?>

	</div><!-- #content -->
</div><!-- #primary -->

<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>
