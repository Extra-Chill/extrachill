<?php
/**
 * Upcoming Event Counts Endpoint
 *
 * Returns counts of upcoming events (date >= today) for taxonomy terms.
 * Route affinity middleware ensures this runs on the events site.
 *
 * The heavy SQL query result is cached in a transient (6hr TTL) and
 * pre-warmed by the cron warmer in extrachill-multisite.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_events_upcoming_counts_route' );

/**
 * Register the upcoming counts endpoint.
 */
function extrachill_api_register_events_upcoming_counts_route() {
	register_rest_route(
		'extrachill/v1',
		'/events/upcoming-counts',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_events_upcoming_counts_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'taxonomy'      => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array( 'venue', 'location', 'artist', 'festival' ),
					'description'       => 'Taxonomy to query: venue, location, artist, or festival',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'slug'          => array(
					'required'          => false,
					'type'              => 'string',
					'description'       => 'Specific term slug. If provided, returns single term data.',
					'sanitize_callback' => 'sanitize_title',
				),
				'location_slug' => array(
					'required'          => false,
					'type'              => 'string',
					'description'       => 'Optional location term slug to scope bulk venue counts to a single city. Only applied when taxonomy is "venue".',
					'sanitize_callback' => 'sanitize_title',
				),
				'limit'         => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'minimum'           => 0,
					'description'       => 'Max terms to return for bulk queries. 0 = unlimited (default).',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Handle upcoming counts request.
 *
 * Invokes the extrachill/events-upcoming-counts ability (registered in extrachill-events,
 * which is active only on the events site). Because this REST route is network-wide, it can
 * be called from any site in the network. To reach the events-registered ability — and the
 * events-site taxonomy/post tables it reads — we switch to the events blog before resolving
 * and executing the ability, then always restore the original context.
 *
 * Switching before wp_get_ability() also prevents the WP core "ability not found" notice that
 * would otherwise be emitted on every cross-site call (see issue #86).
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_events_upcoming_counts_handler( WP_REST_Request $request ) {
	$input = array(
		'taxonomy' => $request->get_param( 'taxonomy' ),
		'limit'    => (int) $request->get_param( 'limit' ),
	);

	$slug = $request->get_param( 'slug' );
	if ( is_string( $slug ) && '' !== $slug ) {
		$input['slug'] = $slug;
	}

	$location_slug = $request->get_param( 'location_slug' );
	if ( is_string( $location_slug ) && '' !== $location_slug ) {
		$input['location_slug'] = $location_slug;
	}

	// Resolve the events blog. The ability and its data live on the events site.
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 0;
	$current_blog   = get_current_blog_id();
	$did_switch     = false;

	// Only switch when we can resolve the events blog and we're not already on it.
	if ( $events_blog_id > 0 && $events_blog_id !== $current_blog ) {
		switch_to_blog( $events_blog_id );
		$did_switch = true;
	}

	try {
		$ability = wp_get_ability( 'extrachill/events-upcoming-counts' );
		if ( ! $ability ) {
			return new WP_Error( 'ability_not_found', 'extrachill-events plugin is required.', array( 'status' => 500 ) );
		}

		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	} finally {
		if ( $did_switch ) {
			restore_current_blog();
		}
	}
}
