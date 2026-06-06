<?php
/**
 * Network notification service.
 *
 * The network-callable SUBSTRATE for notifications. Because extrachill-users is
 * a Network:true plugin, ec_users_notify() is loaded on every site and ANY site
 * (community, events, artist, shop) can call it to enqueue a notification. The
 * back-compat shim keeps the legacy do_action( 'extrachill_notify' ) producers
 * working and now routes them into the table from every site.
 *
 * All reads/writes target the base_prefix table from inc/notifications/db.php,
 * so no switch_to_blog is needed — it is the same physical table everywhere.
 *
 * Parent epic: Extra-Chill/extrachill-community#82.
 *
 * @package ExtraChill\Users
 * @since 0.15.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/db.php';

/**
 * Object-cache group + TTL for the per-user unread notification count.
 *
 * The bell renders on EVERY page load for EVERY logged-in user, so the unread
 * COUNT(*) was running network-wide on every request. Redis object cache is
 * active on this site, so we cache the count per user under a stable key and
 * bust it on every write (insert / mark-read / clear). TTL is a backstop only —
 * writes keep the badge correct; the TTL just bounds drift if a key is ever
 * missed.
 */
const EC_USERS_NOTIFICATIONS_CACHE_GROUP = 'ec_notifications';
const EC_USERS_UNREAD_COUNT_CACHE_TTL    = 5 * MINUTE_IN_SECONDS;

/**
 * Build the per-user unread-count object-cache key.
 *
 * @param int $user_id Recipient user ID.
 * @return string Cache key (e.g. 'unread_42').
 */
function ec_users_unread_count_cache_key( int $user_id ): string {
	return 'unread_' . $user_id;
}

/**
 * Invalidate the cached unread count for one or more users.
 *
 * Called by every writer (insert / mark-read / clear) so the bell badge is
 * never stale. Accepts an array because ec_users_notify() can target multiple
 * recipients in a single call — each recipient's key must be busted.
 *
 * @param int|int[] $user_ids Single user ID or array of user IDs.
 */
function ec_users_flush_unread_count_cache( $user_ids ): void {
	if ( ! is_array( $user_ids ) ) {
		$user_ids = array( $user_ids );
	}

	foreach ( $user_ids as $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			continue;
		}
		wp_cache_delete( ec_users_unread_count_cache_key( $user_id ), EC_USERS_NOTIFICATIONS_CACHE_GROUP );
	}
}

// ─── Self-Healing Install ───────────────────────────────────────────────────────

/**
 * Ensure the network notification table exists and is on the current schema.
 *
 * The substrate lives in {base_prefix}ec_notifications (one table for the whole
 * network). This guard runs on init so the table self-heals on EVERY site — any
 * site that loads this Network:true plugin creates or upgrades the table the
 * first time it is missing/outdated, without waiting for a (re)activation.
 * Re-runs dbDelta whenever EXTRACHILL_USERS_NOTIFICATIONS_SCHEMA_VERSION is
 * bumped. The site-option flag is also set by activation, so this is a no-op
 * after the first install.
 */
function ec_users_maybe_install_notifications_table() {
	$current_version = defined( 'EXTRACHILL_USERS_NOTIFICATIONS_SCHEMA_VERSION' )
		? (string) EXTRACHILL_USERS_NOTIFICATIONS_SCHEMA_VERSION
		: '1';

	$installed_version = (string) get_site_option( 'extrachill_users_notifications_schema_version', '' );

	if ( $installed_version === $current_version ) {
		return;
	}

	extrachill_users_install_notifications_table();

	update_site_option( 'extrachill_users_notifications_schema_version', $current_version );
}
add_action( 'init', 'ec_users_maybe_install_notifications_table' );

// ─── Entry Point ───────────────────────────────────────────────────────────────

/**
 * Create one or more notifications.
 *
 * The single network-wide entry point. Callable from any site. Validates and
 * enriches the payload, then inserts one row per recipient into the base_prefix
 * notification table.
 *
 * @param int|int[] $user_ids Single recipient ID or array of recipient IDs.
 * @param array     $data {
 *     Notification payload.
 *
 *     @type int    $actor_id Required. User ID who triggered the notification.
 *     @type string $type     Required. Type identifier (e.g. 'reply', 'mention').
 *     @type string $link     Required. URL to the notification target.
 *     @type string $title    Required. Human-readable title/subject.
 *     @type int    $item_id  Optional. Related object ID (post/topic/reply/event).
 * }
 * @return int Number of notification rows inserted.
 */
