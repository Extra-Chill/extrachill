<?php
/**
 * The template for displaying Search Results pages.
 *
 * @package ExtraChill
 * @since 1.0
 */

get_header(); ?>

	<?php do_action( 'extrachill_before_body_content' ); ?>
<div id="mediavine-settings" data-blocklist-all="1" ></div>
	<section id="primary">
		<div id="content" class="clearfix">
			<?php display_breadcrumbs(); ?>
			<!-- Display the Search Query -->
			<div class="search-header"><h2>Search Results for: <span class="search-query"><?php echo esc_html( get_search_query() ); ?></span></h2></div>
			<?php if ( have_posts() ) : ?>

				<div class="article-container">

					<?php global $post_i; $post_i = 1; ?>

					<?php while ( have_posts() ) : the_post(); ?>

						<?php get_template_part( 'content', 'archive' ); ?>

					<?php endwhile; ?>

				</div>

				<?php get_template_part( 'navigation', 'archive' ); ?>

				<div class="back-home-link-container">
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="back-home-link">← Back Home</a>
				</div>

			<?php else : ?>

				<?php get_template_part( 'no-results', 'archive' ); ?>

			<?php endif; ?>

		</div><!-- #content -->
			</section><!-- #primary -->

	<?php // get_sidebar(); ?>

	<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>
