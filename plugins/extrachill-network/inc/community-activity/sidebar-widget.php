<?php
/**
 * Community Activity Sidebar Widget
 *
 * Displays recent community activity in the sidebar via theme hook.
 *
 * @package ExtraChill\Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'extrachill_network_community_activity_widget' ) ) :
	/**
	 * Render community activity sidebar widget.
	 *
	 * @return void
	 */
	function extrachill_network_community_activity_widget() {
		if ( ! function_exists( 'extrachill_get_community_activity_items' ) ||
			! function_exists( 'extrachill_render_community_activity' ) ||
			! function_exists( 'ec_get_site_url' ) ) {
			return;
		}

		$activities = extrachill_get_community_activity_items( 5 );

		if ( empty( $activities ) ) {
			return;
		}

		echo '<div class="sidebar-card ec-surface-card">';
		echo '<div class="widget extrachill-recent-activity-widget">';
		echo '<h3 class="widget-title"><span>' . esc_html__( 'Community Activity', 'extrachill-network' ) . '</span></h3>';
		echo '<div class="extrachill-recent-activity">';

		extrachill_render_community_activity(
			array(
				'render_wrapper' => false,
				'item_class'     => 'sidebar-activity-card',
				'empty_class'    => 'sidebar-activity-card sidebar-activity-empty',
				'limit'          => 5,
				'items'          => $activities,
			)
		);

		echo '</div>';
		echo '<div class="widget-button-wrapper">';
		echo '<a href="' . esc_url( ec_get_site_url( 'community' ) . '/recent' ) . '" class="button-2 button-medium">' . esc_html__( 'View All', 'extrachill-network' ) . '</a>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}
endif;

// Hook into sidebar middle.
add_action( 'extrachill_sidebar_middle', 'extrachill_network_community_activity_widget', 10 );
