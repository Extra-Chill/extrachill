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
	add_filter( 'render_block_data-machine-events/calendar', 'extrachill_events_attach_calendar_analytics' );
}

/**
 * Enqueue single event page styles
 *
 * Loads three CSS files for data_machine_events post type:
 * 1. Theme's single-post.css (post layout and typography)
 * 2. Theme's sidebar.css (sidebar styling)
 * 3. Plugin's single-event.css (event-specific card treatment and action buttons)
 */
function extrachill_events_enqueue_single_styles() {
	if ( ! is_singular( 'data_machine_events' ) ) {
		return;
	}

	$theme_dir       = get_template_directory();
	$theme_uri       = get_template_directory_uri();
	$single_post_css = $theme_dir . '/assets/css/single-post.css';

	if ( file_exists( $single_post_css ) ) {
		wp_enqueue_style(
			'extrachill-single-post',
			$theme_uri . '/assets/css/single-post.css',
			array( 'extrachill-style' ),
			(string) filemtime( $single_post_css )
		);
	}

	$sidebar_css = $theme_dir . '/assets/css/sidebar.css';

	if ( file_exists( $sidebar_css ) ) {
		wp_enqueue_style(
			'extrachill-sidebar',
			$theme_uri . '/assets/css/sidebar.css',
			array( 'extrachill-root', 'extrachill-style' ),
			(string) filemtime( $sidebar_css )
		);
	}

	$single_event_css = EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/single-event.css';

	if ( file_exists( $single_event_css ) ) {
		wp_enqueue_style(
			'extrachill-events-single',
			EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/single-event.css',
			array( 'extrachill-style' ),
			(string) filemtime( $single_event_css )
		);
	}
}

/**
 * Enqueue calendar styles for events homepage
 *
 * Only loads on blog ID 7 (events.extrachill.com) homepage and taxonomy pages.
 */
function extrachill_events_enqueue_calendar_styles() {
	$events_blog_id = ec_get_blog_id( 'events' );
	$is_router_page = function_exists( 'extrachill_events_is_router_page' ) && extrachill_events_is_router_page();
	if ( ! $events_blog_id || get_current_blog_id() !== $events_blog_id || ( ! is_front_page() && ! is_tax() && ! $is_router_page ) ) {
		return;
	}

	$calendar_css = EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/calendar.css';

	if ( file_exists( $calendar_css ) ) {
		wp_enqueue_style(
			'extrachill-events-calendar',
			EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/calendar.css',
			array( 'extrachill-style' ),
			(string) filemtime( $calendar_css )
		);
	}
}

/**
 * Attach analytics only on pages that actually render a calendar block.
 *
 * @param string $html Rendered calendar markup.
 * @return string Calendar markup with the Filter CTA labeled for analytics.
 */
function extrachill_events_attach_calendar_analytics( string $html ): string {
	if ( is_admin() || is_preview() || ! function_exists( 'extrachill_analytics_beacon_url' ) ) {
		return $html;
	}

	$handle = 'extrachill-events-calendar-analytics';
	if ( ! wp_script_is( $handle, 'enqueued' ) ) {
		$asset = 'assets/js/calendar-analytics.js';
		wp_enqueue_script( $handle, EXTRACHILL_EVENTS_PLUGIN_URL . $asset, array(), (string) filemtime( EXTRACHILL_EVENTS_PLUGIN_DIR . $asset ), true );
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path        = function_exists( 'extrachill_analytics_normalize_route_path' ) ? extrachill_analytics_normalize_route_path( $request_uri ) : '';
		wp_localize_script(
			$handle,
			'ecCalendarAnalytics',
			array(
				'endpoint' => extrachill_analytics_beacon_url( 'wp-abilities/v1/abilities/extrachill/track-calendar-query/run' ),
				'route'    => '' !== $path && function_exists( 'extrachill_analytics_classify_current_route' ) ? extrachill_analytics_classify_current_route( $path ) : 'other',
			)
		);
	}

	// Upstream owns the modal; label its opener for the existing delegated CTA tracker.
	$tags = new WP_HTML_Tag_Processor( $html );
	while ( $tags->next_tag( array(
		'tag_name'   => 'BUTTON',
		'class_name' => 'data-machine-taxonomy-modal-trigger',
	) ) ) {
		if ( null === $tags->get_attribute( 'data-ec-track' ) ) {
			$tags->set_attribute( 'data-ec-track', 'calendar-filter-open' );
		}
	}
	return $tags->get_updated_html();
}
