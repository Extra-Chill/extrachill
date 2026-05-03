<?php
/**
 * Artist Links REST API Endpoint
 *
 * GET /wp-json/extrachill/v1/artists/{id}/links - Retrieve link page data
 * PUT /wp-json/extrachill/v1/artists/{id}/links - Update link page data (partial updates supported)
 *
 * Delegates to abilities:
 * - extrachill/get-link-page-data
 * - extrachill/save-link-page-links
 * - extrachill/save-link-page-styles
 * - extrachill/save-link-page-settings
 * - extrachill/save-social-links
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_artist_links_routes' );

function extrachill_api_register_artist_links_routes() {
	register_rest_route( 'extrachill/v1', '/artists/(?P<id>\d+)/links', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_artist_links_get_handler',
			'permission_callback' => 'extrachill_api_artist_links_permission_check',
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
			'callback'            => 'extrachill_api_artist_links_put_handler',
			'permission_callback' => 'extrachill_api_artist_links_permission_check',
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
 * Permission check for artist links endpoint
 */
function extrachill_api_artist_links_permission_check( WP_REST_Request $request ) {
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
 * GET handler - retrieve link page data via ability.
 */
function extrachill_api_artist_links_get_handler( WP_REST_Request $request ) {
	$ability = wp_get_ability( 'extrachill/get-link-page-data' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( array( 'artist_id' => $request->get_param( 'id' ) ) );

	if ( is_wp_error( $result ) ) {
		$status = $result->get_error_code() === 'no_link_page' ? 404 : 500;
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
	}

	return rest_ensure_response( $result );
}

/**
 * PUT handler - update link page data via abilities.
 *
 * Routes each payload section to the appropriate ability:
 * - links       -> extrachill/save-link-page-links
 * - css_vars    -> extrachill/save-link-page-styles
 * - settings    -> extrachill/save-link-page-settings
 * - socials     -> extrachill/save-social-links
 * - background_image_id, profile_image_id -> extrachill/save-link-page-settings
 */
function extrachill_api_artist_links_put_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'id' );
	$body      = $request->get_json_params();

	if ( empty( $body ) ) {
		return new WP_Error( 'empty_body', 'Request body is empty.', array( 'status' => 400 ) );
	}

	// The JS client wraps the full payload as {links: {links, settings, css_vars, ...}}.
	// Unwrap nested compound object to top-level keys for backward compatibility.
	if ( isset( $body['links'] ) && is_array( $body['links'] ) && isset( $body['links']['links'] ) ) {
		$nested = $body['links'];
		$body   = array_merge( $body, $nested );
		$body['links'] = $nested['links'];
	}

	// Save links via ability.
	if ( isset( $body['links'] ) ) {
		if ( ! is_array( $body['links'] ) ) {
			return new WP_Error( 'invalid_format', 'links must be an array.', array( 'status' => 400 ) );
		}

		$ability = wp_get_ability( 'extrachill/save-link-page-links' );
		if ( ! $ability ) {
			return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
		}

		$result = $ability->execute(
			array(
				'artist_id' => $artist_id,
				'links'     => $body['links'],
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 500 ) );
		}
	}

	// Save CSS vars via ability.
	if ( isset( $body['css_vars'] ) ) {
		if ( ! is_array( $body['css_vars'] ) ) {
			return new WP_Error( 'invalid_format', 'css_vars must be an object.', array( 'status' => 400 ) );
		}

		$ability = wp_get_ability( 'extrachill/save-link-page-styles' );
		if ( ! $ability ) {
			return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
		}

		$result = $ability->execute(
			array(
				'artist_id' => $artist_id,
				'css_vars'  => $body['css_vars'],
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 500 ) );
		}
	}

	// Save settings via ability.
	$has_settings = isset( $body['settings'] ) || isset( $body['background_image_id'] ) || isset( $body['profile_image_id'] );
	if ( $has_settings ) {
		if ( isset( $body['settings'] ) && ! is_array( $body['settings'] ) ) {
			return new WP_Error( 'invalid_format', 'settings must be an object.', array( 'status' => 400 ) );
		}

		$ability = wp_get_ability( 'extrachill/save-link-page-settings' );
		if ( ! $ability ) {
			return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
		}

		$settings_input = array( 'artist_id' => $artist_id );
		if ( isset( $body['settings'] ) ) {
			$settings_input['settings'] = $body['settings'];
		}
		if ( isset( $body['background_image_id'] ) ) {
			$settings_input['background_image_id'] = absint( $body['background_image_id'] );
		}
		if ( isset( $body['profile_image_id'] ) ) {
			$settings_input['profile_image_id'] = absint( $body['profile_image_id'] );
		}

		$result = $ability->execute( $settings_input );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 500 ) );
		}
	}

	// Save socials via ability.
	if ( isset( $body['socials'] ) ) {
		if ( ! is_array( $body['socials'] ) ) {
			return new WP_Error( 'invalid_format', 'socials must be an array.', array( 'status' => 400 ) );
		}

		$ability = wp_get_ability( 'extrachill/save-social-links' );
		if ( ! $ability ) {
			return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
		}

		$result = $ability->execute(
			array(
				'artist_id'    => $artist_id,
				'social_links' => $body['socials'],
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 500 ) );
		}
	}

	// Return fresh data via read ability.
	$read_ability = wp_get_ability( 'extrachill/get-link-page-data' );
	if ( ! $read_ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-artist-platform plugin is required.', array( 'status' => 500 ) );
	}

	$fresh_data = $read_ability->execute( array( 'artist_id' => $artist_id ) );

	if ( is_wp_error( $fresh_data ) ) {
		return new WP_Error( $fresh_data->get_error_code(), $fresh_data->get_error_message(), array( 'status' => 500 ) );
	}

	return rest_ensure_response( $fresh_data );
}
