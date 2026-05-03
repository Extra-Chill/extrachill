<?php
/**
 * Artist Socials REST API Endpoint
 *
 * GET /wp-json/extrachill/v1/artists/{id}/socials - Retrieve social links
 * PUT /wp-json/extrachill/v1/artists/{id}/socials - Update social links (full replacement)
 *
 * Delegates to abilities:
 * - extrachill/save-social-links
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_socials_routes' );

function extrachill_api_register_artist_socials_routes() {
	register_rest_route( 'extrachill/v1', '/artists/(?P<id>\d+)/socials', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_artist_socials_get_handler',
			'permission_callback' => 'extrachill_api_artist_socials_permission_check',
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		),
		array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => 'extrachill_api_artist_socials_put_handler',
			'permission_callback' => 'extrachill_api_artist_socials_permission_check',
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		),
	) );
}

/**
 * Permission check for artist socials endpoint
 */
function extrachill_api_artist_socials_permission_check( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_forbidden',
			'Must be logged in.',
			array( 'status' => 401 )
		);
	}

	$artist_id = $request->get_param( 'id' );

	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error(
			'invalid_artist',
			'Artist not found.',
			array( 'status' => 404 )
		);
	}

	if ( ! function_exists( 'ec_can_manage_artist' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Artist platform not active.',
			array( 'status' => 500 )
		);
	}

	if ( ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
		return new WP_Error(
			'rest_forbidden',
			'Cannot manage this artist.',
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * GET handler - retrieve social links.
 *
 * Builds response directly since there's no dedicated read ability for socials
 * (they're part of the link page data ability).
 */
function extrachill_api_artist_socials_get_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'id' );

	return rest_ensure_response( extrachill_api_build_socials_response( $artist_id ) );
}

/**
 * PUT handler - update social links via ability.
 */
function extrachill_api_artist_socials_put_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'id' );
	$body      = $request->get_json_params();

	if ( empty( $body ) ) {
		return new WP_Error( 'empty_body', 'No data provided.', array( 'status' => 400 ) );
	}

	if ( ! isset( $body['social_links'] ) ) {
		return new WP_Error( 'missing_field', 'social_links field is required.', array( 'status' => 400 ) );
	}

	if ( ! is_array( $body['social_links'] ) ) {
		return new WP_Error( 'invalid_format', 'social_links must be an array.', array( 'status' => 400 ) );
	}

	$ability = wp_get_ability( 'extrachill/save-social-links' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute(
		array(
			'artist_id'    => $artist_id,
			'social_links' => $body['social_links'],
		)
	);

	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 500 ) );
	}

	return rest_ensure_response( $result );
}

/**
 * Build social links response data.
 *
 * Used by the GET handler. Reads directly from the social links manager
 * since there's no dedicated read-socials ability.
 */
function extrachill_api_build_socials_response( $artist_id ) {
	$social_links = array();

	if ( function_exists( 'extrachill_artist_platform_social_links' ) ) {
		$social_manager = extrachill_artist_platform_social_links();
		$social_links   = $social_manager->get( $artist_id );

		if ( is_array( $social_links ) ) {
			foreach ( $social_links as $index => $social_link ) {
				if ( ! is_array( $social_link ) || empty( $social_link['type'] ) || empty( $social_link['id'] ) ) {
					continue;
				}

				$social_links[ $index ]['icon_class'] = $social_manager->get_icon_class( $social_link['type'], $social_link );
			}
		}
	}

	return array(
		'social_links' => is_array( $social_links ) ? $social_links : array(),
	);
}
