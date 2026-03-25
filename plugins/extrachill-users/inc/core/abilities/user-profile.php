<?php
/**
 * User Profile Abilities
 *
 * Public-facing profile fields: avatar, custom title, bio, local city, links.
 * Business logic (validation, sanitization, writes) lives here.
 * REST and CLI are thin wrappers.
 *
 * @package ExtraChill\Users
 * @since   0.8.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_profile_abilities' );

/**
 * Register user profile abilities.
 */
function extrachill_users_register_profile_abilities() {

	// --- Get User Profile ---
	wp_register_ability(
		'extrachill/get-user-profile',
		array(
			'label'               => __( 'Get User Profile', 'extrachill-users' ),
			'description'         => __( 'Retrieve public profile fields: avatar, title, bio, city, links, artist status.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'user_id' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_get_profile',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);

	// --- Update User Profile ---
	wp_register_ability(
		'extrachill/update-user-profile',
		array(
			'label'               => __( 'Update User Profile', 'extrachill-users' ),
			'description'         => __( 'Update profile fields: custom title, bio (description), local city.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'      => array( 'type' => 'integer' ),
					'custom_title' => array( 'type' => 'string' ),
					'bio'          => array( 'type' => 'string' ),
					'local_city'   => array( 'type' => 'string' ),
				),
				'required'   => array( 'user_id' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_update_profile',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'   => false,
					'idempotent' => true,
				),
			),
		)
	);

	// --- Update User Links ---
	wp_register_ability(
		'extrachill/update-user-links',
		array(
			'label'               => __( 'Update User Links', 'extrachill-users' ),
			'description'         => __( 'Update user profile social/music links. Replaces all existing links.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer' ),
					'links'   => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'type_key'     => array( 'type' => 'string' ),
								'url'          => array( 'type' => 'string' ),
								'custom_label' => array( 'type' => 'string' ),
							),
							'required'   => array( 'type_key', 'url' ),
						),
					),
				),
				'required'   => array( 'user_id', 'links' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_update_links',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'   => false,
					'idempotent' => true,
				),
			),
		)
	);
}

/**
 * Valid link type keys.
 *
 * Shared constant for validation across abilities and frontend.
 *
 * @return array Associative array of type_key => label.
 */
function extrachill_users_get_link_types() {
	return array(
		'website'    => 'Website',
		'facebook'   => 'Facebook',
		'instagram'  => 'Instagram',
		'twitter'    => 'Twitter',
		'youtube'    => 'YouTube',
		'tiktok'     => 'TikTok',
		'spotify'    => 'Spotify',
		'soundcloud' => 'SoundCloud',
		'bandcamp'   => 'Bandcamp',
		'github'     => 'GitHub',
		'other'      => 'Other',
	);
}

/**
 * Get user profile data.
 *
 * @param array $input Input with 'user_id'.
 * @return array|WP_Error Profile data or error.
 */
function extrachill_users_ability_get_profile( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;

	if ( ! $user_id ) {
		return new WP_Error( 'missing_user_id', 'user_id is required.' );
	}

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	// Custom title (defaults to "Extra Chillian").
	$custom_title = get_user_meta( $user_id, 'ec_custom_title', true );

	// Bio.
	$bio = $user->description;

	// Local city.
	$local_city = get_user_meta( $user_id, 'local_city', true );

	// Dynamic links.
	$links = get_user_meta( $user_id, '_user_profile_dynamic_links', true );
	if ( ! is_array( $links ) ) {
		$links = array();
	}

	// Avatar URL.
	$avatar_url = '';
	if ( function_exists( 'extrachill_get_custom_avatar_url' ) ) {
		$avatar_url = extrachill_get_custom_avatar_url( $user_id );
	}
	if ( empty( $avatar_url ) ) {
		$avatar_url = get_avatar_url( $user_id, array( 'size' => 300 ) );
	}

	// Artist status.
	$has_artist       = get_user_meta( $user_id, 'user_is_artist', true ) === '1';
	$has_professional = get_user_meta( $user_id, 'user_is_professional', true ) === '1';
	$pending_request  = get_user_meta( $user_id, 'artist_access_request', true );

	$artist_status = 'none';
	if ( $has_artist || $has_professional ) {
		$artist_status = 'approved';
	} elseif ( ! empty( $pending_request ) && is_array( $pending_request ) ) {
		$artist_status = 'pending';
	}

	$artist_access = array(
		'status' => $artist_status,
		'type'   => $has_artist ? 'artist' : ( $has_professional ? 'professional' : '' ),
	);

	if ( 'pending' === $artist_status ) {
		$artist_access['request_type'] = isset( $pending_request['type'] ) ? $pending_request['type'] : '';
		$artist_access['requested_at'] = isset( $pending_request['requested_at'] ) ? (int) $pending_request['requested_at'] : 0;
	}

	// Available link types for the frontend.
	$link_types = extrachill_users_get_link_types();

	return array(
		'user_id'       => $user_id,
		'display_name'  => $user->display_name,
		'username'      => $user->user_login,
		'avatar_url'    => $avatar_url,
		'custom_title'  => $custom_title,
		'bio'           => $bio,
		'local_city'    => $local_city,
		'links'         => $links,
		'link_types'    => $link_types,
		'artist_access' => $artist_access,
	);
}

