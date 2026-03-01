<?php
/**
 * Onboarding Abilities
 *
 * Core primitives for the onboarding lifecycle:
 * - extrachill/complete-onboarding   Finalize username, flags, send email
 * - extrachill/get-onboarding-status Read onboarding state
 * - extrachill/validate-username     Check username validity and availability
 *
 * @package ExtraChill\Users
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_onboarding_abilities' );

/**
 * Register onboarding abilities.
 */
function extrachill_users_register_onboarding_abilities() {

	// ── Complete Onboarding ─────────────────────────────────────────────
	wp_register_ability(
		'extrachill/complete-onboarding',
		array(
			'label'               => __( 'Complete Onboarding', 'extrachill-users' ),
			'description'         => __( 'Finalize user onboarding: validate and set username, set artist/professional flags, mark complete, and send welcome email.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'              => array(
						'type'        => 'integer',
						'description' => __( 'User ID to complete onboarding for.', 'extrachill-users' ),
					),
					'username'             => array(
						'type'        => 'string',
						'description' => __( 'Chosen username.', 'extrachill-users' ),
					),
					'user_is_artist'       => array(
						'type'        => 'boolean',
						'description' => __( 'Whether user is a musician.', 'extrachill-users' ),
					),
					'user_is_professional' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether user works in the music industry.', 'extrachill-users' ),
					),
				),
				'required'   => array( 'user_id', 'username' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'      => array( 'type' => 'boolean' ),
					'user'         => array( 'type' => 'object' ),
					'redirect_url' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_complete_onboarding',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);

	// ── Get Onboarding Status ───────────────────────────────────────────
	wp_register_ability(
		'extrachill/get-onboarding-status',
		array(
			'label'               => __( 'Get Onboarding Status', 'extrachill-users' ),
			'description'         => __( 'Get onboarding completion state, flags, and current username for a user.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array(
						'type'        => 'integer',
						'description' => __( 'User ID.', 'extrachill-users' ),
					),
				),
				'required'   => array( 'user_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'completed' => array( 'type' => 'boolean' ),
					'from_join' => array( 'type' => 'boolean' ),
					'fields'    => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_get_onboarding_status',
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

	// ── Validate Username ───────────────────────────────────────────────
	wp_register_ability(
		'extrachill/validate-username',
		array(
			'label'               => __( 'Validate Username', 'extrachill-users' ),
			'description'         => __( 'Check if a username is valid (length, characters, reserved words) and available.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'username' => array(
						'type'        => 'string',
						'description' => __( 'Username to validate.', 'extrachill-users' ),
					),
					'user_id'  => array(
						'type'        => 'integer',
						'description' => __( 'Current user ID (allows keeping own username).', 'extrachill-users' ),
					),
				),
				'required'   => array( 'username' ),
			),
			'output_schema'       => array(
				'type'        => 'boolean',
				'description' => __( 'True if username is valid and available.', 'extrachill-users' ),
			),
			'execute_callback'    => 'extrachill_users_ability_validate_username',
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
}

// ─── Execute Callbacks ──────────────────────────────────────────────────────

/**
 * Complete onboarding for a user.
 *
 * Validates username, updates the user record, sets artist/professional flags,
 * marks onboarding complete, refreshes auth, and sends the welcome email.
 *
 * @param array $input {user_id, username, user_is_artist, user_is_professional}.
 * @return array|WP_Error Success array with user data and redirect URL, or error.
 */
function extrachill_users_ability_complete_onboarding( $input ) {
	$user_id              = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	$username             = isset( $input['username'] ) ? sanitize_user( $input['username'], true ) : '';
	$user_is_artist       = ! empty( $input['user_is_artist'] );
	$user_is_professional = ! empty( $input['user_is_professional'] );

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new WP_Error( 'invalid_user', __( 'Invalid user.', 'extrachill-users' ) );
	}

	if ( function_exists( 'ec_is_onboarding_complete' ) && ec_is_onboarding_complete( $user_id ) ) {
		return new WP_Error( 'already_completed', __( 'Onboarding already completed.', 'extrachill-users' ) );
	}

	// Validate username via ability.
	$validate_ability = wp_get_ability( 'extrachill/validate-username' );
	if ( $validate_ability ) {
		$valid = $validate_ability->execute(
			array(
				'username' => $username,
				'user_id'  => $user_id,
			)
		);

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
	}

	// Join flow requires artist or professional flag.
	$from_join = function_exists( 'ec_is_onboarding_from_join' ) && ec_is_onboarding_from_join( $user_id );
	if ( $from_join && ! $user_is_artist && ! $user_is_professional ) {
		return new WP_Error(
			'artist_or_professional_required',
			__( 'Please select "I am a musician" or "I work in the music industry" to continue.', 'extrachill-users' )
		);
	}

	// Update username in database.
	global $wpdb;

	$result = $wpdb->update(
		$wpdb->users,
		array(
			'user_login'    => $username,
			'user_nicename' => sanitize_title( $username ),
			'display_name'  => $username,
		),
		array( 'ID' => $user_id ),
		array( '%s', '%s', '%s' ),
		array( '%d' )
	);

	if ( false === $result ) {
		return new WP_Error( 'update_failed', __( 'Failed to update username.', 'extrachill-users' ) );
	}

	// Set flags and mark complete.
	update_user_meta( $user_id, 'user_is_artist', $user_is_artist ? '1' : '0' );
	update_user_meta( $user_id, 'user_is_professional', $user_is_professional ? '1' : '0' );
	update_user_meta( $user_id, 'onboarding_completed', '1' );
	update_user_meta( $user_id, 'onboarding_completed_at', time() );

	clean_user_cache( $user_id );

	// Refresh auth session.
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true );

	/**
	 * Fires after onboarding is completed.
	 *
	 * @param int   $user_id User ID.
	 * @param array $input   Onboarding data.
	 */
	do_action( 'ec_onboarding_completed', $user_id, $input );

	// Send welcome email via ability.
	$email_ability = wp_get_ability( 'extrachill/send-welcome-email' );
	if ( $email_ability ) {
		$email_ability->execute(
			array(
				'user_id'    => $user_id,
				'email_type' => 'onboarding_complete',
			)
		);
	}

	// Determine redirect URL.
	$redirect_url = get_user_meta( $user_id, 'onboarding_redirect_url', true );
	if ( empty( $redirect_url ) ) {
		$redirect_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : home_url();
	}

	if ( $from_join && function_exists( 'ec_get_site_url' ) ) {
		$redirect_url = ec_get_site_url( 'artist' ) . '/create-artist/';
	}

	return array(
		'success'      => true,
		'user'         => array(
			'id'                   => $user_id,
			'username'             => $username,
			'user_is_artist'       => $user_is_artist,
			'user_is_professional' => $user_is_professional,
		),
		'redirect_url' => $redirect_url,
	);
}

/**
 * Get onboarding status for a user.
 *
 * @param array $input {user_id}.
 * @return array {completed, from_join, fields}.
 */
function extrachill_users_ability_get_onboarding_status( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;

	if ( function_exists( 'ec_get_onboarding_status' ) ) {
		return ec_get_onboarding_status( $user_id );
	}

	// Fallback if utility not loaded.
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return array(
			'completed' => true,
			'from_join' => false,
			'fields'    => array(),
		);
	}

	$meta_value = get_user_meta( $user_id, 'onboarding_completed', true );
	$completed  = '' === $meta_value || '1' === $meta_value;

	return array(
		'completed' => $completed,
		'from_join' => '1' === get_user_meta( $user_id, 'onboarding_from_join', true ),
		'fields'    => array(
			'username'             => $user->user_login,
			'user_is_artist'       => '1' === get_user_meta( $user_id, 'user_is_artist', true ),
			'user_is_professional' => '1' === get_user_meta( $user_id, 'user_is_professional', true ),
		),
	);
}

/**
 * Validate a username for onboarding.
 *
 * Checks length, allowed characters, reserved words, and availability.
 *
 * @param array $input {username, user_id}.
 * @return true|WP_Error True if valid, WP_Error with specific code otherwise.
 */
function extrachill_users_ability_validate_username( $input ) {
	$username = isset( $input['username'] ) ? sanitize_user( $input['username'], true ) : '';
	$user_id  = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;

	if ( strlen( $username ) < 3 ) {
		return new WP_Error(
			'username_too_short',
			__( 'Username must be at least 3 characters.', 'extrachill-users' )
		);
	}

	if ( strlen( $username ) > 60 ) {
		return new WP_Error(
			'username_too_long',
			__( 'Username must be 60 characters or less.', 'extrachill-users' )
		);
	}

	if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $username ) ) {
		return new WP_Error(
			'username_invalid_chars',
			__( 'Username can only contain letters, numbers, hyphens, and underscores.', 'extrachill-users' )
		);
	}

	$existing_user = get_user_by( 'login', $username );
	if ( $existing_user && $existing_user->ID !== $user_id ) {
		return new WP_Error(
			'username_exists',
			__( 'This username is already taken.', 'extrachill-users' )
		);
	}

	$reserved_usernames = array(
		'admin',
		'administrator',
		'extrachill',
		'support',
		'help',
		'info',
		'contact',
		'webmaster',
		'root',
		'system',
		'moderator',
		'mod',
	);

	if ( in_array( strtolower( $username ), $reserved_usernames, true ) ) {
		return new WP_Error(
			'username_reserved',
			__( 'This username is reserved.', 'extrachill-users' )
		);
	}

	return true;
}
