<?php
/**
 * Template Name: Post Copy
 */

get_header(); ?>

	<?php do_action( 'extrachill_before_body_content' ); 
?><section id="primary">

		<div id="content" class="clearfix">
			<?php while ( have_posts() ) : the_post(); ?>

				<?php get_template_part( 'content', 'single' ); ?>

				<?php
				do_action( 'extrachill_before_comments_template' );
				// If comments are open or we have at least one comment, load up the comment template
				if ( comments_open() || '0' != get_comments_number() )
					comments_template();
				do_action ( 'extrachill_after_comments_template' );
				?>

			<?php endwhile; ?>

		</div><!-- #content -->
	</div><!-- #primary -->

	<?php get_sidebar(); ?>

	<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>
