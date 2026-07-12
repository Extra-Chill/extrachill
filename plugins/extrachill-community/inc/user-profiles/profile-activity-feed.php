<?php
/**
 * Profile Activity Feed — Recent Conversations
 *
 * Renders the conversations the displayed user is part of (topics they
 * started or replied to) at the bottom of their bbPress profile page.
 *
 * Composition over duplication: reuses the canonical topic-card partial
 * (bbpress/loop-single-topic-card.php) — the same component the homepage
 * "What's Happening" feed composes — instead of the full reply-card renderer.
 * Reply cards showed isolated reply bodies with no surrounding thread, which
 * read as noise out of context; topic cards carry their own context (title,
 * forum origin, stats, freshness) and invite visitors into the threads the
 * user is active in.
 *
 * Engagement comes from bbPress's native `_bbp_engagement` relationship via
 * bbp_get_user_engaged_topic_ids() — maintained by bbPress whenever a user
 * creates a topic or replies to one. No custom aggregation.
 *
 * @package ExtraChillCommunity
 */

// Prevent direct access.
if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * Number of conversations shown on the profile feed.
 *
 * @return int
 */
function extrachill_profile_conversations_count() {
	return (int) apply_filters( 'extrachill_profile_conversations_count', 8 );
}

/**
 * Build the query for a user's recent conversations.
 *
 * Engaged topics (started or replied to), ordered by last-active time so the
 * liveliest threads the user participates in surface first — mirroring the
 * ordering of the homepage activity feed.
 *
 * @param int $user_id User ID.
 * @return WP_Query|null Query of topics, or null when the user has none.
 */
function extrachill_get_profile_conversations_query( $user_id ) {
	$engaged_ids = bbp_get_user_engaged_topic_ids( $user_id );
	if ( empty( $engaged_ids ) ) {
		return null;
	}

	return new WP_Query( array(
		'post_type'      => bbp_get_topic_post_type(),
		'post__in'       => array_map( 'intval', $engaged_ids ),
		'posts_per_page' => extrachill_profile_conversations_count(),
		'post_status'    => 'publish',
		'orderby'        => 'meta_value',
		'meta_key'       => '_bbp_last_active_time',
		'meta_type'      => 'DATETIME',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	) );
}

/**
 * Render the displayed user's recent conversations below their profile.
 *
 * Hooked to bbp_template_after_user_profile at priority 99 so it renders as
 * the very last element on the profile page — after the header, the About /
 * Community Activity / Artists card grid, the heatmap, and concert history.
 * The bbp_is_single_user_profile() guard keeps it on the main profile tab
 * only; the Topics/Replies sub-tabs already carry the exhaustive loops.
 *
 * @return void
 */
function extrachill_render_profile_activity_feed() {
	if ( ! function_exists('bbp_is_single_user_profile') || ! bbp_is_single_user_profile() ) {
		return;
	}

	if ( ! function_exists('bbp_get_user_engaged_topic_ids') ) {
		return;
	}

	$user_id = (int) bbp_get_displayed_user_id();
	if ( $user_id <= 0 ) {
		return;
	}

	$display_name = bbp_get_displayed_user_field('display_name');
	$query        = extrachill_get_profile_conversations_query( $user_id );

	echo '<div class="bbp-user-profile-card user-profile-activity-feed">';
	printf(
		'<h3>%s</h3>',
		esc_html__( 'Recent Conversations', 'extra-chill-community' )
	);

	if ( $query && $query->have_posts() ) {
		// Flag feed context so the topic card shows its forum origin, exactly
		// as it does on /recent and the homepage feed.
		if ( function_exists('extrachill_is_activity_feed_card') ) {
			extrachill_is_activity_feed_card(true);
		}

		echo '<div class="ec-mobile-full-width-panel"><div class="bbp-body">';
		while ( $query->have_posts() ) {
			$query->the_post();
			$topic_id = get_the_ID();
			require EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'bbpress/loop-single-topic-card.php';
		}
		echo '</div></div>';
		wp_reset_postdata();

		if ( function_exists('extrachill_is_activity_feed_card') ) {
			extrachill_is_activity_feed_card(false);
		}
	} else {
		printf(
			'<p class="ec-profile-conversations-empty">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: displayed user's name. */
					__( '%s hasn\'t joined any conversations yet.', 'extra-chill-community' ),
					$display_name
				)
			)
		);
	}

	echo '</div>';
}
// Fires after the entire profile body (bbpress/user-profile.php). Priority 99
// keeps the feed the final element on the page, after the heatmap (1) and
// Concert History (5) which share this hook.
add_action( 'bbp_template_after_user_profile', 'extrachill_render_profile_activity_feed', 99 );
