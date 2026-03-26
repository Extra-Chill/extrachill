<?php
/**
 * Single Page Template
 *
 * Displays individual pages with content and edit link.
 * Loaded via template_include filter in inc/core/template-router.php.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

get_header(); ?>

<?php do_action( 'extrachill_before_body_content' ); ?>

<section class="main-content">
<?php
while ( have_posts() ) :
	the_post();
	?>

	<?php extrachill_breadcrumbs(); ?>

	<article id="post-<?php the_ID(); ?>" <?php post_class( is_page( 'settings' ) ? 'page-edge-shell ec-mobile-edge-surface' : '' ); ?>>
		<?php do_action( 'extrachill_before_page_content' ); ?>

		<?php if ( apply_filters( 'extrachill_show_page_title', true, get_the_ID() ) ) : ?>
			<header>
				<h1>
					<?php the_title(); ?>
				</h1>
			</header>
		<?php endif; ?>

		<div class="entry-content">
			<?php the_content(); ?>
		</div>

		<?php do_action( 'extrachill_after_page_content' ); ?>
	</article>

<?php endwhile; ?>
	</section>

<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>
