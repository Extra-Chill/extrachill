<?php
/**
 * Notification abilities.
 *
 * Read/write surface for the network notification substrate. Business logic
 * lives in inc/notifications/service.php; these abilities are thin wrappers
 * that resolve the acting user and delegate.
 *
 * Supersedes the community-only blob abilities
 * (extrachill/community-get-notifications etc.) by reading/writing the
 * network notification table instead of the per-user user_meta array.
 *
 * Parent epic: Extra-Chill/extrachill-community#82.
 *
 * @package ExtraChill\Users
 * @since 0.15.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_notification_abilities' );

/**
 * Register notification abilities.
 */
function extrachill_users_register_notification_abilities() {

	// ─── Get Notifications ────────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/get-notifications',
		array(
			'label'               => __( 'Get Notifications', 'extrachill-users' ),
			'description'         => __( 'List notifications for a user (newest first), with optional unread filter and pagination. Reads the network notification table.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'  => array(
						'type'        => 'integer',
						'description' => 'User ID. Defaults to current user.',
						'default'     => 0,
					),
					'unread'   => array(
						'type'        => 'boolean',
						'description' => 'Only return unread notifications.',
						'default'     => false,
					),
					'page'     => array(
						'type'        => 'integer',
						'description' => '1-indexed page number.',
						'default'     => 1,
					),
					'per_page' => array(
						'type'        => 'integer',
						'description' => 'Results per page (1-100).',
						'default'     => 50,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'       => array( 'type' => 'integer' ),
					'total'         => array( 'type' => 'integer' ),
					'unread_count'  => array( 'type' => 'integer' ),
					'page'          => array( 'type' => 'integer' ),
					'pages'         => array( 'type' => 'integer' ),
					'notifications' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_get_notifications',
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	// ─── Get Unread Count ─────────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/get-notification-unread-count',
		array(
			'label'               => __( 'Get Notification Unread Count', 'extrachill-users' ),
			'description'         => __( 'Return the number of unread notifications for a user. Powers the bell badge.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array(
						'type'        => 'integer',
						'description' => 'User ID. Defaults to current user.',
						'default'     => 0,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'      => array( 'type' => 'integer' ),
					'unread_count' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_get_notification_unread_count',
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	// ─── Mark Notifications Read ──────────────────────────────────────────────

	wp_register_ability(
		'extrachill/mark-notifications-read',
		array(
			'label'               => __( 'Mark Notifications Read', 'extrachill-users' ),
			'description'         => __( 'Mark a single notification (by id) or all unread notifications as read for a user.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'         => array(
						'type'        => 'integer',
						'description' => 'User ID. Defaults to current user.',
						'default'     => 0,
					),
					'notification_id' => array(
						'type'        => 'integer',
						'description' => 'Single notification ID to mark read. 0 marks ALL unread.',
						'default'     => 0,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer' ),
					'marked'  => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_mark_notifications_read',
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	// ─── Clear Notifications ──────────────────────────────────────────────────

	wp_register_ability(
		'extrachill/clear-notifications',
		array(
			'label'               => __( 'Clear Notifications', 'extrachill-users' ),
			'description'         => __( 'Delete read notifications older than one week for a user, or all notifications.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array(
						'type'        => 'integer',
						'description' => 'User ID. Defaults to current user.',
						'default'     => 0,
					),
					'all'     => array(
						'type'        => 'boolean',
						'description' => 'Delete ALL notifications (not just old read ones).',
						'default'     => false,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer' ),
					'removed' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_clear_notifications',
			'permission_callback' => 'is_user_logged_in',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => true,
				),
			),
		)
	);

	// ─── Get Notification Preferences ─────────────────────────────────────────

	wp_register_ability(
		'extrachill/get-notification-preferences',
		array(
			'label'               => __( 'Get Notification Preferences', 'extrachill-users' ),
			'description'         => __( 'Return a user\'s notification delivery preferences (e.g. whether unread-notification digest emails are enabled). Self-only.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'        => array( 'type' => 'integer' ),
					'emails_enabled' => array(
						'type'        => 'boolean',
						'description' => 'True when the user receives unread-notification digest emails.',
					),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_get_notification_preferences',
			// Self-only: returns the authenticated user's own preferences.
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

	// ─── Update Notification Preferences ──────────────────────────────────────

	wp_register_ability(
		'extrachill/update-notification-preferences',
		array(
			'label'               => __( 'Update Notification Preferences', 'extrachill-users' ),
			'description'         => __( 'Update a user\'s notification delivery preferences. Currently exposes the unread-notification digest-email opt-in toggle. Self-only.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'emails_enabled' => array(
						'type'        => 'boolean',
						'description' => 'Set true to receive unread-notification digest emails, false to opt out.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'        => array( 'type' => 'integer' ),
					'emails_enabled' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'extrachill_users_ability_update_notification_preferences',
			// Self-only: updates the authenticated user's own preferences.
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
}

// ─── Execute Callbacks ─────────────────────────────────────────────────────────

/**
 * Resolve the acting user ID from input, defaulting to the current user.
 *
 * Non-admins may only act on their own notifications; an explicit user_id that
 * differs from the current user requires the list_users capability.
 *
 * @param array $input Ability input.
 * @return int|WP_Error Resolved user ID, or WP_Error on permission failure.
 */
function extrachill_users_resolve_notification_user_id( array $input ) {
	$current = get_current_user_id();
	$user_id = ! empty( $input['user_id'] ) ? (int) $input['user_id'] : $current;

	if ( $user_id <= 0 ) {
		return new WP_Error( 'no_user', 'A valid user_id is required.', array( 'status' => 400 ) );
	}

	if ( $user_id !== $current && ! current_user_can( 'list_users' ) ) {
		return new WP_Error( 'forbidden', 'You can only access your own notifications.', array( 'status' => 403 ) );
	}

	return $user_id;
}

/**
 * Get notifications ability callback.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_get_notifications( array $input ) {
	$user_id = extrachill_users_resolve_notification_user_id( $input );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	return ec_users_get_notifications(
		$user_id,
		array(
			'unread'   => ! empty( $input['unread'] ),
			'page'     => isset( $input['page'] ) ? (int) $input['page'] : 1,
			'per_page' => isset( $input['per_page'] ) ? (int) $input['per_page'] : 50,
		)
	);
}

/**
 * Get notification unread count ability callback.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_get_notification_unread_count( array $input ) {
	$user_id = extrachill_users_resolve_notification_user_id( $input );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	return array(
		'user_id'      => $user_id,
		'unread_count' => ec_users_get_unread_count( $user_id ),
	);
}

/**
 * Mark notifications read ability callback.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_mark_notifications_read( array $input ) {
	$user_id = extrachill_users_resolve_notification_user_id( $input );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$notification_id = isset( $input['notification_id'] ) ? (int) $input['notification_id'] : 0;

	return array(
		'user_id' => $user_id,
		'marked'  => ec_users_mark_notifications_read( $user_id, $notification_id ),
	);
}

/**
 * Clear notifications ability callback.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_clear_notifications( array $input ) {
	$user_id = extrachill_users_resolve_notification_user_id( $input );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	return array(
		'user_id' => $user_id,
		'removed' => ec_users_clear_notifications( $user_id, ! empty( $input['all'] ) ),
	);
}

/**
 * Get notification preferences ability callback.
 *
 * Self-only: always operates on the authenticated user, ignoring any
 * client-supplied user_id (avoids IDOR over the Abilities /run endpoint).
 *
 * @return array|WP_Error
 */
function extrachill_users_ability_get_notification_preferences() {
	$user_id = extrachill_users_resolve_self_user_id();
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	return array(
		'user_id'        => $user_id,
		'emails_enabled' => ec_users_notification_emails_enabled( $user_id ),
	);
}

/**
 * Update notification preferences ability callback.
 *
 * Self-only. Persists the digest-email opt-in toggle through the canonical
 * setter ec_users_set_notification_emails_disabled() — the SAME setter the
 * one-click unsubscribe endpoint uses. The user-facing `emails_enabled` is
 * inverted into the internal DISABLED flag here.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_users_ability_update_notification_preferences( array $input ) {
	$user_id = extrachill_users_resolve_self_user_id();
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	if ( array_key_exists( 'emails_enabled', $input ) ) {
		$emails_enabled = filter_var( $input['emails_enabled'], FILTER_VALIDATE_BOOLEAN );
		ec_users_set_notification_emails_disabled( $user_id, ! $emails_enabled );
	}

	return array(
		'user_id'        => $user_id,
		'emails_enabled' => ec_users_notification_emails_enabled( $user_id ),
	);
}
