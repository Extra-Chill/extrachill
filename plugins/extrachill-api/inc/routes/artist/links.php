<?php
/**
 * Artist Links REST API Endpoint
 *
 * GET /wp-json/extrachill/v1/artists/{id}/links - Retrieve link page data
 * PUT /wp-json/extrachill/v1/artists/{id}/links - Update link page data (partial updates supported)
 *
 * Link page data includes button links (sections), CSS variables, and settings.
 * Background images are managed via the /media endpoint, not here.
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
 * GET handler - retrieve link page data via canonical ec_get_link_page_data()
 */
function extrachill_api_artist_links_get_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'id' );

	if ( ! function_exists( 'ec_get_link_page_data' ) ) {
		return new WP_Error(
			'dependency_missing',
			'Link page functions not available.',
			array( 'status' => 500 )
		);
	}

	$data = ec_get_link_page_data( $artist_id );

	if ( empty( $data ) || empty( $data['link_page_id'] ) ) {
		return new WP_Error(
			'no_link_page',
			'No link page exists for this artist.',
			array( 'status' => 404 )
		);
	}

	return rest_ensure_response( $data );
}

/**
 * PUT handler - update link page data (partial updates)
 *
 * Accepts the canonical payload shape emitted by ec_get_link_page_data():
 * - links (array of sections)
 * - css_vars (object)
 * - settings (object)
 * - socials (array of {type, url})
 * - background_image_id (int, optional)
 * - profile_image_id (int, optional - stored on artist)
 */
function extrachill_api_artist_links_put_handler( WP_REST_Request $request ) {
	$artist_id = $request->get_param( 'id' );
	$body      = $request->get_json_params();

	if ( ! function_exists( 'ec_get_link_page_for_artist' ) || ! function_exists( 'ec_handle_link_page_save' ) ) {
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
			'Request body is empty.',
			array( 'status' => 400 )
		);
	}

	$save_data = array();

	// Links - full replacement
	if ( isset( $body['links'] ) ) {
		if ( ! is_array( $body['links'] ) ) {
			return new WP_Error(
				'invalid_format',
				'links must be an array.',
				array( 'status' => 400 )
			);
		}
		$save_data['links'] = extrachill_api_sanitize_links( $body['links'] );
	}

	// CSS vars - merge with existing
	if ( isset( $body['css_vars'] ) ) {
		if ( ! is_array( $body['css_vars'] ) ) {
			return new WP_Error(
				'invalid_format',
				'css_vars must be an object.',
				array( 'status' => 400 )
			);
		}
		$existing_vars = get_post_meta( $link_page_id, '_link_page_custom_css_vars', true );
		$existing_vars = is_array( $existing_vars ) ? $existing_vars : array();
		$sanitized_vars = extrachill_api_sanitize_css_vars( $body['css_vars'] );
		$save_data['css_vars'] = array_merge( $existing_vars, $sanitized_vars );
	}

	// Settings - merge with existing, extract to save_data keys
	if ( isset( $body['settings'] ) ) {
		if ( ! is_array( $body['settings'] ) ) {
			return new WP_Error(
				'invalid_format',
				'settings must be an object.',
				array( 'status' => 400 )
			);
		}
		$sanitized_settings = extrachill_api_sanitize_link_settings( $body['settings'] );
		$save_data = array_merge( $save_data, $sanitized_settings );
	}

	// Socials - pass to save handler as social_icons
	if ( isset( $body['socials'] ) ) {
		if ( ! is_array( $body['socials'] ) ) {
			return new WP_Error(
				'invalid_format',
				'socials must be an array.',
				array( 'status' => 400 )
			);
		}
		$save_data['social_icons'] = extrachill_api_sanitize_socials( $body['socials'] );
	}

	// Background image ID (stored on link page)
	if ( isset( $body['background_image_id'] ) ) {
		$save_data['background_image_id'] = absint( $body['background_image_id'] );
	}

	// Profile image ID (stored on artist as thumbnail)
	if ( isset( $body['profile_image_id'] ) ) {
		$save_data['profile_image_id'] = absint( $body['profile_image_id'] );
	}

	// Use existing save handler (no file uploads via REST)
	$result = ec_handle_link_page_save( $link_page_id, $save_data );

	if ( is_wp_error( $result ) ) {
		return new WP_Error(
			'save_failed',
			$result->get_error_message(),
			array( 'status' => 500 )
		);
	}

	// Return fresh data from canonical source
	return rest_ensure_response( ec_get_link_page_data( $artist_id, $link_page_id ) );
}

