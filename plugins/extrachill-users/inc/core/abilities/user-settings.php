<?php
/**
 * User Settings Abilities
 *
 * Account-level settings: name fields, display name, email change, password change.
 * Business logic (validation, sanitization, writes) lives here.
 * REST and CLI are thin wrappers.
 *
 * @package ExtraChill\Users
 * @since   0.8.0
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY = '_extrachill_default_event_location';

add_action( 'wp_abilities_api_init', 'extrachill_users_register_settings_abilities' );

/**
 * Register user settings abilities.
 */
function extrachill_users_register_settings_abilities() {

	// --- Get User Settings ---
	wp_register_ability(
		'extrachill/get-user-settings',
		array(
			'label'               => __( 'Get User Settings', 'extrachill-users' ),
			'description'         => __( 'Retrieve private account settings for the authenticated user.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_get_settings',
			// Self-only: returns the authenticated user's account settings (incl. email).
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);

	// --- Update User Settings ---
	wp_register_ability(
		'extrachill/update-user-settings',
		array(
			'label'               => __( 'Update User Settings', 'extrachill-users' ),
			'description'         => __( 'Update account details: first name, last name, display name.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'first_name'             => array( 'type' => 'string' ),
					'last_name'              => array( 'type' => 'string' ),
					'display_name'           => array( 'type' => 'string' ),
					'default_event_location' => array(
						'type'        => 'string',
						'description' => __( 'Canonical events location slug. Pass an empty string to clear it.', 'extrachill-users' ),
					),
				),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_update_settings',
			// Self-only: updates the authenticated user's account details.
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'   => false,
					'idempotent' => true,
				),
			),
		)
	);

	// --- Change User Email ---
	wp_register_ability(
		'extrachill/change-user-email',
		array(
			'label'               => __( 'Change User Email', 'extrachill-users' ),
			'description'         => __( 'Initiate email change with verification. Sends confirmation to new address.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'new_email' => array( 'type' => 'string' ),
				),
				'required'   => array( 'new_email' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_change_email',
			// Self-only: changes the authenticated user's email.
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly' => false,
				),
			),
		)
	);

	// --- Change User Password ---
	wp_register_ability(
		'extrachill/change-user-password',
		array(
			'label'               => __( 'Change User Password', 'extrachill-users' ),
			'description'         => __( 'Change user password. Requires current password verification.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'current_password' => array( 'type' => 'string' ),
					'new_password'     => array( 'type' => 'string' ),
					'confirm_password' => array( 'type' => 'string' ),
				),
				'required'   => array( 'current_password', 'new_password', 'confirm_password' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_change_password',
			// Self-only: changes the authenticated user's password (requires current password).
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly' => false,
				),
			),
		)
	);
}

/**
 * Get user settings (account details).
 *
 * Self-only: resolves the authenticated user; takes no input.
 *
 * @return array|WP_Error Settings data or error.
 */
function extrachill_users_ability_get_settings() {
	// Self-only: always operate on the authenticated user, ignoring any client-supplied user_id.
	$user_id = extrachill_users_resolve_self_user_id();
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	// Build display name options (same logic as the old PHP template).
	$display_name_options             = array();
	$display_name_options['nickname'] = $user->nickname;
	$display_name_options['username'] = $user->user_login;

	if ( ! empty( $user->first_name ) ) {
		$display_name_options['firstname'] = $user->first_name;
	}
	if ( ! empty( $user->last_name ) ) {
		$display_name_options['lastname'] = $user->last_name;
	}
	if ( ! empty( $user->first_name ) && ! empty( $user->last_name ) ) {
		$display_name_options['firstlast'] = $user->first_name . ' ' . $user->last_name;
		$display_name_options['lastfirst'] = $user->last_name . ' ' . $user->first_name;
	}

	$display_name_options = array_unique( array_filter( array_map( 'trim', $display_name_options ) ) );

	// Pending email change — WordPress core uses '_new_email' meta key.
	// The old community settings page incorrectly read '_new_user_email'.
	$pending_email      = null;
	$pending_email_data = get_user_meta( $user_id, '_new_email', true );
	if ( $pending_email_data && isset( $pending_email_data['newemail'] ) ) {
		$pending_email = $pending_email_data['newemail'];
	}

	$default_event_location_slug = (string) get_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, true );
	$default_event_location      = null;
	if ( '' !== $default_event_location_slug ) {
		$default_event_location = extrachill_users_resolve_default_event_location( $default_event_location_slug );
		if ( is_wp_error( $default_event_location ) ) {
			$default_event_location = null;
		}
	}

	return array(
		'user_id'                => $user_id,
		'first_name'             => $user->first_name,
		'last_name'              => $user->last_name,
		'display_name'           => $user->display_name,
		'display_name_options'   => array_values( $display_name_options ),
		'email'                  => $user->user_email,
		'pending_email'          => $pending_email,
		'default_event_location' => $default_event_location,
	);
}

