<?php
/**
 * Artist Socials REST API Endpoint
 *
 * GET /wp-json/extrachill/v1/artists/{id}/socials - Retrieve social links
 * PUT /wp-json/extrachill/v1/artists/{id}/socials - Update social links (full replacement)
 *
 * Social links are icon buttons (Instagram, Spotify, etc.) stored on the artist profile.
 * Uses extrachill_artist_platform_social_links() manager for all operations.
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
 * GET handler - retrieve social links
 */
function extrachill_api_artist_socials_get_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'id' );

	return rest_ensure_response( extrachill_api_build_socials_response( $artist_id ) );
}

/**
 * PUT handler - update social links (full replacement)
 */
function extrachill_api_artist_socials_put_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'id' );
	$body      = $request->get_json_params();

	if ( ! function_exists( 'extrachill_artist_platform_social_links' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Social links manager not available.',
			array( 'status' => 500 )
		);
	}

	if ( ! function_exists( 'ec_get_link_page_for_artist' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Link page functions not available.',
			array( 'status' => 500 )
		);
	}

	$link_page_id = ec_get_link_page_for_artist( $artist_id );

	if ( ! $link_page_id ) {
		return new WP_Error(
			'no_link_page',
			'No link page exists for this artist.',
			array( 'status' => 404 )
		);
	}

	if ( empty( $body ) ) {
		return new WP_Error(
			'empty_body',
			'No data provided.',
			array( 'status' => 400 )
		);
	}

	// Validate social_links is present
	if ( ! isset( $body['social_links'] ) ) {
		return new WP_Error(
			'missing_field',
			'social_links field is required.',
			array( 'status' => 400 )
		);
	}

	if ( ! is_array( $body['social_links'] ) ) {
		return new WP_Error(
			'invalid_format',
			'social_links must be an array.',
			array( 'status' => 400 )
		);
	}

	$sanitized_socials = extrachill_api_sanitize_socials( $body['social_links'], $link_page_id );

	if ( empty( $sanitized_socials ) && ! empty( $body['social_links'] ) ) {
		return new WP_Error(
			'validation_failed',
			'No valid social links found.',
			array( 'status' => 400 )
		);
	}

	$social_manager = extrachill_artist_platform_social_links();

	$result = $social_manager->save( $artist_id, $sanitized_socials );

	if ( is_wp_error( $result ) ) {
		return new WP_Error(
			'save_failed',
			$result->get_error_message(),
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response( extrachill_api_build_socials_response( $artist_id ) );
}

/**
 * Build social links response data
 */
function extrachill_api_build_socials_response( $artist_id ) {
    $social_links = array();

    if ( function_exists( 'extrachill_artist_platform_social_links' ) ) {
        $social_manager = extrachill_artist_platform_social_links();
        $social_links   = $social_manager->get( $artist_id );

        // Enrich each link with icon_class for frontend rendering
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

