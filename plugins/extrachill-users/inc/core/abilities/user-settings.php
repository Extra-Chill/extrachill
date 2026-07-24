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

const EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY       = '_extrachill_default_event_location';
const EXTRACHILL_USERS_LOCAL_SCENE_META_KEY                  = '_extrachill_local_scene';
const EXTRACHILL_USERS_LOCAL_SCENE_VISIBILITY_META_KEY       = '_extrachill_local_scene_visibility';
const EXTRACHILL_USERS_LOCAL_SCENE_PROMPT_DISMISSED_META_KEY = '_extrachill_local_scene_prompt_dismissed';
const EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY   = '_extrachill_concert_history_visibility';
const EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY  = '_extrachill_event_attendance_visibility';

add_action( 'wp_abilities_api_init', 'extrachill_users_register_settings_abilities' );
add_action( 'user_register', 'extrachill_users_set_new_user_visibility_defaults' );
add_action( 'extrachill_users_visibility_changed', 'extrachill_users_purge_visibility_caches', 10, 4 );

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
					'first_name'                   => array( 'type' => 'string' ),
					'last_name'                    => array( 'type' => 'string' ),
					'display_name'                 => array( 'type' => 'string' ),
					'local_scene'                  => array(
						'type'        => 'string',
						'description' => __( 'Canonical Events location slug. Pass an empty string to clear it.', 'extrachill-users' ),
					),
					'local_scene_visibility'       => array(
						'type' => 'string',
						'enum' => array( 'public', 'private' ),
					),
					'concert_history_visibility'   => array(
						'type' => 'string',
						'enum' => array( 'public', 'private' ),
					),
					'event_attendance_visibility'  => array(
						'type' => 'string',
						'enum' => array( 'public', 'private' ),
					),
					'local_scene_prompt_dismissed' => array( 'type' => 'boolean' ),
					'default_event_location'       => array(
						'type'        => 'string',
						'description' => __( 'Compatibility alias for local_scene.', 'extrachill-users' ),
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
					'new_password'     => array(
						'type'      => 'string',
						'minLength' => 8,
					),
					'confirm_password' => array(
						'type'      => 'string',
						'minLength' => 8,
					),
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

	$local_scene = extrachill_users_get_local_scene( $user_id );
	if ( is_wp_error( $local_scene ) ) {
		$local_scene = null;
	}

	return array(
		'user_id'                      => $user_id,
		'first_name'                   => $user->first_name,
		'last_name'                    => $user->last_name,
		'display_name'                 => $user->display_name,
		'display_name_options'         => array_values( $display_name_options ),
		'email'                        => $user->user_email,
		'pending_email'                => $pending_email,
		'onboarding_completed'         => function_exists( 'ec_is_onboarding_complete' ) ? ec_is_onboarding_complete( $user_id ) : true,
		'local_scene'                  => $local_scene,
		'local_scene_visibility'       => extrachill_users_get_local_scene_visibility( $user_id ),
		'concert_history_visibility'   => extrachill_users_get_concert_history_visibility( $user_id ),
		'event_attendance_visibility'  => extrachill_users_get_event_attendance_visibility( $user_id ),
		'local_scene_prompt_dismissed' => extrachill_users_get_local_scene_prompt_dismissed( $user_id ),
		'default_event_location'       => $local_scene,
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
	$location_input_present = array_key_exists( 'local_scene', $input ) || array_key_exists( 'default_event_location', $input );

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

	if ( array_key_exists( 'local_scene_prompt_dismissed', $input ) ) {
		$changed = extrachill_users_set_local_scene_prompt_dismissed( $user_id, (bool) $input['local_scene_prompt_dismissed'] ) || $changed;
	}

	if ( $location_input_present ) {
		$location_input = array_key_exists( 'local_scene', $input ) ? $input['local_scene'] : $input['default_event_location'];
		$result         = extrachill_users_set_local_scene( $user_id, (string) $location_input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$changed = $result || $changed;

		if ( '' !== sanitize_title( (string) $location_input ) ) {
			$changed = extrachill_users_set_local_scene_prompt_dismissed( $user_id, false ) || $changed;
		}
	}

	if ( array_key_exists( 'local_scene_visibility', $input ) ) {
		$visibility = sanitize_key( (string) $input['local_scene_visibility'] );
		$result     = extrachill_users_set_local_scene_visibility( $user_id, $visibility );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $result ) {
			$changed = true;
		}
	}

	$visibility_setters = array(
		'concert_history_visibility'  => 'extrachill_users_set_concert_history_visibility',
		'event_attendance_visibility' => 'extrachill_users_set_event_attendance_visibility',
	);
	foreach ( $visibility_setters as $setting => $setter ) {
		if ( ! array_key_exists( $setting, $input ) ) {
			continue;
		}

		$result = $setter( $user_id, sanitize_key( (string) $input[ $setting ] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$changed = $result || $changed;
	}

	if ( ! $changed ) {
		if ( $location_input_present || array_key_exists( 'local_scene_visibility', $input ) || array_key_exists( 'local_scene_prompt_dismissed', $input ) || array_intersect_key( $visibility_setters, $input ) ) {
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
 * Resolve a canonical event location through the Users-owned preference helper.
 *
 * @param string $slug Location term slug.
 * @return array|WP_Error Resolved location or dependency/validation error.
 */
function extrachill_users_resolve_local_scene( string $slug ) {
	$result = extrachill_users_ability_event_locations(
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
			'user_event_locations_invalid_response',
			__( 'Canonical event locations returned an invalid response.', 'extrachill-users' ),
			array( 'status' => 502 )
		);
	}

	return $result['location'];
}

/**
 * Read and resolve a user's canonical Local Scene.
 *
 * The legacy default is a deterministic fallback only when canonical meta has
 * never been written. This preserves legacy data without requiring mass writes.
 *
 * @param int $user_id User ID.
 * @return array|null|WP_Error Resolved location, null, or resolution error.
 */
function extrachill_users_get_local_scene( int $user_id ) {
	if ( metadata_exists( 'user', $user_id, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY ) ) {
		$slug = (string) get_user_meta( $user_id, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY, true );
	} else {
		$slug = (string) get_user_meta( $user_id, EXTRACHILL_USERS_DEFAULT_EVENT_LOCATION_META_KEY, true );
	}

	return '' === $slug ? null : extrachill_users_resolve_local_scene( $slug );
}

/**
 * Persist a canonical Local Scene slug after authoritative resolution.
 *
 * @param int    $user_id User ID.
 * @param string $value Location slug input, or an empty string to clear.
 * @return bool|WP_Error Whether canonical storage changed, or an error.
 */
function extrachill_users_set_local_scene( int $user_id, string $value ) {
	$slug = sanitize_title( $value );
	if ( '' !== $slug ) {
		$location = extrachill_users_resolve_local_scene( $slug );
		if ( is_wp_error( $location ) ) {
			return $location;
		}
		$slug = $location['slug'];
	}

	$current = metadata_exists( 'user', $user_id, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY )
		? (string) get_user_meta( $user_id, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY, true )
		: null;
	if ( $slug === $current ) {
		return false;
	}

	update_user_meta( $user_id, EXTRACHILL_USERS_LOCAL_SCENE_META_KEY, $slug );
	return true;
}

/**
 * Get Local Scene visibility, defaulting missing legacy values to public.
 *
 * @param int $user_id User ID.
 * @return string public|private.
 */
function extrachill_users_get_local_scene_visibility( int $user_id ): string {
	return 'private' === get_user_meta( $user_id, EXTRACHILL_USERS_LOCAL_SCENE_VISIBILITY_META_KEY, true ) ? 'private' : 'public';
}

/**
 * Persist Local Scene profile visibility using the canonical meta semantics.
 *
 * @param int    $user_id User ID.
 * @param string $visibility Public or private.
 * @return bool|WP_Error Whether storage changed, or an error.
 */
function extrachill_users_set_local_scene_visibility( int $user_id, string $visibility ) {
	$visibility = sanitize_key( $visibility );
	if ( ! in_array( $visibility, array( 'public', 'private' ), true ) ) {
		return new WP_Error( 'invalid_local_scene_visibility', __( 'Local Scene visibility must be public or private.', 'extrachill-users' ), array( 'status' => 400 ) );
	}

	if ( extrachill_users_get_local_scene_visibility( $user_id ) === $visibility && metadata_exists( 'user', $user_id, EXTRACHILL_USERS_LOCAL_SCENE_VISIBILITY_META_KEY ) ) {
		return false;
	}

	update_user_meta( $user_id, EXTRACHILL_USERS_LOCAL_SCENE_VISIBILITY_META_KEY, $visibility );
	return true;
}

/**
 * Get concert history visibility, defaulting missing legacy values to public.
 *
 * @param int $user_id User ID.
 * @return string public|private.
 */
function extrachill_users_get_concert_history_visibility( int $user_id ): string {
	return 'private' === get_user_meta( $user_id, EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY, true ) ? 'private' : 'public';
}

/**
 * Get event attendance identity visibility, defaulting missing legacy values to public.
 *
 * @param int $user_id User ID.
 * @return string public|private.
 */
function extrachill_users_get_event_attendance_visibility( int $user_id ): string {
	return 'private' === get_user_meta( $user_id, EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY, true ) ? 'private' : 'public';
}

/**
 * Persist a visibility setting and publish its transition to downstream owners.
 *
 * @param int    $user_id   User ID.
 * @param string $setting   Public setting name.
 * @param string $meta_key  Canonical user meta key.
 * @param string $visibility Public or private.
 * @return bool|WP_Error Whether storage changed, or an error.
 */
function extrachill_users_set_visibility( int $user_id, string $setting, string $meta_key, string $visibility ) {
	$visibility = sanitize_key( $visibility );
	if ( ! in_array( $visibility, array( 'public', 'private' ), true ) ) {
		return new WP_Error( 'invalid_' . $setting, __( 'Visibility must be public or private.', 'extrachill-users' ), array( 'status' => 400 ) );
	}

	$old_visibility = 'private' === get_user_meta( $user_id, $meta_key, true ) ? 'private' : 'public';
	if ( $old_visibility === $visibility && metadata_exists( 'user', $user_id, $meta_key ) ) {
		return false;
	}

	$updated = update_user_meta( $user_id, $meta_key, $visibility );
	$stored  = (string) get_user_meta( $user_id, $meta_key, true );
	if ( false === $updated || $visibility !== $stored ) {
		return new WP_Error(
			'visibility_update_failed',
			__( 'Visibility could not be updated.', 'extrachill-users' ),
			array(
				'status'  => 500,
				'user_id' => $user_id,
				'setting' => $setting,
			)
		);
	}

	if ( $old_visibility !== $visibility ) {
		/**
		 * Fires after a Users-owned visibility setting changes effective value.
		 *
		 * @param int    $user_id       User ID.
		 * @param string $setting       Public setting name.
		 * @param string $old_visibility Previous public/private value.
		 * @param string $visibility     New public/private value.
		 */
		do_action( 'extrachill_users_visibility_changed', $user_id, $setting, $old_visibility, $visibility );
	}

	return true;
}

/**
 * Persist concert history visibility.
 *
 * @param int    $user_id User ID.
 * @param string $visibility Public or private.
 * @return bool|WP_Error Whether storage changed, or an error.
 */
function extrachill_users_set_concert_history_visibility( int $user_id, string $visibility ) {
	return extrachill_users_set_visibility( $user_id, 'concert_history_visibility', EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY, $visibility );
}

/**
 * Persist event attendance identity visibility.
 *
 * @param int    $user_id User ID.
 * @param string $visibility Public or private.
 * @return bool|WP_Error Whether storage changed, or an error.
 */
function extrachill_users_set_event_attendance_visibility( int $user_id, string $visibility ) {
	return extrachill_users_set_visibility( $user_id, 'event_attendance_visibility', EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY, $visibility );
}

/**
 * Set privacy-preserving defaults for registrations created by Extra Chill.
 *
 * @param int $user_id User ID.
 */
function extrachill_users_set_new_user_visibility_defaults( int $user_id ): void {
	if ( ! metadata_exists( 'user', $user_id, EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY ) ) {
		update_user_meta( $user_id, EXTRACHILL_USERS_CONCERT_HISTORY_VISIBILITY_META_KEY, 'private' );
	}
	if ( ! metadata_exists( 'user', $user_id, EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY ) ) {
		update_user_meta( $user_id, EXTRACHILL_USERS_EVENT_ATTENDANCE_VISIBILITY_META_KEY, 'private' );
	}
}

/**
 * Purge anonymous HTML caches that can project concert visibility.
 *
 * The domain owner selects affected sites and invokes the cache plugin's
 * generic current-blog purge hook. The cache layer remains unaware of users,
 * concerts, or visibility settings.
 *
 * @param int    $user_id       User ID.
 * @param string $setting       Visibility setting name.
 * @param string $old_visibility Previous visibility value.
 * @param string $new_visibility New visibility value.
 */
function extrachill_users_purge_visibility_caches( int $user_id, string $setting, string $old_visibility, string $new_visibility ): void {
	if ( $old_visibility === $new_visibility ) {
		return;
	}

	$blog_ids = function_exists( 'ec_get_blog_id' )
		? array( (int) ec_get_blog_id( 'community' ), (int) ec_get_blog_id( 'events' ) )
		: array();

	/**
	 * Filters the site IDs whose anonymous HTML can project Users visibility.
	 *
	 * @param int[]  $blog_ids Affected site IDs.
	 * @param int    $user_id User ID.
	 * @param string $setting Changed visibility setting.
	 */
	$blog_ids = apply_filters( 'extrachill_users_visibility_cache_blog_ids', $blog_ids, $user_id, $setting );
	$blog_ids = array_unique(
		array_filter(
			array_map( 'intval', (array) $blog_ids ),
			static function ( int $blog_id ): bool {
				return $blog_id > 0 && (bool) get_site( $blog_id );
			}
		)
	);

	foreach ( $blog_ids as $blog_id ) {
		$switched = get_current_blog_id() !== $blog_id;
		if ( $switched ) {
			switch_to_blog( $blog_id );
		}

		try {
			do_action( 'extrachill_cache_flush' );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
}

/**
 * Check whether the user dismissed the Local Scene prompt.
 *
 * @param int $user_id User ID.
 * @return bool Whether the prompt was dismissed.
 */
function extrachill_users_get_local_scene_prompt_dismissed( int $user_id ): bool {
	return '1' === get_user_meta( $user_id, EXTRACHILL_USERS_LOCAL_SCENE_PROMPT_DISMISSED_META_KEY, true );
}

/**
 * Persist the private Local Scene prompt dismissal state.
 *
 * False is represented by absent meta so new and reset users share the same default.
 *
 * @param int  $user_id   User ID.
 * @param bool $dismissed Whether the prompt was dismissed.
 * @return bool Whether storage changed.
 */
function extrachill_users_set_local_scene_prompt_dismissed( int $user_id, bool $dismissed ): bool {
	$current = extrachill_users_get_local_scene_prompt_dismissed( $user_id );
	$exists  = metadata_exists( 'user', $user_id, EXTRACHILL_USERS_LOCAL_SCENE_PROMPT_DISMISSED_META_KEY );

	if ( $dismissed === $current && ( $dismissed || ! $exists ) ) {
		return false;
	}

	if ( $dismissed ) {
		update_user_meta( $user_id, EXTRACHILL_USERS_LOCAL_SCENE_PROMPT_DISMISSED_META_KEY, '1' );
	} else {
		delete_user_meta( $user_id, EXTRACHILL_USERS_LOCAL_SCENE_PROMPT_DISMISSED_META_KEY );
	}

	return true;
}

/**
 * Compatibility resolver retained for existing consumers.
 *
 * @param string $slug Location term slug.
 * @return array|WP_Error Resolved location or dependency/validation error.
 */
function extrachill_users_resolve_default_event_location( string $slug ) {
	return extrachill_users_resolve_local_scene( $slug );
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

	$password_validation = extrachill_users_validate_password( $new_password );
	if ( is_wp_error( $password_validation ) ) {
		return $password_validation;
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
