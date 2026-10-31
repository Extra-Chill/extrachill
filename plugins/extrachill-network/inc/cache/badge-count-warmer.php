<?php
/**
 * Badge Count Cache Warmer
 *
 * Pre-computes taxonomy badge count transients so no homepage visitor ever
 * hits a cold cache. Runs every 4 hours on each site via WP-Cron.
 *
 * Since extrachill-network is network-activated, this cron fires on every
 * site natively, writing transients in the correct Redis namespace. Each
 * site only warms the transients it needs.
 *
 * @package ExtraChillNetwork
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'cron_schedules', 'ec_badge_warmer_schedule' );
add_action( 'init', 'ec_badge_warmer_register_cron' );
add_action( 'ec_warm_badge_counts', 'ec_badge_warmer_run' );

/**
 * Add 4-hour cron interval.
 */
function ec_badge_warmer_schedule( $schedules ) {
	$schedules['every_four_hours'] = array(
		'interval' => 4 * HOUR_IN_SECONDS,
		'display'  => __( 'Every 4 Hours', 'extrachill-network' ),
	);
	return $schedules;
}

/**
 * Schedule the cron event if not already scheduled.
 */
function ec_badge_warmer_register_cron() {
	if ( ! wp_next_scheduled( 'ec_warm_badge_counts' ) ) {
		wp_schedule_event( time(), 'every_four_hours', 'ec_warm_badge_counts' );
	}
}

/**
 * Run the warmer for the current site.
 *
 * Checks which site we're on and warms only the relevant transients.
 *
 * @return array List of warmed cache descriptions.
 */
function ec_badge_warmer_run() {
	$blog_id = get_current_blog_id();
	$warmed  = array();

	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
	$main_blog_id   = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;

	if ( $blog_id === $events_blog_id ) {
		$warmed = ec_badge_warmer_warm_events_site();
	} elseif ( $blog_id === $main_blog_id ) {
		$warmed = ec_badge_warmer_warm_blog_site();
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		foreach ( $warmed as $item ) {
			\WP_CLI::log( "  Warmed: {$item}" );
		}
	}

	return $warmed;
}

/**
 * Warm transients on events.extrachill.com.
 *
 * Calls the data-machine-events/get-upcoming-counts ability directly
 * (data-machine-events is active on this site).
 *
 * @return array List of warmed descriptions.
 */
function ec_badge_warmer_warm_events_site() {
	$warmed    = array();
	$cache_ttl = 6 * HOUR_IN_SECONDS;

	$ability = wp_get_ability( 'data-machine-events/get-upcoming-counts' );

	if ( $ability ) {
		$taxonomies = array( 'location', 'venue', 'artist', 'festival' );

		foreach ( $taxonomies as $taxonomy ) {
			$result = $ability->execute( array( 'taxonomy' => $taxonomy ) );
			$terms  = ( ! is_wp_error( $result ) && ! empty( $result['terms'] ) )
				? $result['terms']
				: array();

			set_transient( 'ec_upcoming_counts_' . $taxonomy, $terms, $cache_ttl );
			$warmed[] = "events/{$taxonomy}: " . count( $terms ) . ' terms';
		}
	}

	// Calendar stats.
	if ( function_exists( 'extrachill_events_get_calendar_stats' ) ) {
		delete_transient( 'extrachill_calendar_stats' );
		extrachill_events_get_calendar_stats();
		$warmed[] = 'events/calendar-stats';
	}

	return $warmed;
}

/**
 * Warm transients on extrachill.com.
 *
 * Calls the REST endpoints via rest_do_request() which routes through
 * extrachill-api's switch_to_blog pattern. This writes transients in
 * the main site's Redis namespace so they're available on next homepage load.
 *
 * @return array List of warmed descriptions.
 */
function ec_badge_warmer_warm_blog_site() {
	$warmed = array();

	// Location event counts (from events site).
	$request = new WP_REST_Request( 'GET', '/extrachill/v1/events/upcoming-counts' );
	$request->set_query_params( array( 'taxonomy' => 'location' ) );
	$response = rest_do_request( $request );

	if ( ! $response->is_error() ) {
		$data     = $response->get_data();
		$warmed[] = 'blog/location-events: ' . ( is_array( $data ) ? count( $data ) : 0 ) . ' terms';
	}

	// Festival wire counts (from wire site).
	$request2 = new WP_REST_Request( 'GET', '/extrachill/v1/wire/taxonomy-counts' );
	$request2->set_query_params( array( 'taxonomy' => 'festival' ) );
	$response2 = rest_do_request( $request2 );

	if ( ! $response2->is_error() ) {
		$data2    = $response2->get_data();
		$warmed[] = 'blog/wire-festivals: ' . ( is_array( $data2 ) ? count( $data2 ) : 0 ) . ' terms';
	}

	return $warmed;
}
