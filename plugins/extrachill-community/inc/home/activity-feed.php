<?php
/**
 * Homepage Activity Feed ("What's Happening")
 *
 * Feed-first homepage hero. Renders the most recently active topics as a
 * stream of topic cards — the human pulse of the community — rather than a
 * forum-index directory table.
 *
 * Reuses the canonical topic-card partial (bbpress/loop-single-topic-card.php),
 * which already renders upvotes, faces, location/taxonomy badges (#57), and
 * freshness. No new feed engine: this is a richer, ordered preview of the same
 * activity surfaced on the /recent page.
 *
 * Loaded via the extrachill_community_home_top action hook
 * (registered in inc/home/actions.php).
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'extrachill_community_render_activity_feed' ) ) {
	/**
	 * Number of items in the homepage hero feed.
	 *
	 * Filterable so a later phase (cross-network feed, #53 Phase 3) can tune
	 * volume without touching the template.
	 *
	 * @return int
	 */
	function extrachill_community_activity_feed_count() {
		return (int) apply_filters( 'extrachill_community_activity_feed_count', 8 );
	}

	/**
	 * Build the WP_Query for the homepage hero feed.
	 *
	 * Mirrors the activity shape used by the /recent feed and the existing
	 * recently-active component: topics ordered by last-active time, newest
	 * first. Topics (not replies) keep each conversation represented once in
	 * the hero, which reads better than a chronological reply stream for a
	 * low-volume community.
	 *
	 * @return WP_Query
	 */
	function extrachill_community_get_activity_feed_query() {
		$query_args = array(
			'post_type'              => bbp_get_topic_post_type(),
			'posts_per_page'         => extrachill_community_activity_feed_count(),
			'post_status'            => 'publish',
			'orderby'                => 'meta_value',
			'meta_key'               => '_bbp_last_active_time',
			'meta_type'              => 'DATETIME',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => true,
			'update_post_meta_cache' => true,
		);

		return new WP_Query( $query_args );
	}

	/**
	 * Render the homepage hero activity feed.
	 *
	 * Hooked to extrachill_community_home_top.
	 */
	function extrachill_community_render_activity_feed() {
		if ( ! function_exists( 'bbp_get_topic_post_type' ) || ! function_exists( 'bbpress' ) ) {
			return;
		}

		$query = extrachill_community_get_activity_feed_query();
		?>
		<section class="community-activity-feed" aria-labelledby="community-activity-feed-heading">
			<div class="community-section-header">
				<h2 id="community-activity-feed-heading"><?php esc_html_e( "What's Happening", 'extra-chill-community' ); ?></h2>
			</div>
			<div class="community-activity-feed-list ec-mobile-full-width-panel">
				<div class="bbp-body">
					<?php
					if ( $query->have_posts() ) :
						while ( $query->have_posts() ) :
							$query->the_post();
							$topic_id = get_the_ID();
							require EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'bbpress/loop-single-topic-card.php';
						endwhile;

						wp_reset_postdata();
					else :
						?>
						<p class="community-activity-feed-empty"><?php esc_html_e( 'No activity yet — start the first conversation.', 'extra-chill-community' ); ?></p>
						<?php
					endif;
					?>
				</div>
			</div>
			<div class="community-section-header community-activity-feed-footer">
				<div class="view-all-users-link">
					<a href="<?php echo esc_url( home_url( '/recent' ) ); ?>" class="button-3 button-medium">
						<?php esc_html_e( 'View all activity', 'extra-chill-community' ); ?>
					</a>
				</div>
			</div>
		</section>
		<?php
	}
}