/**
 * Sanitize links array (full replacement)
 */
function extrachill_api_sanitize_links( $links ) {
	if ( ! is_array( $links ) ) {
		return array();
	}

	$sanitized = array();

	foreach ( $links as $section ) {
		if ( ! is_array( $section ) ) {
			continue;
		}

		$sanitized_section = array(
			'section_title' => isset( $section['section_title'] ) ? sanitize_text_field( wp_unslash( $section['section_title'] ) ) : '',
			'links'         => array(),
		);

		if ( isset( $section['links'] ) && is_array( $section['links'] ) ) {
			foreach ( $section['links'] as $link ) {
				if ( ! is_array( $link ) ) {
					continue;
				}

				$sanitized_link = array(
					'link_text' => isset( $link['link_text'] ) ? sanitize_text_field( wp_unslash( $link['link_text'] ) ) : '',
					'link_url'  => isset( $link['link_url'] ) ? esc_url_raw( wp_unslash( $link['link_url'] ) ) : '',
					'id'        => isset( $link['id'] ) ? sanitize_text_field( $link['id'] ) : 'link_' . time() . '_' . wp_rand(),
				);

				// Optional expiration
				if ( isset( $link['expires_at'] ) && ! empty( $link['expires_at'] ) ) {
					$sanitized_link['expires_at'] = sanitize_text_field( wp_unslash( $link['expires_at'] ) );
				}

				$sanitized_section['links'][] = $sanitized_link;
			}
		}

		$sanitized[] = $sanitized_section;
	}

	return $sanitized;
}

/**
 * Sanitize CSS variables (merge-friendly)
 */
function extrachill_api_sanitize_css_vars( $vars ) {
	if ( ! is_array( $vars ) ) {
		return array();
	}

	$sanitized = array();

	foreach ( $vars as $key => $value ) {
		// Only accept --link-page-* variables and overlay
		if ( strpos( $key, '--link-page-' ) !== 0 && $key !== 'overlay' ) {
			continue;
		}

		// Color values
		if ( strpos( $key, 'color' ) !== false || strpos( $key, '-bg' ) !== false ) {
			$sanitized[ $key ] = sanitize_hex_color( $value );
		} else {
			$sanitized[ $key ] = sanitize_text_field( wp_unslash( $value ) );
		}
	}

	return $sanitized;
}

/**
 * Sanitize link page settings (returns flat array for ec_handle_link_page_save)
 */
function extrachill_api_sanitize_link_settings( $settings ) {
	if ( ! is_array( $settings ) ) {
		return array();
	}

	$sanitized = array();

	// Boolean fields (stored as '1' or '0')
	$bool_fields = array(
		'link_expiration_enabled',
		'weekly_notifications_enabled',
		'redirect_enabled',
		'youtube_embed_enabled',
		'overlay_enabled',
	);

	foreach ( $bool_fields as $field ) {
		if ( isset( $settings[ $field ] ) ) {
			$sanitized[ $field ] = $settings[ $field ] ? '1' : '0';
		}
	}

	// String fields
	$string_fields = array(
		'redirect_target_url',
		'meta_pixel_id',
		'google_tag_id',
		'google_tag_manager_id',
		'subscribe_display_mode',
		'subscribe_description',
		'social_icons_position',
		'profile_image_shape',
	);

	foreach ( $string_fields as $field ) {
		if ( isset( $settings[ $field ] ) ) {
			$sanitized[ $field ] = sanitize_text_field( wp_unslash( $settings[ $field ] ) );
		}
	}

	return $sanitized;
}

/**
 * Sanitize socials array for REST input
 */
function extrachill_api_sanitize_socials( $socials ) {
	if ( ! is_array( $socials ) ) {
		return array();
	}

	$sanitized = array();

	foreach ( $socials as $social ) {
		if ( ! is_array( $social ) ) {
			continue;
		}

		$type = isset( $social['type'] ) ? sanitize_text_field( wp_unslash( $social['type'] ) ) : '';
		$url  = isset( $social['url'] ) ? esc_url_raw( wp_unslash( $social['url'] ) ) : '';

		if ( empty( $type ) || empty( $url ) ) {
			continue;
		}

		$sanitized[] = array(
			'type' => $type,
			'url'  => $url,
		);
	}

	return $sanitized;
}