function ec_users_notify( $user_ids, array $data ): int {
	global $wpdb;

	if ( ! is_array( $user_ids ) ) {
		$user_ids = array( $user_ids );
	}

	// Validate required fields.
	$actor_id = isset( $data['actor_id'] ) ? (int) $data['actor_id'] : 0;
	$type     = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : '';
	$link     = isset( $data['link'] ) ? esc_url_raw( (string) $data['link'] ) : '';
	$title    = isset( $data['title'] ) ? (string) $data['title'] : '';

	// Back-compat: the legacy payload used 'topic_title' for the title field.
	if ( '' === $title && ! empty( $data['topic_title'] ) ) {
		$title = (string) $data['topic_title'];
	}

	$title = sanitize_text_field( $title );

	if ( ! $actor_id || '' === $type || '' === $link || '' === $title ) {
		return 0;
	}

	if ( ! get_userdata( $actor_id ) ) {
		return 0;
	}

	$item_id = isset( $data['item_id'] ) ? (int) $data['item_id'] : 0;
	if ( $item_id <= 0 && ! empty( $data['post_id'] ) ) {
		// Back-compat: legacy callers passed the related object as 'post_id'.
		$item_id = (int) $data['post_id'];
	}

	$table        = extrachill_users_notifications_table_name();
	$created_at   = current_time( 'mysql', true );
	$inserted     = 0;
	$notified_ids = array();

	foreach ( $user_ids as $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			continue;
		}

		$result = $wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'actor_id'   => $actor_id,
				'type'       => $type,
				'title'      => $title,
				'link'       => $link,
				'item_id'    => $item_id > 0 ? $item_id : null,
				'is_read'    => 0,
				'created_at' => $created_at,
			),
			array( '%d', '%d', '%s', '%s', '%s', $item_id > 0 ? '%d' : null, '%d', '%s' )
		);

		if ( $result ) {
			++$inserted;
			$notified_ids[] = $user_id;
		}
	}

	// Bust each recipient's cached unread count so the badge reflects the new row.
	if ( $notified_ids ) {
		ec_users_flush_unread_count_cache( $notified_ids );
	}

	return $inserted;
}

// ─── Read / CRUD ─────────────────────────────────────────────────────────────

/**
 * Enrich a raw notification row with actor display data for rendering.
 *
 * @param array $row Raw DB row (associative).
 * @return array Enriched notification.
 */
function ec_users_enrich_notification( array $row ): array {
	$actor_id           = isset( $row['actor_id'] ) ? (int) $row['actor_id'] : 0;
	$actor_display_name = '';
	$actor_profile_link = '';

	if ( $actor_id > 0 ) {
		$actor = get_userdata( $actor_id );
		if ( $actor ) {
			$actor_display_name = $actor->display_name;
			if ( function_exists( 'extrachill_get_user_community_profile_url' ) ) {
				$actor_profile_link = (string) extrachill_get_user_community_profile_url( $actor_id );
			}
		}
	}

	return array(
		'id'                 => isset( $row['id'] ) ? (int) $row['id'] : 0,
		'user_id'            => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
		'actor_id'           => $actor_id,
		'actor_display_name' => $actor_display_name,
		'actor_profile_link' => $actor_profile_link,
		'type'               => isset( $row['type'] ) ? (string) $row['type'] : '',
		'title'              => isset( $row['title'] ) ? (string) $row['title'] : '',
		'link'               => isset( $row['link'] ) ? (string) $row['link'] : '',
		'item_id'            => empty( $row['item_id'] ) ? null : (int) $row['item_id'],
		'read'               => ! empty( $row['is_read'] ),
		'is_read'            => (int) ( ! empty( $row['is_read'] ) ),
		'time'               => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
		'created_at'         => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
	);
}

/**
 * Get notifications for a user (newest first, paginated).
 *
 * @param int   $user_id Recipient user ID.
 * @param array $args {
 *     Optional query arguments.
 *
 *     @type bool $unread   Only return unread notifications. Default false.
 *     @type int  $page     1-indexed page number. Default 1.
 *     @type int  $per_page Results per page (1-100). Default 50.
 * }
 * @return array{ user_id:int, total:int, unread_count:int, page:int, pages:int, notifications:array }
 */
