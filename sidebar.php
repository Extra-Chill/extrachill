<?php
/**
 * The Sidebar containing the main widget areas.
 *
 * @package ThemeGrill
 * @subpackage ColorMag
 * @since ColorMag 1.0
 */
?>

<aside id="secondary">
	<?php do_action( 'extrachill_before_sidebar' ); ?>

		<?php
		// Display Recent Posts directly
		echo '<div class="widget my-recent-posts-widget">'; // Added a specific class
		if ( function_exists( 'my_recent_posts_shortcode' ) ) {
			echo my_recent_posts_shortcode();
		} else {
			// Optional: Add a fallback message or error handling
			echo '<p>Recent posts are currently unavailable.</p>';
		}
		echo '</div>';

		// Display Community Activity Feed directly
		echo '<div class="widget extrachill-recent-activity-widget">'; // Added a specific class
		echo '<h3 class="widget-title"><span>Community Activity</span></h3>'; // Add the title here
		if ( function_exists( 'extrachill_recent_activity_shortcode' ) ) {
			echo extrachill_recent_activity_shortcode();
		} else {
			// Optional: Add a fallback message or error handling
			echo '<p>Community activity feed is currently unavailable.</p>';
		}
		echo '</div>';

		// Display Recent Newsletters directly
		echo '<div class="widget recent-newsletters-widget">'; // Added a specific class
		if ( function_exists( 'recent_newsletters_shortcode' ) ) {
			echo recent_newsletters_shortcode();
		} else {
			// Optional: Add a fallback message or error handling
			echo '<p>Recent newsletters are currently unavailable.</p>';
		}
		echo '</div>';
		?>

	<?php do_action( 'extrachill_after_sidebar' ); ?>
</aside>
