<?php
/**
 * Network notification service.
 *
 * The network-callable SUBSTRATE for notifications. Because extrachill-users is
 * a Network:true plugin, ec_users_notify_with_receipts() is loaded on every site
 * and any site can use an explicit producer and idempotency key to enqueue a
 * notification safely.
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
 * never stale. Accepts an array because notification producers can target
 * multiple recipients in a single call — each recipient's key must be busted.
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

	if ( extrachill_users_install_notifications_table() ) {
		update_site_option( 'extrachill_users_notifications_schema_version', $current_version );
	}
}
add_action( 'init', 'ec_users_maybe_install_notifications_table' );

// ─── Entry Point ───────────────────────────────────────────────────────────────

/**
 * Create notifications and return a per-recipient delivery receipt.
 *
 * Producers that need retry safety pass both `producer` and `idempotency_key`.
 * Their exact pair is hashed into the compact delivery key protected by the
 * table's unique (user_id, delivery_key) index. Concurrent calls therefore
 * converge on one row per recipient while unrelated producers remain isolated.
 * Internal and external producers must pass both fields.
 *
 * An idempotent producer that delivers its own email may also pass
 * `producer_owns_email => true`. The insert atomically persists that ownership
 * and stamps emailed_at equal to created_at, keeping the notification unread and
 * visible while excluding it from the generic email sweep. A crash after this
 * insert but before downstream
 * queue admission leaves the suppressed receipt in place; producers should call
 * ec_users_release_notification_receipt() only after an explicit admission
 * failure, then retry the complete operation.
 *
 * Requested IDs are normalized to a unique recipient set. Each receipt has an
 * inserted, existing, or failed status. A notification row ID is returned only
 * after an insert or an exact producer/key replay has been verified.
 *
 * @param int|int[] $user_ids Single recipient ID or array of recipient IDs.
 * @param array     $data {
 *     Notification payload.
 *
 *     @type int    $actor_id       Required. User ID who triggered the notification.
 *     @type string $type           Required. Type identifier (e.g. 'reply', 'mention').
 *     @type string $link           Required. URL to the notification target.
 *     @type string $title          Required. Human-readable title/subject.
 *     @type int    $item_id        Optional. Related object ID (post/topic/reply/event).
 *     @type string $producer        Required producer namespace. Must be paired
 *                                   with idempotency_key.
 *     @type string $idempotency_key Required producer-owned replay key. Must be
 *                                   paired with producer.
 *     @type bool   $producer_owns_email Optional. When true, the producer owns
 *                                       email delivery for this receipt. Requires
 *                                       producer and idempotency_key.
 * }
 * @return array{requested:int, inserted:int, existing:int, failed:int, recipients:array<int,array{user_id:int,status:string,notification_id:?int,error:?string}>}
 */
