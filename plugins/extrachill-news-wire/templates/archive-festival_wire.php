<?php
/**
 * The template for displaying archive pages for the Festival Wire CPT.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package ExtraChillNewsWire
 * @since 0.1.0
 */

get_header(); ?>

	<div class="main-content festival-wire-page">
		<main id="main" class="site-main" role="main">

			<?php
			// Display breadcrumbs
			extrachill_breadcrumbs();

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
											// Get festival filter options
											$festivals = get_terms(array('taxonomy' => 'festival', 'hide_empty' => true));
											$festival_data = array();
											if (!is_wp_error($festivals)) {
												foreach ($festivals as $festival) {
													$festival_data[] = array('slug' => $festival->slug, 'name' => $festival->name);
												}
											}
											
											if (!empty($festival_data)) {
												foreach ($festival_data as $festival) {
													echo '<option value="' . esc_attr($festival['slug']) . '">' . esc_html($festival['name']) . '</option>';
												}
											}
											?>
										</select>
									</div>
								</div>
								
								<div class="filter-group">
									<div class="filter-input">
										<?php
										// Get location filter options
										$locations = get_terms(array('taxonomy' => 'location', 'hide_empty' => true));
										if (!is_wp_error($locations) && !empty($locations)) {
											echo '<select id="location-filter" class="location-dropdown">';
											echo '<option value="all">All Locations</option>';
											foreach ($locations as $location) {
												echo '<option value="' . esc_attr($location->slug) . '">' . esc_html($location->name) . '</option>';
											}
											echo '</select>';
										} else {
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
				$latest_post = get_posts(array('numberposts' => 1, 'post_type' => 'festival_wire', 'orderby' => 'modified', 'order' => 'DESC'));
				$last_updated_string = '';
				
				if (!empty($latest_post)) {
					$last_updated_string = 'Last updated: ' . get_the_modified_date('F j, Y \a\t g:i A', $latest_post[0]->ID);
				}

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
						// Use plugin's content card
						require __DIR__ . '/content-card.php';
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

                <?php extrachill_pagination( null, 'festival-wire', 'post' ); ?>

				<!-- Music Festivals Forum CTA -->
				<div class="forum-cta-container">
					<h2 class="forum-cta-title">Join the Discussion!</h2>
					<p class="forum-cta-description">Chat with fellow festival fans, share your experiences, and get real-time updates in our Music Festivals forum.</p>
					<a href="<?php echo esc_url('https://community.extrachill.com/r/music-discussion/music-festivals'); ?>" class="forum-cta-link button" target="_blank" rel="noopener noreferrer">Visit the Forum</a>
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
						<p>We include fact-checking steps in our AI aggregation process and manually clean up entries that we notice are incorrect. However, due to the high volume of information and the nature of automated processing, occasional inaccuracies may slip through. If you spot something wrong, post in the Music Festivals forum or contact us directly so we can verify and update the entry.</p>
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
						<button class="faq-question" aria-expanded="false" aria-controls="faq-answer-7">How do I share corrections or leads?</button>
						<div id="faq-answer-7" class="faq-answer" role="region" aria-labelledby="faq-question-7" hidden>
							<p>Use the Music Festivals forum or contact the editorial team directly if you spot something that needs attention. We monitor community reports and update the Festival Wire accordingly.</p>
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
	</div><!-- .main-content -->

<?php 
// Sidebar removed as per requirements
// get_sidebar(); 
?>
<?php get_footer(); ?>