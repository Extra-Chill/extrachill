<?php
/*
 * Template Name: Recent Activity Feed
 * Description: A page template to show the most recent replies across all forums in a Twitter-like stream.
 *
 * This template owns ONLY the global /recent activity page. It is intentionally
 * forum-scoped (topics + replies across all forums) and NOT cross-network — see
 * epic #53 Phase 3. The user-scoped profile activity feed lives in its own path
 * (inc/user-profiles/profile-activity-feed.php), so this page can be changed or
 * retired without touching profiles.
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

// Global recent activity feed (unscoped, forum-wide).
$recent_feed = extrachill_get_recent_feed_query( 15 );
extrachill_render_recent_feed( $recent_feed );

get_footer();
