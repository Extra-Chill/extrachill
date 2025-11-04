<?php
/**
 * Events Breadcrumb Integration
 *
 * Provides "Extra Chill → Events" breadcrumb root for events.extrachill.com (blog ID 7).
 * Homepage shows just "Events", other pages include full trail.
 *
 * @package ExtraChillEvents
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string $root_link Default root breadcrumb link HTML
 * @return string Modified root link
 */
function ec_events_breadcrumb_root( $root_link ) {
	if ( get_current_blog_id() !== 7 ) {
		return $root_link;
	}

	if ( is_front_page() ) {
		return '<a href="https://extrachill.com">Extra Chill</a>';
	}

	return '<a href="https://extrachill.com">Extra Chill</a> › <a href="' . esc_url( home_url() ) . '">Events</a>';
}
add_filter( 'extrachill_breadcrumbs_root', 'ec_events_breadcrumb_root' );

/**
 * @param string $custom_trail Existing custom trail from other plugins
 * @return string Breadcrumb trail HTML
 */
function ec_events_breadcrumb_trail_homepage( $custom_trail ) {
	if ( get_current_blog_id() !== 7 ) {
		return $custom_trail;
	}

	if ( is_front_page() ) {
		return '<span>Events</span>';
	}

	return $custom_trail;
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'ec_events_breadcrumb_trail_homepage' );

/**
 * Override breadcrumb trail for archive pages
 *
 * Provides context-aware breadcrumbs for taxonomy archives and post type archives.
 * Works in conjunction with ec_events_breadcrumb_root() which provides the
 * "Extra Chill › Events" prefix.
 *
 * Breadcrumb patterns:
 * - Homepage: "Events"
 * - Taxonomy archive: "Extra Chill › Events › [Term Name]"
 * - Post type archive: "Extra Chill › Events" (rarely seen due to redirect)
 * - Single event: "Extra Chill › Events › [Event Title]" (unchanged)
 *
 * @hook extrachill_breadcrumbs_override_trail
 * @param string|false $custom_trail Custom breadcrumb trail or false
 * @return string|false Custom trail for archives, or false to use default
 */
function ec_events_breadcrumb_trail_archives( $custom_trail ) {
	if ( get_current_blog_id() !== 7 ) {
		return $custom_trail;
	}

	// Taxonomy archives: Show term name only
	// Root breadcrumb already provides "Extra Chill › Events" via ec_events_breadcrumb_root()
	if ( is_tax() ) {
		$term = get_queried_object();
		if ( $term && isset( $term->name ) ) {
			return '<span>' . esc_html( $term->name ) . '</span>';
		}
	}

	// Post type archive: Show "Events"
	// Shouldn't reach here due to redirect, but handle gracefully
	if ( is_post_type_archive( 'dm_events' ) ) {
		return '<span>Events</span>';
	}

	// Return false to use default theme breadcrumb logic
	return $custom_trail;
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'ec_events_breadcrumb_trail_archives' );

/**
 * Override back-to-home link label for events pages
 *
 * Changes "Back to Extra Chill" to "Back to Events" on events pages.
 * Uses theme's extrachill_back_to_home_label filter.
 * Only applies on blog ID 7 (events.extrachill.com).
 *
 * @param string $label Default back-to-home link label
 * @param string $url   Back-to-home link URL
 * @return string Modified label
 */
function ec_events_back_to_home_label( $label, $url ) {
	// Only apply on events.extrachill.com (blog ID 7)
	if ( get_current_blog_id() !== 7 ) {
		return $label;
	}

	// Don't override on homepage (homepage should say "Back to Extra Chill")
	if ( is_front_page() ) {
		return $label;
	}

	return '← Back to Events';
}
add_filter( 'extrachill_back_to_home_label', 'ec_events_back_to_home_label', 10, 2 );