/**
 * Update user settings (account details).
 *
 * @param array $input Input with 'user_id' and optional name/display_name fields.
 * @return array|WP_Error Updated settings or error.
 */
function extrachill_users_ability_update_settings( $input ) {
	// Self-only: always operate on the authenticated user, ignoring any client-supplied user_id.
	$user_id = extrachill_users_resolve_self_user_id();
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	$update_args            = array( 'ID' => $user_id );
	$changed                = false;
	$location_input_present = array_key_exists( 'default_event_location', $input );

	if ( isset( $input['first_name'] ) ) {
		$first_name = sanitize_text_field( $input['first_name'] );
		if ( $first_name !== $user->first_name ) {
			$update_args['first_name'] = $first_name;
			$changed                   = true;
		}
	}

	if ( isset( $input['last_name'] ) ) {
		$last_name = sanitize_text_field( $input['last_name'] );
		if ( $last_name !== $user->last_name ) {
			$update_args['last_name'] = $last_name;
			$changed                  = true;
		}
	}

	if ( isset( $input['display_name'] ) ) {
		$display_name = sanitize_text_field( $input['display_name'] );
		if ( $display_name !== $user->display_name ) {
			$update_args['display_name'] = $display_name;
			$changed                     = true;
		}
	}

	if ( $location_input_present ) {
		$location_slug = sanitize_title( (string) $input['default_event_location'] );
		$current_slug  = (string) get_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, true );

		if ( '' === $location_slug ) {
			if ( '' !== $current_slug ) {
				delete_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY );
				$changed = true;
			}
		} else {
			$location = extrachill_users_resolve_default_event_location( $location_slug );
			if ( is_wp_error( $location ) ) {
				return $location;
			}

			if ( $location_slug !== $current_slug ) {
				update_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, $location_slug );
				$changed = true;
			}
		}
	}

	if ( ! $changed ) {
		if ( $location_input_present ) {
			return extrachill_users_ability_get_settings();
		}

		return array(
			'success' => true,
			'message' => 'No changes detected.',
			'user_id' => $user_id,
		);
	}

	$result = wp_update_user( $update_args );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Return fresh settings data (resolves the same authenticated user).
	return extrachill_users_ability_get_settings();
}

/**
 * Resolve a canonical location through the Events domain's public Ability.
 *
 * @param string $slug Location term slug.
 * @return array|WP_Error Resolved location or dependency/validation error.
 */
