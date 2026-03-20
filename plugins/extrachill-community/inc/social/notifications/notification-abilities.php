<?php
/**
 * Notification Abilities
 *
 * Abilities-first primitives for the community notification system.
 * Colocated with notification-handler.php, notification-cleanup.php,
 * capture-replies.php, and capture-mentions.php.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_community_register_notification_abilities' );

/**
 * Register notification abilities.
 */
function extrachill_community_register_notification_abilities() {

	wp_register_ability(
		'extrachill/community-get-notifications',
		array(
			'label'               => __( 'Get Notifications', 'extrachill-community' ),
			'description'         => __( 'List notifications for a user with optional filtering.', 'extrachill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer', 'description' => 'User ID (defaults to current user)' ),
					'unread'  => array( 'type' => 'boolean', 'description' => 'Only return unread notifications' ),
					'limit'   => array( 'type' => 'integer', 'description' => 'Max notifications to return (default 50)' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'       => array( 'type' => 'integer' ),
					'total'         => array( 'type' => 'integer' ),
					'unread_count'  => array( 'type' => 'integer' ),
					'notifications' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_get_notifications',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/community-mark-notifications-read',
		array(
			'label'               => __( 'Mark Notifications Read', 'extrachill-community' ),
			'description'         => __( 'Mark all notifications as read for a user.', 'extrachill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer', 'description' => 'User ID (defaults to current user)' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer' ),
					'marked'  => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_mark_notifications_read',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/community-clear-notifications',
		array(
			'label'               => __( 'Clear Notifications', 'extrachill-community' ),
			'description'         => __( 'Delete read notifications older than one week for a user, or all notifications.', 'extrachill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer', 'description' => 'User ID (defaults to current user)' ),
					'all'     => array( 'type' => 'boolean', 'description' => 'Delete ALL notifications (not just old read ones)' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer' ),
					'removed' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_clear_notifications',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => true,
				),
			),
		)
	);
}

// ─── Execute callbacks ─────────────────────────────────────────────────────────

/**
 * Get notifications for a user.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_get_notifications( $input ) {
	$user_id = extrachill_community_resolve_user_id( $input );
	if ( ! $user_id ) {
		return new WP_Error( 'missing_user', 'A valid user_id is required.' );
	}

	$unread_only = ! empty( $input['unread'] );
	$limit       = isset( $input['limit'] ) ? (int) $input['limit'] : 50;

	$ctx = extrachill_community_switch_to_community_blog();

	try {
		$notifications = get_user_meta( $user_id, 'extrachill_notifications', true );
		$notifications = is_array( $notifications ) ? $notifications : array();

		// Reverse so newest first.
		$notifications = array_reverse( $notifications );

		$unread_count = 0;
		foreach ( $notifications as $n ) {
			if ( empty( $n['read'] ) ) {
				++$unread_count;
			}
		}

		if ( $unread_only ) {
			$notifications = array_values(
				array_filter(
					$notifications,
					function ( $n ) {
						return empty( $n['read'] );
					}
				)
			);
		}

		$total         = count( $notifications );
		$notifications = array_slice( $notifications, 0, $limit );

		return array(
			'user_id'       => $user_id,
			'total'         => $total,
			'unread_count'  => $unread_count,
			'notifications' => $notifications,
		);
	} finally {
		if ( $ctx['switched'] ) {
			restore_current_blog();
		}
	}
}

/**
 * Mark all notifications as read for a user.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_mark_notifications_read( $input ) {
	$user_id = extrachill_community_resolve_user_id( $input );
	if ( ! $user_id ) {
		return new WP_Error( 'missing_user', 'A valid user_id is required.' );
	}

	$ctx = extrachill_community_switch_to_community_blog();

	try {
		$notifications = get_user_meta( $user_id, 'extrachill_notifications', true );
		$notifications = is_array( $notifications ) ? $notifications : array();

		$marked = 0;
		foreach ( $notifications as &$notification ) {
			if ( empty( $notification['read'] ) ) {
				$notification['read']        = true;
				$notification['viewed_time'] = current_time( 'mysql' );
				++$marked;
			}
		}
		unset( $notification );

		update_user_meta( $user_id, 'extrachill_notifications', $notifications );

		return array(
			'user_id' => $user_id,
			'marked'  => $marked,
		);
	} finally {
		if ( $ctx['switched'] ) {
			restore_current_blog();
		}
	}
}

/**
 * Clear old/read or all notifications for a user.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_clear_notifications( $input ) {
	$user_id = extrachill_community_resolve_user_id( $input );
	if ( ! $user_id ) {
		return new WP_Error( 'missing_user', 'A valid user_id is required.' );
	}

	$clear_all = ! empty( $input['all'] );

	$ctx = extrachill_community_switch_to_community_blog();

	try {
		$notifications = get_user_meta( $user_id, 'extrachill_notifications', true );
		$notifications = is_array( $notifications ) ? $notifications : array();
		$before_count  = count( $notifications );

		if ( $clear_all ) {
			$notifications = array();
		} else {
			$one_week_ago  = strtotime( '-1 week', current_time( 'timestamp' ) );
			$notifications = array_values(
				array_filter(
					$notifications,
					function ( $n ) use ( $one_week_ago ) {
						if ( ! empty( $n['read'] ) ) {
							if ( ! empty( $n['viewed_time'] ) ) {
								return strtotime( $n['viewed_time'] ) > $one_week_ago;
							}
							return false;
						}
						return true;
					}
				)
			);
		}

		update_user_meta( $user_id, 'extrachill_notifications', $notifications );

		return array(
			'user_id' => $user_id,
			'removed' => $before_count - count( $notifications ),
		);
	} finally {
		if ( $ctx['switched'] ) {
			restore_current_blog();
		}
	}
}
