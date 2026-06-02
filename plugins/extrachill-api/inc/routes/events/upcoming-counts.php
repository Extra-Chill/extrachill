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
 * switch_to_blog() changes only the DB context — it does not load the
 * extrachill-events plugin code, so the ability is registered only when this
 * request actually runs on a site where extrachill-events is active. We
 * therefore guard with wp_has_ability() (a notice-free registry check) before
 * calling wp_get_ability(), which otherwise emits a core "ability not found"
 * notice on every cross-site / internal dispatch (see issues #86, #171).
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
		// Guard with wp_has_ability() before resolving. switch_to_blog() only
		// changes the DB context — it does NOT load extrachill-events' code, so
		// the ability is registered only when this request runs on a site where
		// extrachill-events is active. wp_get_ability() emits a core
		// "_doing_it_wrong" notice whenever the ability is absent; wp_has_ability()
		// performs the same registry check without the notice. This keeps stray
		// cross-site / internal dispatches (e.g. rest_do_request from the cache
		// warmer) from polluting the log and tripping headers-already-sent.
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'extrachill/events-upcoming-counts' ) ) {
			return new WP_Error( 'ability_not_found', 'extrachill-events plugin is required.', array( 'status' => 500 ) );
		}

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
