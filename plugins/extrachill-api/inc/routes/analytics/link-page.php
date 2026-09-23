<?php
/**
 * REST route: GET /wp-json/extrachill/v1/analytics/link-page
 *
 * Authenticated endpoint for fetching aggregated analytics data for link pages.
 * Delegates to filter hook for data retrieval from artist-platform plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_link_page_analytics_route' );

function extrachill_api_register_link_page_analytics_route() {
	register_rest_route(
		'extrachill/v1',
		'/analytics/link-page',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_link_page_analytics_handler',
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'link_page_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && $param > 0;
					},
					'sanitize_callback' => 'absint',
				),
				'date_range'   => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 30,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Whether an ID is a Link Page, read from the Link Pages storage site.
 *
 * The runtime owns both the storage site and the post type for that site
 * (extrachill-link-pages#34), so this adapter asks rather than assuming a
 * blog or a literal. Falls back to the legacy current-blog check when the
 * runtime is unavailable, which is exactly the pre-cutover behaviour.
 *
 * @param int $link_page_id Link Page ID.
 * @return bool
 */
function extrachill_api_is_link_page( $link_page_id ) {
	$link_page_id = absint( $link_page_id );
	if ( ! $link_page_id ) {
		return false;
	}

	if ( ! function_exists( 'ec_link_page_post_type' ) || ! function_exists( 'ec_with_link_page_storage_blog' ) ) {
		return 'artist_link_page' === get_post_type( $link_page_id );
	}

	// The callback receives the storage blog ID; resolve the type for exactly
	// that site. A storage failure comes back as a WP_Error, which the strict
	// comparison below treats as "not a Link Page" (fail closed).
	$matches = ec_with_link_page_storage_blog(
		static function ( $storage_blog_id ) use ( $link_page_id ) {
			return ec_link_page_post_type( $storage_blog_id ) === get_post_type( $link_page_id );
		}
	);

	return true === $matches;
}

/**
 * Handles link page analytics requests
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_link_page_analytics_handler( WP_REST_Request $request ) {
	$link_page_id = $request->get_param( 'link_page_id' );
	$date_range   = $request->get_param( 'date_range' );

	// Validate link page exists and is correct post type. Read the post from the
	// Link Pages storage site: after the site cutover the record lives on the
	// dedicated Link Pages blog, not the blog serving this REST request, and
	// its type follows that site (extrachill-link-pages#34).
	if ( ! extrachill_api_is_link_page( $link_page_id ) ) {
		return new WP_Error(
			'invalid_link_page',
			__( 'Invalid link page specified.', 'extrachill-api' ),
			array( 'status' => 400 )
		);
	}

	// Get associated artist ID
	$artist_id = apply_filters( 'ec_get_artist_id', $link_page_id );
	if ( ! $artist_id ) {
		return new WP_Error(
			'artist_not_found',
			__( 'Could not determine associated artist.', 'extrachill-api' ),
			array( 'status' => 400 )
		);
	}

	// Check permission
	if ( ! function_exists( 'ec_can_manage_artist' ) || ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
		return new WP_Error(
			'permission_denied',
			__( 'You do not have permission to view analytics for this link page.', 'extrachill-api' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Fires when link page analytics are requested.
	 *
	 * Handlers should return analytics data array on success, or WP_Error on failure.
	 *
	 * @param mixed $result        Previous filter result (null if no handler has run).
	 * @param int   $link_page_id  The link page post ID.
	 * @param int   $date_range    Number of days to query.
	 */
	$result = apply_filters( 'extrachill_get_link_page_analytics', null, $link_page_id, $date_range );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_array( $result ) ) {
		return new WP_Error(
			'analytics_unavailable',
			__( 'Analytics data could not be retrieved.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response( $result );
}
