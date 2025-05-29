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
										<?php
										// Get only location term IDs that are used by festival_wire posts
										global $wpdb;
										$locations_with_festival_wire_ids = $wpdb->get_col(
											$wpdb->prepare(
												"SELECT DISTINCT terms.term_id
												FROM {$wpdb->posts} posts
												JOIN {$wpdb->term_relationships} rel ON posts.ID = rel.object_id
												JOIN {$wpdb->term_taxonomy} tax ON rel.term_taxonomy_id = tax.term_taxonomy_id
												JOIN {$wpdb->terms} terms ON tax.term_id = terms.term_id
												WHERE posts.post_type = %s
												AND posts.post_status = %s
												AND tax.taxonomy = %s",
												'festival_wire',
												'publish',
												'location'
											)
										);

										$all_location_ids_to_include = array();
										$earth_term_id = null; // Initialize earth term ID

										// --- Get Earth Term ID ---
										$earth_term = get_term_by('slug', 'earth', 'location'); // Assuming slug is 'earth'
										if ($earth_term && is_object($earth_term)) {
											$earth_term_id = $earth_term->term_id;
										}
										// --- End Get Earth Term ID ---

										if (!empty($locations_with_festival_wire_ids)) {
											$all_location_ids_to_include = $locations_with_festival_wire_ids; // Start with locations having posts

											// Find ancestors for each location that has posts
											foreach ($locations_with_festival_wire_ids as $term_id) {
												$ancestors = get_ancestors($term_id, 'location', 'taxonomy');
												if (!empty($ancestors)) {
													$all_location_ids_to_include = array_merge($all_location_ids_to_include, $ancestors);
												}
											}
											// Ensure unique IDs
											$all_location_ids_to_include = array_unique($all_location_ids_to_include);
										}

										// Prepare arguments for wp_dropdown_categories
										$dropdown_args = array(
											'taxonomy'         => 'location',
											'name'             => 'location-filter',
											'id'               => 'location-filter',
											'class'            => 'location-dropdown',
											'show_option_all'  => 'All Locations',
											'orderby'          => 'name',
											'order'            => 'ASC',
											'hierarchical'     => true,
											'value_field'      => 'slug',
											'echo'             => true,
											'hide_empty'       => false, // Allow parents to show
											'selected'         => get_query_var('location'),
											// We still might need 'include' to limit to relevant branches initially
											'include'          => !empty($all_location_ids_to_include) ? $all_location_ids_to_include : array(),
										);

										// --- Add exclude argument if earth term exists ---
										if ($earth_term_id !== null) {
											$dropdown_args['exclude'] = $earth_term_id; // Use exclude parameter
										}
										// --- End Add exclude ---

										// Only generate dropdown if we have locations to include (even if only earth was included initially)
										// Or if earth was excluded, check if anything remains in include list
										$should_display = false;
										if (!empty($all_location_ids_to_include)) {
											if ($earth_term_id !== null) {
												// If earth exists, check if there are other IDs besides earth
												 $temp_ids = array_diff($all_location_ids_to_include, [$earth_term_id]);
												 $should_display = !empty($temp_ids);
											} else {
												// If earth doesn't exist, just check if the include list is populated
												$should_display = true;
											}
										}


										if ($should_display) {
											 // It's generally safer to let wp_dropdown_categories handle filtering with include/exclude
											 // rather than pre-filtering the include array ourselves when using exclude.
											 // Let's remove the explicit include if we are excluding earth, unless it's empty.
											 if ($earth_term_id !== null && !empty($dropdown_args['include'])) {
												 // If excluding earth, let 'exclude' do the work on the full hierarchy,
												 // unless the include list was empty to begin with.
												 // This might be optional, let's test with include first.
												 // unset($dropdown_args['include']);
											 }
											 wp_dropdown_categories($dropdown_args);

										} else {
											// Fallback: If NO relevant locations found (after excluding earth)
											echo '<select id="location-filter" class="location-dropdown" disabled><option value="all">No Locations Found</option></select>';
										}
										?>
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

				<?php
				// --- Display Last Updated Time ---
				$last_updated_string = '';
				$latest_post_args = array(
				    'post_type'      => 'festival_wire',
				    'post_status'    => 'publish',
				    'posts_per_page' => 1,
				    'orderby'        => 'modified_gmt', // Order by last modified date (GMT)
				    'order'          => 'DESC',
				    'fields'         => 'ids', // Only need the ID to get the modified time
				);
				$latest_post_query = new WP_Query($latest_post_args);

				if ($latest_post_query->have_posts()) {
				    $latest_post_id = $latest_post_query->posts[0];
				    // Get the GMT modification time as a Unix timestamp
				    $last_modified_gmt_timestamp = get_post_modified_time('U', true, $latest_post_id);
				    if ($last_modified_gmt_timestamp) {
				         // Compare with current GMT time
				        $time_diff = human_time_diff($last_modified_gmt_timestamp, current_time('timestamp', true));
				        $last_updated_string = sprintf('Feed last updated %s ago', $time_diff);
				    }
				}
				wp_reset_postdata(); // Reset post data after custom query

				// Output the string if it was generated
				if (!empty($last_updated_string)) : ?>
				    <div class="festival-wire-last-updated">
				        <?php echo esc_html($last_updated_string); ?>
				    </div>
				<?php endif; ?>
				<?php // --- End Display Last Updated Time --- ?>

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

				<!-- Festival Wire FAQ Section -->
				<div class="festival-wire-faq-container">
					<h2 class="faq-section-title">Festival Wire FAQ</h2>
					<div class="faq-accordion">
						
						<div class="faq-item">
							<button class="faq-question" aria-expanded="false" aria-controls="faq-answer-1">What is the Festival Wire?</button>
							<div id="faq-answer-1" class="faq-answer" role="region" aria-labelledby="faq-question-1" hidden>
								<p>The Festival Wire is your go-to source for the latest news, lineup announcements, schedule drops, and official updates directly from music festivals across the globe. We aggregate information to keep you informed in one convenient place.</p>
							</div>
						</div>

						<div class="faq-item">
							<button class="faq-question" aria-expanded="false" aria-controls="faq-answer-2">How does it work?</button>
							<div id="faq-answer-2" class="faq-answer" role="region" aria-labelledby="faq-question-2" hidden>
								<p>Our system automatically monitors official news outlets, festival sources, and online discussions (like Reddit) for updates. This data is then processed using AI to aggregate and summarize the information. While we strive for accuracy, always double-check the official festival website or source for the most current and definitive details.</p>
							</div>
						</div>

						<div class="faq-item">
							<button class="faq-question" aria-expanded="false" aria-controls="faq-answer-3">Is it accurate?</button>
							<div id="faq-answer-3" class="faq-answer" role="region" aria-labelledby="faq-question-3" hidden>
								<p>We include fact-checking steps in our AI aggregation process and manually clean up entries that we notice are incorrect. However, due to the high volume of information and the nature of automated processing, occasional inaccuracies may slip through. If you spot something wrong, please let us know using the "Festival News Tip" form above – it helps us improve!</p>
							</div>
						</div>

						<div class="faq-item">
							<button class="faq-question" aria-expanded="false" aria-controls="faq-answer-4">How often is it updated?</button>
							<div id="faq-answer-4" class="faq-answer" role="region" aria-labelledby="faq-question-4" hidden>
								<p>The Festival Wire is updated multiple times per day as new information becomes available. We aim to bring you news as close to real-time as possible.</p>
							</div>
						</div>

						<div class="faq-item">
							<button class="faq-question" aria-expanded="false" aria-controls="faq-answer-5">How can I follow along?</button>
							<div id="faq-answer-5" class="faq-answer" role="region" aria-labelledby="faq-question-5" hidden>
								<p>Stay plugged in! We share links to Festival Wire updates all day, every day on our social media channels. Follow us on:
									<ul>
										<li><a href="https://x.com/extra_chill" target="_blank" rel="noopener noreferrer">X (formerly Twitter)</a></li>
										<li><a href="https://www.facebook.com/extrachill" target="_blank" rel="noopener noreferrer">Facebook</a></li>
										<li><a href="https://bsky.app/profile/festivalwire.bsky.social" target="_blank" rel="noopener noreferrer">BlueSky</a></li>
									</ul>
								</p>
							</div>
						</div>

						<div class="faq-item">
							<button class="faq-question" aria-expanded="false" aria-controls="faq-answer-6">How do the filters work?</button>
							<div id="faq-answer-6" class="faq-answer" role="region" aria-labelledby="faq-question-6" hidden>
								<p>You can use the dropdown menus at the top of the page to filter the news feed by specific festivals or locations. Select your desired options and click "Apply Filters" to see relevant updates.</p>
							</div>
						</div>
						
						<div class="faq-item">
							<button class="faq-question" aria-expanded="false" aria-controls="faq-answer-7">I heard a rumor! Can I submit a tip?</button>
							<div id="faq-answer-7" class="faq-answer" role="region" aria-labelledby="faq-question-7" hidden>
								<p>Absolutely! We encourage community contributions. Use the "Have a Festival News Tip?" form found further up on this page to share any leads or information you have.</p>
							</div>
						</div>

						<div class="faq-item">
							<button class="faq-question" aria-expanded="false" aria-controls="faq-answer-8">Why isn't [Specific Festival] listed?</button>
							<div id="faq-answer-8" class="faq-answer" role="region" aria-labelledby="faq-question-8" hidden>
								<p>The festivals included depend on the sources our automated system monitors (official sites, news outlets, online discussions). We're continuously working to expand coverage. If a festival isn't listed, it might be because we haven't integrated a reliable source for it yet, or they simply haven't had recent relevant news or announcements detected by our system.</p>
							</div>
						</div>

					</div><!-- .faq-accordion -->
				</div><!-- .festival-wire-faq-container -->

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