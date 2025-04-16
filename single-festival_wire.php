<?php
/**
 * The template for displaying all single Festival Wire posts.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/#single-post
 *
 * @package ColorMag Pro
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
					// Display categories and tags in a flex container
					echo '<div class="festival-badges">';
					
					// Display categories as tags
					$categories = get_the_category();
					if (!empty($categories)) {
						echo '<div class="festival-tags">';
						foreach ($categories as $category) {
							echo '<a href="' . esc_url(get_category_link($category->term_id)) . '" class="festival-tag category-tag">' . esc_html($category->name) . '</a>';
						}
						echo '</div>';
					}

					// Display tags if available
					$tags = get_the_tags();
					if ($tags) {
						// Restore the original parent div class
						echo '<div class="post-tags">';
						foreach ($tags as $tag) {
							// Add festival-specific class to the anchor tag
							$tag_link_classes = 'festival-tag tag-tag festival-' . esc_attr($tag->slug);
							echo '<a href="' . esc_url(get_tag_link($tag->term_id)) . '" class="' . $tag_link_classes . '">' . esc_html($tag->name) . '</a>';
						}
						echo '</div>';
					}

					// Display Location Terms here
					$locations = get_the_terms( get_the_ID(), 'location' );
					if ( $locations && ! is_wp_error( $locations ) ) :
						echo '<div class="location-badges">'; // Container for locations
						foreach ( $locations as $location ) :
							$location_link = get_term_link( $location );
							if ( ! is_wp_error( $location_link ) ) :
								// Wrap the link in a span with the location slug class and use festival-tag class
								echo '<span class="location-' . esc_attr( $location->slug ) . '"><a href="' . esc_url( $location_link ) . '" class="festival-tag location-link" rel="tag">' . esc_html( $location->name ) . '</a></span>';
							endif;
						endforeach;
						echo '</div>'; // Close .location-badges
					endif;

					echo '</div>'; // .festival-badges
					
					// Display the title
					the_title( '<h1 class="entry-title">', '</h1>' ); 
					?>

					<div class="entry-meta">
						<span class="posted-on"><?php echo esc_html( get_the_date() ); ?></span>
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
						'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'colormag-pro' ),
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
							esc_html__( 'Edit %s', 'colormag-pro' ),
							'<span class="screen-reader-text">' . get_the_title() . '</span>'
						),
						'<span class="edit-link">',
						'</span>'
					);
					?>
				</footer><!-- .entry-footer -->
			</article><!-- #post-<?php the_ID(); ?> -->

			<?php
			// Related festival wire posts
			$current_post_id = get_the_ID();
			$current_tags = wp_get_post_tags($current_post_id);
			
			if (!empty($current_tags)) {
				$related_args = array(
					'post_type' => 'festival_wire',
					'posts_per_page' => 4,
					'post__not_in' => array($current_post_id),
					'tag__in' => array($current_tags[0]->term_id),
					'orderby' => 'date',
					'order' => 'DESC',
				);
				
				$related_query = new WP_Query($related_args);
				
				if ($related_query->have_posts()) {
					echo '<div class="related-festival-wire">';
					$tag_name = $current_tags[0]->name;
					echo '<h2 class="related-wire-title">' . sprintf(esc_html__('Related %s News', 'colormag-pro'), $tag_name) . '</h2>';
					echo '<div class="festival-wire-grid">';
					
					while ($related_query->have_posts()) {
						$related_query->the_post();
						?>
						<article id="post-<?php the_ID(); ?>" <?php post_class('festival-wire-card'); ?>>
							<?php if (has_post_thumbnail()): ?>
							<div class="festival-wire-card-image">
								<a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
									<?php the_post_thumbnail('medium'); ?>
								</a>
							</div>
							<?php endif; ?>
							
							<div class="festival-wire-card-content">
								<?php
								// Start badges container
								echo '<div class="festival-badges">';

								// Display categories as tags
								$categories = get_the_category();
								if (!empty($categories)) {
									echo '<div class="festival-tags">';
									foreach ($categories as $category) {
										echo '<a href="' . esc_url(get_category_link($category->term_id)) . '" class="festival-tag category-tag">' . esc_html($category->name) . '</a>';
									}
									echo '</div>';
								}

								// Display tags if available
								$tags = get_the_tags();
								if ($tags) {
									echo '<div class="post-tags">';
									foreach ($tags as $tag) {
										$tag_link_classes = 'festival-tag tag-tag festival-' . esc_attr($tag->slug);
										echo '<a href="' . esc_url(get_tag_link($tag->term_id)) . '" class="' . $tag_link_classes . '">' . esc_html($tag->name) . '</a>';
									}
									echo '</div>';
								}

								// Display Location Terms here
								$locations = get_the_terms( get_the_ID(), 'location' );
								if ( $locations && ! is_wp_error( $locations ) ) :
									echo '<div class="location-badges">'; // Container for locations
									foreach ( $locations as $location ) :
										$location_link = get_term_link( $location );
										if ( ! is_wp_error( $location_link ) ) :
											// Wrap the link in a span with the location slug class and use festival-tag class
											echo '<span class="location-' . esc_attr( $location->slug ) . '"><a href="' . esc_url( $location_link ) . '" class="festival-tag location-link" rel="tag">' . esc_html( $location->name ) . '</a></span>';
										endif;
									endforeach;
									echo '</div>'; // Close .location-badges
								endif;

								echo '</div>'; // .festival-badges
								?>
								
								<header class="entry-header">
									<?php the_title( sprintf( '<h2 class="entry-title"><a href="%s" rel="bookmark">', esc_url( get_permalink() ) ), '</a></h2>' ); ?>
								</header><!-- .entry-header -->

								<div class="entry-meta">
									<span class="posted-on"><?php echo esc_html( get_the_date() ); ?></span>
								</div><!-- .entry-meta -->

								<div class="entry-summary">
									<?php the_excerpt(); ?>
								</div><!-- .entry-summary -->
							</div>
						</article>
						<?php
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
			<?php require get_template_directory() . '/festival-wire/festival-tip-form.php'; ?>
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