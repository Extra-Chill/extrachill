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
			'description'         => __( 'Retrieve account settings for a user: name, display name, email, pending email.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'user_id' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_get_settings',
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
					'user_id'      => array( 'type' => 'integer' ),
					'first_name'   => array( 'type' => 'string' ),
					'last_name'    => array( 'type' => 'string' ),
					'display_name' => array( 'type' => 'string' ),
				),
				'required'   => array( 'user_id' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_update_settings',
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
					'user_id'   => array( 'type' => 'integer' ),
					'new_email' => array( 'type' => 'string' ),
				),
				'required'   => array( 'user_id', 'new_email' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_change_email',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
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
					'user_id'          => array( 'type' => 'integer' ),
					'current_password' => array( 'type' => 'string' ),
					'new_password'     => array( 'type' => 'string' ),
					'confirm_password' => array( 'type' => 'string' ),
				),
				'required'   => array( 'user_id', 'current_password', 'new_password', 'confirm_password' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_users_ability_change_password',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
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
 * @param array $input Input with 'user_id'.
 * @return array|WP_Error Settings data or error.
 */
function extrachill_users_ability_get_settings( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;

	if ( ! $user_id ) {
		return new WP_Error( 'missing_user_id', 'user_id is required.' );
	}

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	// Build display name options (same logic as the old PHP template).
	$display_name_options = array();
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

	// Pending email change.
	$pending_email      = null;
	$pending_email_data = get_user_meta( $user_id, '_new_user_email', true );
	if ( $pending_email_data && isset( $pending_email_data['newemail'] ) ) {
		$pending_email = $pending_email_data['newemail'];
	}

	return array(
		'user_id'              => $user_id,
		'first_name'           => $user->first_name,
		'last_name'            => $user->last_name,
		'display_name'         => $user->display_name,
		'display_name_options' => array_values( $display_name_options ),
		'email'                => $user->user_email,
		'pending_email'        => $pending_email,
	);
}

/**
 * Update user settings (account details).
 *
 * @param array $input Input with 'user_id' and optional name/display_name fields.
 * @return array|WP_Error Updated settings or error.
 */
function extrachill_users_ability_update_settings( $input ) {
	$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;

	if ( ! $user_id ) {
		return new WP_Error( 'missing_user_id', 'user_id is required.' );
	}

	$user = get_user_by( 'ID', $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', 'User not found.' );
	}

	$update_args = array( 'ID' => $user_id );
	$changed     = false;

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

	if ( ! $changed ) {
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

	// Return fresh settings data.
	return extrachill_users_ability_get_settings( array( 'user_id' => $user_id ) );
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
	$user_id   = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	$new_email = isset( $input['new_email'] ) ? sanitize_email( $input['new_email'] ) : '';

	if ( ! $user_id ) {
		return new WP_Error( 'missing_user_id', 'user_id is required.' );
	}

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
	$hash = md5( $new_email . time() . wp_rand() );
	$new_user_email = array(
		'hash'     => $hash,
		'newemail' => $new_email,
	);
	update_user_meta( $user_id, '_new_user_email', $new_user_email );

	// Build confirmation URL.
	$confirm_url = esc_url(
		admin_url(
			'profile.php?newuseremail=' . $hash
		)
	);

	// Send confirmation email.
	/* translators: Do not translate USERNAME, ADMIN_URL, EMAIL, SITENAME, SITEURL: those are placeholders. */
	$email_text = __(
		'Howdy ###USERNAME###,

Someone requested a change to the email address on your account.

Please click the following link to confirm this change:
###ADMIN_URL###

If you did not request this, you can safely ignore and delete this email.

This email was sent to ###EMAIL###

Regards,
###SITENAME###
###SITEURL###',
		'extrachill-users'
	);

	$email_text = str_replace( '###USERNAME###', $user->user_login, $email_text );
	$email_text = str_replace( '###ADMIN_URL###', $confirm_url, $email_text );
	$email_text = str_replace( '###EMAIL###', $new_email, $email_text );
	$email_text = str_replace( '###SITENAME###', wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ), $email_text );
	$email_text = str_replace( '###SITEURL###', home_url(), $email_text );

	$sent = wp_mail(
		$new_email,
		sprintf(
			/* translators: %s: site name */
			__( '[%s] Email Change Request', 'extrachill-users' ),
			wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
		),
		$email_text
	);

	if ( ! $sent ) {
		delete_user_meta( $user_id, '_new_user_email' );
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
	$user_id          = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	$current_password = isset( $input['current_password'] ) ? $input['current_password'] : '';
	$new_password     = isset( $input['new_password'] ) ? $input['new_password'] : '';
	$confirm_password = isset( $input['confirm_password'] ) ? $input['confirm_password'] : '';

	if ( ! $user_id ) {
		return new WP_Error( 'missing_user_id', 'user_id is required.' );
	}

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