function extrachill_users_resolve_default_event_location( string $slug ) {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/events-locations' ) : null;
	if ( ! $ability ) {
		return new WP_Error(
			'events_locations_unavailable',
			__( 'Canonical event locations are currently unavailable.', 'extrachill-users' ),
			array( 'status' => 503 )
		);
	}

	$result = $ability->execute(
		array(
			'mode' => 'resolve',
			'slug' => $slug,
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_array( $result ) || ! isset( $result['location'] ) || ! is_array( $result['location'] ) ) {
		return new WP_Error(
			'events_locations_invalid_response',
			__( 'Canonical event locations returned an invalid response.', 'extrachill-users' ),
			array( 'status' => 502 )
		);
	}

	return $result['location'];
}

/**
 * Initiate email change with verification.
 *
 * Uses WordPress's built-in email verification system.
 * Stores the pending email and sends a confirmation link.
 *
 * @param array $input Input with 'user_id' and 'new_email'.
 * @return array|WP_Error Result or error.
 */
function extrachill_users_ability_change_email( $input ) {
	// Self-only: always operate on the authenticated user, ignoring any client-supplied user_id.
	$user_id = extrachill_users_resolve_self_user_id();
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$new_email = isset( $input['new_email'] ) ? sanitize_email( $input['new_email'] ) : '';

	if ( empty( $new_email ) || ! is_email( $new_email ) ) {
		return new WP_Error( 'invalid_email', 'Please provide a valid email address.' );
	}

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	if ( $new_email === $user->user_email ) {
		return new WP_Error( 'same_email', 'New email address must be different from your current email.' );
	}

	// Check if email is already in use by another user.
	$existing = email_exists( $new_email );
	if ( $existing && $existing !== $user_id ) {
		return new WP_Error( 'email_exists', 'This email address is already in use.' );
	}

	// Store pending email and send verification.
	// WordPress native: generate hash, store in meta, send confirmation email.
	$hash           = md5( $new_email . time() . wp_rand() );
	$new_user_email = array(
		'hash'     => $hash,
		'newemail' => $new_email,
	);
	update_user_meta( $user_id, '_new_email', $new_user_email );

	// Build confirmation URL — matches WordPress core (wp-includes/user.php).
	$confirm_url = esc_url(
		self_admin_url(
			'profile.php?newuseremail=' . $hash
		)
	);

	$subject = sprintf(
		/* translators: %s: site name */
		__( '[%s] Email Change Request', 'extrachill-users' ),
		wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
	);

	// Build HTML body from the plain-text template. The template owns greeting + signature,
	// so we strip those lines and convert the action paragraph to a CTA.
	$body_html  = '<p>' . esc_html__( 'Someone requested a change to the email address on your account.', 'extrachill-users' ) . '</p>';
	$body_html .= '<p>' . esc_html__( 'Please click the button below to confirm this change:', 'extrachill-users' ) . '</p>';
	$body_html .= '<p>' . sprintf(
		/* translators: %s: email address */
		esc_html__( 'This email was sent to %s.', 'extrachill-users' ),
		'<strong>' . esc_html( $new_email ) . '</strong>'
	) . '</p>';
	$body_html .= '<p>' . esc_html__( 'If you did not request this, you can safely ignore and delete this email.', 'extrachill-users' ) . '</p>';

	$result = ec_send_email(
		array(
			'to'       => $new_email,
			'subject'  => $subject,
			'template' => 'extrachill/minimal',
			'context'  => array(
				'subject_html'   => esc_html( $subject ),
				'body_html'      => $body_html,
				'recipient_name' => $user->user_login,
				'cta_url'        => $confirm_url,
				'cta_label'      => __( 'Confirm Email Change', 'extrachill-users' ),
				'preheader'      => __( 'Confirm your new email address on Extra Chill.', 'extrachill-users' ),
			),
		)
	);

	$sent = ! empty( $result['success'] );

	if ( ! $sent ) {
		delete_user_meta( $user_id, '_new_email' );
		return new WP_Error( 'email_send_failed', 'Failed to send verification email. Please try again.' );
	}

	return array(
		'success'       => true,
		'message'       => sprintf( 'Verification email sent to %s. Check your inbox and click the verification link.', $new_email ),
		'pending_email' => $new_email,
	);
}

/**
 * Change user password.
 *
 * Validates current password before allowing change.
 *
 * @param array $input Input with 'user_id', 'current_password', 'new_password', 'confirm_password'.
 * @return array|WP_Error Result or error.
 */
function extrachill_users_ability_change_password( $input ) {
	// Self-only: always operate on the authenticated user, ignoring any client-supplied user_id.
	$user_id = extrachill_users_resolve_self_user_id();
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$current_password = isset( $input['current_password'] ) ? $input['current_password'] : '';
	$new_password     = isset( $input['new_password'] ) ? $input['new_password'] : '';
	$confirm_password = isset( $input['confirm_password'] ) ? $input['confirm_password'] : '';

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	if ( empty( $current_password ) ) {
		return new WP_Error( 'missing_current_password', 'Current password is required.' );
	}

	if ( ! wp_check_password( $current_password, $user->user_pass, $user_id ) ) {
		return new WP_Error( 'incorrect_password', 'Current password is incorrect.' );
	}

	if ( empty( $new_password ) ) {
		return new WP_Error( 'missing_new_password', 'New password is required.' );
	}

	if ( $new_password !== $confirm_password ) {
		return new WP_Error( 'password_mismatch', 'New passwords do not match.' );
	}

	$result = wp_update_user(
		array(
			'ID'        => $user_id,
			'user_pass' => $new_password,
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'success' => true,
		'message' => 'Password changed successfully.',
	);
}
