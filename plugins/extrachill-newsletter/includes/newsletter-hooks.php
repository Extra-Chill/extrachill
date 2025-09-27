<?php
/**
 * Newsletter Hook Functions
 *
 * Provides hook-based newsletter display functions for integration
 * with the ExtraChill theme's action-based architecture.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sidebar Recent Newsletters Hook Function
 *
 * Clean hook-based function for displaying recent newsletters in sidebar.
 * Outputs HTML directly for use with theme action hooks.
 *
 * @since 1.0.0
 */
if ( ! function_exists( 'extrachill_sidebar_recent_newsletters' ) ) :
	function extrachill_sidebar_recent_newsletters() {
		// Query for recent newsletters
		$newsletter_args = array(
			'post_type' => 'newsletter',
			'posts_per_page' => 3,
			'post_status' => 'publish',
			'orderby' => 'date',
			'order' => 'DESC',
		);

		$newsletter_query = new WP_Query($newsletter_args);

		if ($newsletter_query->have_posts()) {
			echo '<div class="sidebar-card">';
			echo '<div class="recent-newsletters-widget">';
			echo '<h3 class="widget-title"><span>Recent Newsletters</span></h3>';
			echo '<ul class="recent-newsletters-list">';

			while ($newsletter_query->have_posts()) {
				$newsletter_query->the_post();
				echo '<li>';
				echo '<a href="' . esc_url(get_permalink()) . '" class="newsletter-link">';
				echo '<strong>' . esc_html(get_the_title()) . '</strong>';
				echo '<span class="newsletter-date">' . esc_html(get_the_date()) . '</span>';
				echo '</a>';
				echo '</li>';
			}

			echo '</ul>';

			// View all link
			$newsletter_archive_url = get_post_type_archive_link('newsletter');
			if ($newsletter_archive_url) {
				echo '<div class="view-all-newsletters">';
				echo '<a href="' . esc_url($newsletter_archive_url) . '" class="view-all-link">';
				echo esc_html__('View All Newsletters', 'extrachill-newsletter') . ' →';
				echo '</a>';
				echo '</div>';
			}

			echo '</div>';
			echo '</div>';
		}

		// Reset post data
		wp_reset_postdata();
	}
endif;

// Hook into sidebar bottom
add_action( 'extrachill_sidebar_bottom', 'extrachill_sidebar_recent_newsletters', 10 );