function ec_users_get_notifications( int $user_id, array $args = array() ): array {
	global $wpdb;

	$defaults = array(
		'unread'   => false,
		'page'     => 1,
		'per_page' => 50,
	);
	$args     = wp_parse_args( $args, $defaults );

	$unread_only = ! empty( $args['unread'] );
	$per_page    = max( 1, min( 100, (int) $args['per_page'] ) );
	$page        = max( 1, (int) $args['page'] );

	$table = extrachill_users_notifications_table_name();

	if ( $user_id <= 0 ) {
		return array(
			'user_id'       => $user_id,
			'total'         => 0,
			'unread_count'  => 0,
			'page'          => $page,
			'pages'         => 0,
			'notifications' => array(),
		);
	}

	$unread_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
			$user_id
		)
	);

	if ( $unread_only ) {
		$total = $unread_count;
	} else {
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				$user_id
			)
		);
	}

	$pages  = (int) ceil( $total / $per_page );
	$page   = $pages > 0 ? min( $page, $pages ) : 1;
	$offset = ( $page - 1 ) * $per_page;

	if ( 0 === $total ) {
		return array(
			'user_id'       => $user_id,
			'total'         => 0,
			'unread_count'  => $unread_count,
			'page'          => $page,
			'pages'         => 0,
			'notifications' => array(),
		);
	}

	if ( $unread_only ) {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND is_read = 0 ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				$user_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
	} else {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				$user_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
	}

	$notifications = array();
	foreach ( (array) $rows as $row ) {
		$notifications[] = ec_users_enrich_notification( $row );
	}

	return array(
		'user_id'       => $user_id,
		'total'         => $total,
		'unread_count'  => $unread_count,
		'page'          => $page,
		'pages'         => $pages,
		'notifications' => $notifications,
	);
}

/**
 * Count unread notifications for a user.
 *
 * @param int $user_id Recipient user ID.
 * @return int
 */
function ec_users_get_unread_count( int $user_id ): int {
	global $wpdb;

	if ( $user_id <= 0 ) {
		return 0;
	}

	$cache_key = ec_users_unread_count_cache_key( $user_id );
	$cached    = wp_cache_get( $cache_key, EC_USERS_NOTIFICATIONS_CACHE_GROUP );
	if ( false !== $cached ) {
		return (int) $cached;
	}

	$table = extrachill_users_notifications_table_name();

	$count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
			$user_id
		)
	);

	wp_cache_set( $cache_key, $count, EC_USERS_NOTIFICATIONS_CACHE_GROUP, EC_USERS_UNREAD_COUNT_CACHE_TTL );

	return $count;
}

/**
 * Mark notifications as read for a user.
 *
 * Marks a single notification (when $notification_id given) or all unread
 * notifications for the user.
 *
 * @param int $user_id         Recipient user ID.
 * @param int $notification_id Optional. Single notification ID to mark read.
 *                             0 marks ALL unread for the user. Default 0.
 * @return int Number of rows updated.
 */
function ec_users_mark_notifications_read( int $user_id, int $notification_id = 0 ): int {
	global $wpdb;

	if ( $user_id <= 0 ) {
		return 0;
	}

	$table = extrachill_users_notifications_table_name();

	if ( $notification_id > 0 ) {
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET is_read = 1 WHERE id = %d AND user_id = %d AND is_read = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				$notification_id,
				$user_id
			)
		);
	} else {
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET is_read = 1 WHERE user_id = %d AND is_read = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				$user_id
			)
		);
	}

	if ( false !== $updated && (int) $updated > 0 ) {
		ec_users_flush_unread_count_cache( $user_id );
	}

	return false === $updated ? 0 : (int) $updated;
}

/**
 * Clear notifications for a user.
 *
 * Deletes ALL notifications when $all is true; otherwise deletes read
 * notifications older than one week (matching the legacy cleanup semantics).
 *
 * @param int  $user_id Recipient user ID.
 * @param bool $all     Delete ALL notifications, not just old read ones.
 * @return int Number of rows removed.
 */
function ec_users_clear_notifications( int $user_id, bool $all = false ): int {
	global $wpdb;

	if ( $user_id <= 0 ) {
		return 0;
	}

	$table = extrachill_users_notifications_table_name();

	if ( $all ) {
		$removed = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				$user_id
			)
		);
	} else {
		$one_week_ago = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
		$removed      = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d AND is_read = 1 AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				$user_id,
				$one_week_ago
			)
		);
	}

	if ( false !== $removed && (int) $removed > 0 ) {
		ec_users_flush_unread_count_cache( $user_id );
	}

	return false === $removed ? 0 : (int) $removed;
}

// ─── Back-Compat Shim ──────────────────────────────────────────────────────────

/**
 * Forward the legacy extrachill_notify action into the new substrate.
 *
 * Registered network-wide so the existing community producers (forum replies +
 * mentions) keep working AND now write into the table from every site. The
 * community plugin only FIRES do_action( 'extrachill_notify' ) — it does not
 * register a competing handler — so this substrate handler is the sole consumer
 * of the action and there is no double-write.
 *
 * @param int|int[] $user_ids Recipient ID(s).
 * @param mixed     $data     Notification payload (legacy shape supported).
 */
function ec_users_handle_notify_action( $user_ids, $data ) {
	if ( ! is_array( $data ) ) {
		return;
	}

	ec_users_notify( $user_ids, $data );
}
add_action( 'extrachill_notify', 'ec_users_handle_notify_action', 10, 2 );
