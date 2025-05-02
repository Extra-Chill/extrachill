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
											// Get only festivals that are used by festival_wire posts
											global $wpdb;
											$festivals_with_festival_wire = $wpdb->get_col(
												"SELECT DISTINCT terms.term_id
												FROM {$wpdb->posts} posts
												JOIN {$wpdb->term_relationships} rel ON posts.ID = rel.object_id
												JOIN {$wpdb->term_taxonomy} tax ON rel.term_taxonomy_id = tax.term_taxonomy_id
												JOIN {$wpdb->terms} terms ON tax.term_id = terms.term_id
												WHERE posts.post_type = 'festival_wire'
												AND posts.post_status = 'publish'
												AND tax.taxonomy = 'festival'"
											);
											
											if (!empty($festivals_with_festival_wire)) {
												$festival_args = array(
													'taxonomy' => 'festival',
													'include' => $festivals_with_festival_wire,
													'hide_empty' => true,
													'orderby' => 'name',
													'order' => 'ASC',
												);
												
												$festival_terms = get_terms($festival_args);
												
												foreach ($festival_terms as $festival) {
													echo '<option value="' . esc_attr($festival->slug) . '">' . esc_html($festival->name) . '</option>';
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
						/**
						 * Include the Post-Format-specific template for the content.
						 * If you want to override this in a child theme, then include a file
						 * called content-___.php (where ___ is the Post Format name) and that will be used instead.
						 */
						// Replace the entire <article> block with get_template_part(), using the correct path
						get_template_part( 'festival-wire/content', 'card' );
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

				<!-- Music Festivals Forum CTA -->
				<div class="forum-cta-container">
					<h2 class="forum-cta-title">Join the Discussion!</h2>
					<p class="forum-cta-description">Chat with fellow festival fans, share your experiences, and get the latest tips in our Music Festivals forum.</p>
					<a href="<?php echo esc_url('https://community.extrachill.com/r/music-festivals'); ?>" class="forum-cta-link button" target="_blank" rel="noopener noreferrer">Visit the Forum</a>
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