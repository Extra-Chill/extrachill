<?php
/**
 * Events Breadcrumb Integration
 *
 * Integrates with theme's breadcrumb system to provide events-specific
 * breadcrumbs with "Extra Chill → Events" root link.
 *
 * @package ExtraChillEvents
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Change breadcrumb root to "Extra Chill → Events" on events pages
 *
 * Uses theme's extrachill_breadcrumbs_root filter to override the root link.
 * Only applies on blog ID 7 (events.extrachill.com).
 *
 * @param string $root_link Default root breadcrumb link HTML
 * @return string Modified root link
 * @since 1.0.0
 */
function ec_events_breadcrumb_root( $root_link ) {
	// Only apply on events.extrachill.com (blog ID 7)
	if ( get_current_blog_id() !== 7 ) {
		return $root_link;
	}

	// On homepage, just "Extra Chill" (trail will add "Events")
	if ( is_front_page() ) {
		return '<a href="https://extrachill.com">Extra Chill</a>';
	}

	// On other pages, include "Events" in root
	return '<a href="https://extrachill.com">Extra Chill</a> › <a href="' . esc_url( home_url() ) . '">Events</a>';
}
add_filter( 'extrachill_breadcrumbs_root', 'ec_events_breadcrumb_root' );

/**
 * Override breadcrumb trail for events homepage
 *
 * Displays just "Events" (no link) on the homepage to prevent "Archives" suffix.
 *
 * @param string $custom_trail Existing custom trail from other plugins
 * @return string Breadcrumb trail HTML
 * @since 1.0.0
 */
function ec_events_breadcrumb_trail_homepage( $custom_trail ) {
	// Only apply on events.extrachill.com (blog ID 7)
	if ( get_current_blog_id() !== 7 ) {
		return $custom_trail;
	}

	// Only on front page (homepage)
	if ( is_front_page() ) {
		return '<span>Events</span>';
	}

	return $custom_trail;
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'ec_events_breadcrumb_trail_homepage' );
