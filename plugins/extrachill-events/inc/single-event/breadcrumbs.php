<?php
/**
 * Single Event Breadcrumbs
 *
 * Handles breadcrumb overrides for single event pages.
 *
 * @package ExtraChillEvents
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Override datamachine-events breadcrumbs with theme breadcrumb system
 *
 * Replaces datamachine-events breadcrumbs with theme's extrachill_breadcrumbs() function
 * for consistent breadcrumb styling across site.
 *
 * @hook datamachine_events_breadcrumbs
 * @param string|null $breadcrumbs Plugin's default breadcrumb HTML
 * @param int $post_id Event post ID
 * @return string Theme breadcrumb HTML
 * @since 0.1.0
 */
function ec_events_override_breadcrumbs( $breadcrumbs, $post_id ) {
	if ( function_exists( 'extrachill_breadcrumbs' ) ) {
		ob_start();
		extrachill_breadcrumbs();
		return ob_get_clean();
	}
	return $breadcrumbs;
}
add_filter( 'datamachine_events_breadcrumbs', 'ec_events_override_breadcrumbs', 10, 2 );

/**
 * Customize breadcrumb root for events site
 *
 * Produces "Extra Chill" root on homepage, "Extra Chill → Events" on other pages.
 * Only applies on blog ID 7 (events.extrachill.com).
 *
 * @hook extrachill_breadcrumbs_root
 * @param string $root_link Default root breadcrumb link HTML from theme
 * @return string Modified root link with events context
 * @since 0.1.0
 */
function ec_events_breadcrumb_root( $root_link ) {
	if ( is_front_page() ) {
		$main_site_url = ec_get_site_url( 'main' );
		return '<a href="' . esc_url( $main_site_url ) . '">Extra Chill</a>';
	}

	$main_site_url = ec_get_site_url( 'main' );
	return '<a href="' . esc_url( $main_site_url ) . '">Extra Chill</a> › <a href="' . esc_url( home_url() ) . '">Events Calendar</a>';
}
add_filter( 'extrachill_breadcrumbs_root', 'ec_events_breadcrumb_root' );

/**
 * Override breadcrumb trail for homepage
 *
 * Produces "Events" trail on homepage. Root function provides "Extra Chill" link.
 * Only applies on blog ID 7 (events.extrachill.com) homepage.
 *
 * @hook extrachill_breadcrumbs_override_trail
 * @param string|false $custom_trail Existing custom trail from other filters
 * @return string|false Custom trail for homepage, unchanged otherwise
 * @since 0.1.0
 */
function ec_events_breadcrumb_trail_homepage( $custom_trail ) {
	if ( is_front_page() ) {
		return '<span class="network-dropdown-target">Events Calendar</span>';
	}

	return $custom_trail;
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'ec_events_breadcrumb_trail_homepage' );

/**
 * Override breadcrumb trail for archive pages
 *
 * Produces taxonomy-specific or post type archive trails. Root function provides
 * "Extra Chill → Events" prefix. Only applies on blog ID 7 (events.extrachill.com).
 *
 * Output patterns:
 * - Taxonomy: "Extra Chill → Events → [Term Name]"
 * - Post type: "Extra Chill → Events"
 *
 * @hook extrachill_breadcrumbs_override_trail
 * @param string|false $custom_trail Custom breadcrumb trail from other filters
 * @return string|false Custom trail for archives, unchanged otherwise
 * @since 0.1.0
 */
function ec_events_breadcrumb_trail_archives( $custom_trail ) {
	if ( is_tax() ) {
		$term = get_queried_object();
		if ( $term && isset( $term->name ) ) {
			return '<span>' . esc_html( $term->name ) . '</span>';
		}
	}

	if ( is_post_type_archive( 'datamachine_events' ) ) {
		return '<span class="network-dropdown-target">Events Calendar</span>';
	}

	return $custom_trail;
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'ec_events_breadcrumb_trail_archives' );

/**
 * Override breadcrumb trail for single event posts
 *
 * Produces event title trail. Root function provides "Extra Chill → Events" prefix.
 * Only applies on blog ID 7 (events.extrachill.com).
 *
 * Output pattern: "Extra Chill → Events → [Event Title]"
 *
 * @hook extrachill_breadcrumbs_override_trail
 * @param string|false $custom_trail Custom breadcrumb trail from other filters
 * @return string|false Custom trail for single events, unchanged otherwise
 * @since 0.1.0
 */
function ec_events_breadcrumb_trail_single( $custom_trail ) {
	if ( is_singular( 'datamachine_events' ) ) {
		return '<span class="breadcrumb-title">' . get_the_title() . '</span>';
	}

	return $custom_trail;
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'ec_events_breadcrumb_trail_single' );

/**
 * Override back-to-home link label for event pages
 *
 * Produces "Back to Events" on non-homepage pages. Homepage retains default
 * "Back to Extra Chill" label pointing to main site.
 * Only applies on blog ID 7 (events.extrachill.com).
 *
 * @hook extrachill_back_to_home_label
 * @param string $label Default back-to-home link label from theme
 * @param string $url Back-to-home link URL
 * @return string Modified label for event pages, unchanged for homepage
 * @since 0.1.0
 */
function ec_events_back_to_home_label( $label, $url ) {
	if ( is_front_page() ) {
		return $label;
	}

	return '← Back to Events Calendar';
}
add_filter( 'extrachill_back_to_home_label', 'ec_events_back_to_home_label', 10, 2 );