/**
 * Update user profile fields.
 *
 * @param array $input Input with 'user_id' and optional profile fields.
 * @return array|WP_Error Updated profile or error.
 */
function extrachill_users_ability_update_profile( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;

	if ( ! $user_id ) {
		return new WP_Error( 'missing_user_id', 'user_id is required.' );
	}

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	$updated = false;

	// Custom title.
	if ( isset( $input['custom_title'] ) ) {
		$custom_title = sanitize_text_field( $input['custom_title'] );
		update_user_meta( $user_id, 'ec_custom_title', $custom_title );
		$updated = true;
	}

	// Bio (description).
	if ( isset( $input['bio'] ) ) {
		$bio = wp_kses_post( $input['bio'] );
		wp_update_user(
			array(
				'ID'          => $user_id,
				'description' => $bio,
			)
		);
		$updated = true;
	}

	// Local city.
	if ( isset( $input['local_city'] ) ) {
		$local_city = sanitize_text_field( $input['local_city'] );
		update_user_meta( $user_id, 'local_city', $local_city );
		$updated = true;
	}

	if ( ! $updated ) {
		return array(
			'success' => true,
			'message' => 'No changes detected.',
			'user_id' => $user_id,
		);
	}

	// Return fresh profile data.
	return extrachill_users_ability_get_profile( array( 'user_id' => $user_id ) );
}

/**
 * Update user profile links.
 *
 * Replaces all existing links with the provided set.
 * Validates type_key against allowed link types and sanitizes URLs.
 *
 * @param array $input Input with 'user_id' and 'links' array.
 * @return array|WP_Error Updated links or error.
 */
function extrachill_users_ability_update_links( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	$links   = isset( $input['links'] ) ? $input['links'] : array();

	if ( ! $user_id ) {
		return new WP_Error( 'missing_user_id', 'user_id is required.' );
	}

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	if ( ! is_array( $links ) ) {
		return new WP_Error( 'invalid_links', 'Links must be an array.' );
	}

	$valid_types     = array_keys( extrachill_users_get_link_types() );
	$sanitized_links = array();

	foreach ( $links as $link ) {
		if ( ! is_array( $link ) ) {
			continue;
		}

		$type_key = isset( $link['type_key'] ) ? sanitize_text_field( $link['type_key'] ) : '';
		$url      = isset( $link['url'] ) ? esc_url_raw( $link['url'] ) : '';

		// Skip empty URLs.
		if ( empty( $url ) ) {
			continue;
		}

		// Validate type_key.
		if ( ! in_array( $type_key, $valid_types, true ) ) {
			continue;
		}

		$sanitized_link = array(
			'type_key' => $type_key,
			'url'      => $url,
		);

		// Custom label (only meaningful for 'other' type, but store if provided).
		if ( ! empty( $link['custom_label'] ) ) {
			$sanitized_link['custom_label'] = sanitize_text_field( $link['custom_label'] );
		}

		$sanitized_links[] = $sanitized_link;
	}

	if ( empty( $sanitized_links ) ) {
		delete_user_meta( $user_id, '_user_profile_dynamic_links' );
	} else {
		update_user_meta( $user_id, '_user_profile_dynamic_links', $sanitized_links );
	}

	return array(
		'success' => true,
		'message' => 'Profile links updated.',
		'user_id' => $user_id,
		'links'   => $sanitized_links,
	);
}
