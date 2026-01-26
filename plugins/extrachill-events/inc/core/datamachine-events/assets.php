<?php
/**
 * Assets
 *
 * Enqueue CSS for single event pages and calendar pages.
 *
 * @package ExtraChillEvents
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize asset hooks
 */
function extrachill_events_init_assets() {
	add_action( 'wp_enqueue_scripts', 'extrachill_events_enqueue_single_styles' );
	add_action( 'wp_enqueue_scripts', 'extrachill_events_enqueue_calendar_styles' );
}

/**
 * Enqueue single event page styles
 *
 * Loads three CSS files for datamachine_events post type:
 * 1. Theme's single-post.css (post layout and typography)
 * 2. Theme's sidebar.css (sidebar styling)
 * 3. Plugin's single-event.css (event-specific card treatment and action buttons)
 */
function extrachill_events_enqueue_single_styles() {
	if ( ! is_singular( 'datamachine_events' ) ) {
		return;
	}

	$theme_dir = get_template_directory();
	$theme_uri = get_template_directory_uri();
	$single_post_css = $theme_dir . '/assets/css/single-post.css';

	if ( file_exists( $single_post_css ) ) {
		wp_enqueue_style(
			'extrachill-single-post',
			$theme_uri . '/assets/css/single-post.css',
			array( 'extrachill-style' ),
			filemtime( $single_post_css )
		);
	}

	$sidebar_css = $theme_dir . '/assets/css/sidebar.css';

	if ( file_exists( $sidebar_css ) ) {
		wp_enqueue_style(
			'extrachill-sidebar',
			$theme_uri . '/assets/css/sidebar.css',
			array( 'extrachill-root', 'extrachill-style' ),
			filemtime( $sidebar_css )
		);
	}

	$single_event_css = EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/single-event.css';

	if ( file_exists( $single_event_css ) ) {
		wp_enqueue_style(
			'extrachill-events-single',
			EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/single-event.css',
			array( 'extrachill-style' ),
			filemtime( $single_event_css )
		);
	}
}

/**
 * Enqueue calendar styles for events homepage
 *
 * Only loads on blog ID 7 (events.extrachill.com) homepage and taxonomy pages.
 */
function extrachill_events_enqueue_calendar_styles() {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
	if ( ! $events_blog_id || get_current_blog_id() !== $events_blog_id || ( ! is_front_page() && ! is_tax() ) ) {
		return;
	}

	$calendar_css = EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/calendar.css';

	if ( file_exists( $calendar_css ) ) {
		wp_enqueue_style(
			'extrachill-events-calendar',
			EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/calendar.css',
			array( 'extrachill-style' ),
			filemtime( $calendar_css )
		);
	}
}
