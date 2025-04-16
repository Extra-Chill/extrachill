<?php
/**
 * The template for displaying archive pages for the Festival Wire CPT.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package ColorMag Pro
 */

get_header(); ?>

	<div id="primary" class="content-area festival-wire-page">
		<main id="main" class="site-main" role="main">

			<?php 
			// Display breadcrumbs
			if (function_exists('display_breadcrumbs')) {
				display_breadcrumbs();
			}
			
			if ( have_posts() ) : ?>

				<header class="page-header">
					<h1 class="page-title">Festival Wire</h1>
					<div class="archive-description">Stay updated with the latest music festival news, announcements, and updates.</div>
					
					<div class="festival-filter-controls">
						<div class="festival-filter-inner">
							<div class="filter-dropdowns">
								<div class="filter-group">
									<div class="filter-input">
										<select id="festival-filter" class="festival-dropdown">
											<option value="all">All Festivals</option>
											<?php
											// Get only tags that are used by festival_wire posts
											global $wpdb;
											$tags_with_festival_wire = $wpdb->get_col(
												"SELECT DISTINCT terms.term_id
												FROM {$wpdb->posts} posts
												JOIN {$wpdb->term_relationships} rel ON posts.ID = rel.object_id
												JOIN {$wpdb->term_taxonomy} tax ON rel.term_taxonomy_id = tax.term_taxonomy_id
												JOIN {$wpdb->terms} terms ON tax.term_id = terms.term_id
												WHERE posts.post_type = 'festival_wire'
												AND posts.post_status = 'publish'
												AND tax.taxonomy = 'post_tag'"
											);
											
											if (!empty($tags_with_festival_wire)) {
												$tag_args = array(
													'taxonomy' => 'post_tag',
													'include' => $tags_with_festival_wire,
													'hide_empty' => true,
													'orderby' => 'name',
													'order' => 'ASC',
												);
												
												$festival_tags = get_terms($tag_args);
												
												foreach ($festival_tags as $tag) {
													echo '<option value="' . esc_attr($tag->slug) . '">' . esc_html($tag->name) . '</option>';
												}
											}
											?>
										</select>
									</div>
								</div>
								
								<div class="filter-group">
									<div class="filter-input">
										<select id="location-filter" class="location-dropdown">
											<option value="all">All Locations</option>
											<?php
											// Get only locations that are used by festival_wire posts
											$locations_with_festival_wire = $wpdb->get_col(
												"SELECT DISTINCT terms.term_id
												FROM {$wpdb->posts} posts
												JOIN {$wpdb->term_relationships} rel ON posts.ID = rel.object_id
												JOIN {$wpdb->term_taxonomy} tax ON rel.term_taxonomy_id = tax.term_taxonomy_id
												JOIN {$wpdb->terms} terms ON tax.term_id = terms.term_id
												WHERE posts.post_type = 'festival_wire'
												AND posts.post_status = 'publish'
												AND tax.taxonomy = 'location'"
											);
											
											if (!empty($locations_with_festival_wire)) {
												$location_args = array(
													'taxonomy' => 'location',
													'include' => $locations_with_festival_wire,
													'hide_empty' => true,
													'orderby' => 'name',
													'order' => 'ASC',
												);
												
												$festival_locations = get_terms($location_args);
												
												foreach ($festival_locations as $location) {
													echo '<option value="' . esc_attr($location->slug) . '">' . esc_html($location->name) . '</option>';
												}
											}
											?>
										</select>
									</div>
								</div>
							</div>
							<div class="filter-actions">
								<button id="festival-filter-button" class="filter-button">Apply Filters</button>
								<a href="<?php echo esc_url(get_post_type_archive_link('festival_wire')); ?>" class="filter-reset">Reset Filters</a>
							</div>
						</div>
					</div>
				</header><!-- .page-header -->

				<div class="festival-wire-grid-container">
					<div id="festival-wire-posts-container" class="festival-wire-grid">
					<?php
					/* Start the Loop */
					while ( have_posts() ) : the_post();
					?>
						<article id="post-<?php the_ID(); ?>" <?php post_class('festival-wire-card'); ?>>
							<?php if (has_post_thumbnail()): ?>
							<div class="festival-wire-card-image">
								<?php the_post_thumbnail('medium'); ?>
							</div>
							<?php endif; ?>
							
							<div class="festival-wire-card-content">
								<?php
								// Display categories and tags in a flex container
								echo '<div class="festival-badges">';
								
								// Get post categories for festival tags
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
								?>
								
								<header class="entry-header">
									<?php the_title( sprintf( '<h2 class="entry-title"><a href="%s" class="card-link-target" rel="bookmark">', esc_url( get_permalink() ) ), '</a></h2>' ); ?>
								</header><!-- .entry-header -->

								<div class="entry-meta">
									<span class="posted-on"><?php echo esc_html( get_the_date() ); ?></span>
									<?php
									// REMOVE Location Terms display from here
									/*
									$locations = get_the_terms( get_the_ID(), 'location' );
									if ( $locations && ! is_wp_error( $locations ) ) :
										echo '<span class="meta-sep"> | </span><span class="location-meta">'; // Separator and container
										$location_links = array();
										foreach ( $locations as $location ) :
											$location_link = get_term_link( $location );
											if ( ! is_wp_error( $location_link ) ) :
												// Wrap the link in a span with the location slug class for CSS targeting
												$location_links[] = '<span class="location-' . esc_attr( $location->slug ) . '"><a href="' . esc_url( $location_link ) . '" class="location-link" rel="tag">' . esc_html( $location->name ) . '</a></span>';
											endif;
										endforeach;
										echo implode( ', ', $location_links ); // Comma-separate if multiple locations
										echo '</span>';
									endif;
									*/
									?>
								</div><!-- .entry-meta -->

								<div class="entry-summary">
									<?php the_excerpt(); ?>
								</div><!-- .entry-summary -->
							</div>
						</article><!-- #post-<?php the_ID(); ?> -->
					<?php
					endwhile;
					?>
					</div><!-- #festival-wire-posts-container.festival-wire-grid -->

					<?php
					// Pagination removed
					/*
					if (function_exists('wp_pagenavi')) {
						wp_pagenavi();
					} else {
						the_posts_navigation();
					}
					*/
					?>
				</div><!-- .festival-wire-grid-container -->

                <?php // Add Load More Button conditionally outside the container
                global $wp_query;
                if ( $wp_query->max_num_pages > 1 ) : ?>
                    <button id="festival-wire-load-more" class="cm-load-more-button">Load More Posts</button>
                <?php endif; ?>

				<!-- Modular Festival Wire Tip Form -->
				<div class="festival-wire-tip-form-container">
					<h2 class="tip-form-title">Have a Festival News Tip?</h2>
					<p class="tip-form-description">Heard something exciting about an upcoming festival? Drop us a tip, and we'll check it out!</p>
					<?php require get_template_directory() . '/festival-wire/festival-tip-form.php'; ?>
				</div>

			<?php
			else : 
				// If no content, include the "No posts found" template.
				get_template_part( 'template-parts/content/content', 'none' );
			?>
			<?php endif; ?>

		</main><!-- #main -->
	</div><!-- #primary -->

<?php 
// Sidebar removed as per requirements
// get_sidebar(); 
?>
<?php get_footer(); ?> 