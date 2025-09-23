<?php
/**
 * The template for displaying all single Festival Wire posts.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/#single-post
 *
 * @package ExtraChill
 * @since 1.0
 */

get_header(); ?>

	<div id="primary" class="content-area">
		<main id="main" class="site-main" role="main">

		<?php
		// Display breadcrumbs
		if (function_exists('display_breadcrumbs')) {
			display_breadcrumbs();
		}
		
		while ( have_posts() ) : the_post();
		?>
			<article id="post-<?php the_ID(); ?>" <?php post_class( array( 'festival-wire-single-post', 'single-post' ) ); ?>>
				<header class="entry-header">
					<?php
					// Display all taxonomies in a unified container
					echo '<div class="taxonomy-badges">';
					
					// Display categories as tags
					$categories = get_the_category();
					if (!empty($categories)) {
						foreach ($categories as $category) {
							$cat_slug = sanitize_html_class($category->slug);
							echo '<a href="' . esc_url(get_category_link($category->term_id)) . '" class="taxonomy-badge category-badge category-' . $cat_slug . '-badge">' . esc_html($category->name) . '</a>';
						}
					}

					// Get festival taxonomy terms
					$festivals = get_the_terms(get_the_ID(), 'festival');
					if ($festivals && !is_wp_error($festivals)) {
						foreach ($festivals as $festival) {
							$festival_slug = sanitize_html_class($festival->slug);
							echo '<a href="' . esc_url(get_term_link($festival)) . '" class="taxonomy-badge festival-badge festival-' . $festival_slug . '">' . esc_html($festival->name) . '</a>';
						}
					}

					// Display Location Terms
					$locations = get_the_terms(get_the_ID(), 'location');
					if ( $locations && ! is_wp_error( $locations ) ) :
						foreach ( $locations as $location ) :
							$location_link = get_term_link( $location );
							if ( ! is_wp_error( $location_link ) ) :
								$loc_slug = sanitize_html_class( $location->slug );
								echo '<a href="' . esc_url( $location_link ) . '" class="taxonomy-badge location-badge location-' . $loc_slug . '" rel="tag">' . esc_html( $location->name ) . '</a>';
							endif;
						endforeach;
					endif;

					echo '</div>'; // .taxonomy-badges
					
					// Display the title
					the_title( '<h1 class="entry-title">', '</h1>' ); 
					?>

					<div class="entry-meta">
						<span class="posted-on"><?php echo esc_html( get_the_date('F j, Y \a\t g:ia') ); ?></span>
						<?php // Location is now displayed in the badges section above ?>
					</div><!-- .entry-meta -->
				</header><!-- .entry-header -->

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
					echo '<div class="related-festival-wire">';
					echo '<h2 class="related-wire-title">' . sprintf(esc_html__('Related %s News', 'extrachill'), esc_html($festival_name)) . '</h2>';
					echo '<div class="festival-wire-grid">';

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
						require __DIR__ . '/../extrachill-news-wire/templates/content-card.php';
					}
					
					echo '</div>'; // .festival-wire-grid
					echo '</div>'; // .related-festival-wire
					
					wp_reset_postdata();
				}
			}
			?>
		
		<?php endwhile; // End of the loop. ?>

		<!-- Modular Festival Wire Tip Form -->
		<div class="festival-wire-tip-form-container">
			<h2 class="tip-form-title">Have a Festival News Tip?</h2>
			<p class="tip-form-description">Heard something exciting about an upcoming festival? Drop us a tip, and we'll check it out!</p>
			<?php require __DIR__ . '/../extrachill-news-wire/includes/festival-tip-form.php'; ?>
		</div>

		<!-- Back to Archive Button -->
		<div class="festival-wire-back-button-container">
			<a href="<?php echo esc_url( get_post_type_archive_link( 'festival_wire' ) ); ?>" class="cm-button cm-back-button">Back to Festival Wire</a>
		</div>

		</main><!-- #main -->
	</div><!-- #primary -->

<?php
get_sidebar();
?>
<?php get_footer(); ?> 