<?php
/**
 * The template for displaying all single Festival Wire posts.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/#single-post
 *
 * @package ExtraChillNewsWire
 * @since 0.1.0
 */

get_header(); ?>

<?php do_action( 'extrachill_before_body_content' ); ?>

	<div class="main-content">
		<main id="main" class="site-main" role="main">

		<?php
		// Display breadcrumbs using theme function
		extrachill_breadcrumbs();

		while ( have_posts() ) : the_post();
		?>
	
<div class="single-post-card">
<article id="post-<?php the_ID(); ?>" <?php post_class( array( 'festival-wire-single-post', 'single-post' ) ); ?>>
	<?php do_action('extrachill_before_post_content'); ?>
				<header>
					<?php do_action( 'extrachill_above_post_title' ); ?>
					<?php the_title( '<h1>', '</h1>' ); ?>
				</header>
				<?php extrachill_entry_meta(); ?>

				<?php 
				// Display featured image
				if ( has_post_thumbnail() ) { ?>
					<div class="post-thumbnail">
						<?php the_post_thumbnail( 'large' ); ?>
						<?php 
						// Display image caption if available
						$thumbnail_id = get_post_thumbnail_id();
						$thumbnail_caption = get_post($thumbnail_id)->post_excerpt;
						if (!empty($thumbnail_caption)) {
							echo '<div class="featured-image-caption">' . wp_kses_post($thumbnail_caption) . '</div>';
						}
						?>
					</div>
				<?php } ?>

				<div class="entry-content">
					<?php
					the_content();

					wp_link_pages( array(
						'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'extrachill' ),
						'after'  => '</div>',
					) );
					?>
				</div><!-- .entry-content -->

	<?php do_action('extrachill_after_post_content'); ?>
				<footer class="entry-footer">
					<?php
					
					// Edit post link for logged in users
					edit_post_link(
						sprintf(
							/* translators: %s: Name of current post. Only visible to screen readers. */
							esc_html__( 'Edit %s', 'extrachill' ),
							'<span class="screen-reader-text">' . get_the_title() . '</span>'
						),
						'<span class="edit-link">',
						'</span>'
					);
					?>
				</footer><!-- .entry-footer -->
			</article><!-- #post-<?php the_ID(); ?> -->
</div>

	<?php endwhile; // End of the loop. ?>

<aside>
	<?php
	// Related festival wire posts (Cached)
			$current_post_id = get_the_ID();
			$current_festivals = get_the_terms(get_the_ID(), 'festival');
			
			if (!empty($current_festivals) && !is_wp_error($current_festivals)) {
				$festival_term_id = $current_festivals[0]->term_id;
				$festival_name = $current_festivals[0]->name;
				
				// Get related posts directly
				$related_posts = get_posts(array(
					'post_type' => 'festival_wire',
					'numberposts' => 6,
					'post__not_in' => array($current_post_id),
					'tax_query' => array(
						array(
							'taxonomy' => 'festival',
							'field' => 'term_id',
							'terms' => $festival_term_id
						)
					)
				));
				
				if (!empty($related_posts)) {
					echo '<div class="related-tax-section related-festival-wire">';
					echo '<h3 class="related-tax-header">' . sprintf(esc_html__('Related %s News', 'extrachill'), esc_html($festival_name)) . '</h3>';
					echo '<div class="related-tax-grid festival-wire-grid">';

					// Set up global post data for template parts
					global $post;
					foreach ($related_posts as $related_post) {
						$post = $related_post;
						setup_postdata($post);
						
						$related_post_id = get_the_ID();
						// Collect related post IDs for sidebar exclusion
						if (!isset($GLOBALS['displayed_posts']) || !is_array($GLOBALS['displayed_posts'])) {
							$GLOBALS['displayed_posts'] = array();
						}
						if (!in_array($related_post_id, $GLOBALS['displayed_posts'])) {
							$GLOBALS['displayed_posts'][] = $related_post_id;
						}

						// Use plugin's content card
						require __DIR__ . '/content-card.php';
					}
					
					echo '</div>'; // .festival-wire-grid
					echo '</div>'; // .related-festival-wire
					
					wp_reset_postdata();
				}
			}
	?>
</aside>

<?php
// Newsletter plugin owns and displays the tip form
do_action('extrachill_after_news_wire');
?>

		<!-- Back to Archive Button -->
		<div class="festival-wire-back-button-container">
			<a href="<?php echo esc_url( get_post_type_archive_link( 'festival_wire' ) ); ?>" class="cm-button cm-back-button">Back to Festival Wire</a>
		</div>

		</main><!-- #main -->
	</div><!-- .main-content -->

<?php get_sidebar(); ?>

<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?> 