function ec_users_notify_with_receipts( $user_ids, array $data ): array {
	global $wpdb;

	if ( ! is_array( $user_ids ) ) {
		$user_ids = array( $user_ids );
	}

	$user_ids = array_values( array_unique( array_map( 'intval', $user_ids ) ) );
	$receipt  = array(
		'requested'  => count( $user_ids ),
		'inserted'   => 0,
		'existing'   => 0,
		'failed'     => 0,
		'recipients' => array(),
	);

	// Validate required fields.
	$actor_id = isset( $data['actor_id'] ) ? (int) $data['actor_id'] : 0;
	$type     = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : '';
	$link     = isset( $data['link'] ) ? esc_url_raw( (string) $data['link'] ) : '';
	$title    = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';

	$producer        = isset( $data['producer'] ) ? strtolower( trim( (string) $data['producer'] ) ) : '';
	$idempotency_key = isset( $data['idempotency_key'] ) ? trim( (string) $data['idempotency_key'] ) : '';
	$has_producer    = '' !== $producer;
	$has_key         = '' !== $idempotency_key;
	$owns_email      = true === ( $data['producer_owns_email'] ?? false );
	$payload_error   = null;

	if ( ! $actor_id || '' === $type || '' === $link || '' === $title ) {
		$payload_error = 'invalid_payload';
	} elseif ( ! get_userdata( $actor_id ) ) {
		$payload_error = 'invalid_actor';
	} elseif ( $has_producer !== $has_key ) {
		$payload_error = 'incomplete_idempotency';
	} elseif ( $has_producer && ( strlen( $producer ) > 64 || ! preg_match( '/^[a-z0-9][a-z0-9._\/-]*$/', $producer ) ) ) {
		$payload_error = 'invalid_producer';
	} elseif ( $has_key && ( strlen( $idempotency_key ) > 191 || preg_match( '/[\x00-\x1F\x7F]/', $idempotency_key ) ) ) {
		$payload_error = 'invalid_idempotency_key';
	} elseif ( $owns_email && ( ! $has_producer || ! $has_key ) ) {
		$payload_error = 'email_ownership_requires_idempotency';
	} elseif ( ! $has_producer ) {
		$payload_error = 'incomplete_idempotency';
	}

	if ( null !== $payload_error ) {
		foreach ( $user_ids as $user_id ) {
			$receipt['recipients'][ $user_id ] = ec_users_notification_failed_receipt( $user_id, $payload_error );
			++$receipt['failed'];
		}

		return $receipt;
	}

	$item_id = isset( $data['item_id'] ) ? (int) $data['item_id'] : 0;

	$table        = extrachill_users_notifications_table_name();
	$created_at   = current_time( 'mysql', true );
	$notified_ids = array();
	$delivery_key = $has_producer ? hash( 'sha256', $producer . "\0" . $idempotency_key ) : null;

	foreach ( $user_ids as $user_id ) {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			$receipt['recipients'][ $user_id ] = ec_users_notification_failed_receipt( $user_id, 'invalid_user' );
			++$receipt['failed'];
			continue;
		}

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (user_id, actor_id, type, title, link, item_id, is_read, created_at, emailed_at, producer_owns_email, producer, idempotency_key, delivery_key) VALUES (%d, %d, %s, %s, %s, NULLIF(%d, 0), 0, %s, NULLIF(%s, ''), %d, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				$user_id,
				$actor_id,
				$type,
				$title,
				$link,
				$item_id,
				$created_at,
				$owns_email ? $created_at : '',
				(int) $owns_email,
				$producer,
				$idempotency_key,
				$delivery_key
			)
		);

		if ( false === $result ) {
			$receipt['recipients'][ $user_id ] = ec_users_notification_failed_receipt( $user_id, 'insert_failed' );
			++$receipt['failed'];
			continue;
		}

		$notification_id = (int) $wpdb->insert_id;
		$status          = 'inserted';

		if ( 1 !== (int) $result ) {
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, producer, idempotency_key, producer_owns_email FROM {$table} WHERE user_id = %d AND delivery_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
					$user_id,
					$delivery_key
				),
				ARRAY_A
			);

			if ( ! $existing || $producer !== $existing['producer'] || $idempotency_key !== $existing['idempotency_key'] ) {
				$receipt['recipients'][ $user_id ] = ec_users_notification_failed_receipt( $user_id, 'insert_failed' );
				++$receipt['failed'];
				continue;
			}
			if ( (int) $owns_email !== (int) $existing['producer_owns_email'] ) {
				$receipt['recipients'][ $user_id ] = ec_users_notification_failed_receipt( $user_id, 'idempotency_contract_mismatch' );
				++$receipt['failed'];
				continue;
			}

			$status          = 'existing';
			$notification_id = (int) $existing['id'];
		}

		if ( $notification_id <= 0 ) {
			$receipt['recipients'][ $user_id ] = ec_users_notification_failed_receipt( $user_id, 'row_id_unavailable' );
			++$receipt['failed'];
			continue;
		}

		$receipt['recipients'][ $user_id ] = array(
			'user_id'         => $user_id,
			'status'          => $status,
			'notification_id' => $notification_id,
			'error'           => null,
		);
		++$receipt[ $status ];

		if ( 'inserted' === $status ) {
			$notified_ids[] = $user_id;
		}
	}

	// Bust each recipient's cached unread count so the badge reflects the new row.
	if ( $notified_ids ) {
		ec_users_flush_unread_count_cache( $notified_ids );
	}

	return $receipt;
}

