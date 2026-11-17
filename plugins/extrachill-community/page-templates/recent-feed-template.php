<?php
/*
 * Template Name: Recent Activity Feed
 * Description: The most recent activity across the network in a Twitter-like stream.
 *
 * This template owns ONLY the global /recent activity page. It renders the one
 * activity feed (extrachill_build_activity_feed() in inc/content/recent-feed.php):
 * forum topics and replies plus every source registered on
 * `ec_activity_feed_sources`, such as network comments on articles and events,
 * merged by date. The profile "Recent Conversations" list lives in its own
 * path (inc/user-profiles/profile-activity-feed.php).
 */

get_header();
?>
<?php extrachill_breadcrumbs(); ?>

<?php
echo '<div class="community-section-header"><h1>' . esc_html__( 'Recent Activity', 'extra-chill-community' ) . '</h1></div>';

// Output the standard WordPress content within the div
if ( have_posts() ) :
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
endif;

// Global recent activity feed (unscoped, every registered source).
$recent_feed = extrachill_get_recent_feed_query( 15 );
extrachill_render_recent_feed( $recent_feed );

get_footer();