/**
 * Release one producer-owned notification receipt after queue admission fails.
 *
 * The delete is bounded by the receipt ID, recipient, producer, idempotency key,
 * delivery digest, authoritative email-ownership marker, and unread state. It cannot
 * release a normal idempotent notification or another producer's receipt.
 * Call this only for an explicit downstream queue-admission failure. It cannot
 * repair a process crash between notification insertion and learning the queue
 * result, and releasing after successful admission could permit duplicate work.
 *
 * @param int    $notification_id Notification ID returned in the receipt.
 * @param int    $user_id         Receipt recipient ID.
 * @param string $producer        Exact producer namespace used for insertion.
 * @param string $idempotency_key Exact producer replay key used for insertion.
 * @return bool True only when the exact producer-owned receipt was deleted.
 */
function ec_users_release_notification_receipt( int $notification_id, int $user_id, string $producer, string $idempotency_key ): bool {
	global $wpdb;

	$producer        = strtolower( trim( $producer ) );
	$idempotency_key = trim( $idempotency_key );

	if (
		$notification_id <= 0
		|| $user_id <= 0
		|| '' === $producer
		|| '' === $idempotency_key
		|| strlen( $producer ) > 64
		|| ! preg_match( '/^[a-z0-9][a-z0-9._\/-]*$/', $producer )
		|| strlen( $idempotency_key ) > 191
		|| preg_match( '/[\x00-\x1F\x7F]/', $idempotency_key )
	) {
		return false;
	}

	$table        = extrachill_users_notifications_table_name();
	$delivery_key = hash( 'sha256', $producer . "\0" . $idempotency_key );
	$deleted      = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE id = %d AND user_id = %d AND producer = %s AND idempotency_key = %s AND delivery_key = %s AND producer_owns_email = 1 AND is_read = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
			$notification_id,
			$user_id,
			$producer,
			$idempotency_key,
			$delivery_key
		)
	);

	if ( 1 !== (int) $deleted ) {
		return false;
	}

	ec_users_flush_unread_count_cache( $user_id );

	return true;
}

/**
 * Build a failed per-recipient delivery receipt.
 *
 * @param int    $user_id Recipient ID as requested.
 * @param string $error   Stable failure code.
 * @return array{user_id:int,status:string,notification_id:null,error:string}
 */
function ec_users_notification_failed_receipt( int $user_id, string $error ): array {
	return array(
		'user_id'         => $user_id,
		'status'          => 'failed',
		'notification_id' => null,
		'error'           => $error,
	);
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
 *     @type bool $exclude_producer_owned_email Exclude notifications whose
 *                                               producer owns email delivery.
 *                                               Default false.
 * }
 * @return array{ user_id:int, total:int, unread_count:int, page:int, pages:int, notifications:array }
 */
function ec_users_get_notifications( int $user_id, array $args = array() ): array {
	global $wpdb;

	$defaults = array(
		'unread'                       => false,
		'page'                         => 1,
		'per_page'                     => 50,
		'exclude_producer_owned_email' => false,
	);
	$args     = wp_parse_args( $args, $defaults );

	$unread_only   = ! empty( $args['unread'] );
	$exclude_owned = ! empty( $args['exclude_producer_owned_email'] );
	$per_page      = max( 1, min( 100, (int) $args['per_page'] ) );
	$page          = max( 1, (int) $args['page'] );

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

	if ( $exclude_owned ) {
		$unread_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0 AND producer_owns_email = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				$user_id
			)
		);
	} else {
		$unread_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				$user_id
			)
		);
	}

	if ( $unread_only ) {
		$total = $unread_count;
	} elseif ( $exclude_owned ) {
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND producer_owns_email = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				$user_id
			)
		);
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

	if ( $unread_only && $exclude_owned ) {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND is_read = 0 AND producer_owns_email = 0 ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				$user_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
	} elseif ( $unread_only ) {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND is_read = 0 ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
				$user_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
	} elseif ( $exclude_owned ) {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND producer_owns_email = 0 ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
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
