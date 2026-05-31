<?php
/**
 * Notification Data Migration
 *
 * One-time, idempotent migration of the legacy per-user notification blob
 * (`extrachill_notifications` user_meta) into the network notification
 * substrate table ({base_prefix}ec_notifications) owned by extrachill-users.
 *
 * Background: notifications used to be stored as a single serialized array on
 * each user's `extrachill_notifications` user_meta. The new substrate stores
 * one row per notification in a base_prefix table. This migration copies every
 * existing blob entry into the table, preserving the original timestamp and
 * read state, then renames the source meta key to
 * `extrachill_notifications_migrated` so the data is retained (not destroyed)
 * and never re-imported.
 *
 * Guarded by a network site-option flag so the scan runs exactly once across
 * the network. user_meta is a GLOBAL table in multisite, so no switch_to_blog
 * is needed to read the blob or write the table.
 *
 * Parent epic: Extra-Chill/extrachill-community#82.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

/**
 * Network site-option flag marking the blob migration complete.
 */
if ( ! defined( 'EXTRACHILL_COMMUNITY_NOTIFICATIONS_MIGRATED_FLAG' ) ) {
	define( 'EXTRACHILL_COMMUNITY_NOTIFICATIONS_MIGRATED_FLAG', 'extrachill_community_notifications_blob_migrated_v1' );
}

/**
 * Run the one-time blob -> table migration when needed.
 *
 * Runs late on init (priority 20) so the substrate table self-heal
 * (extrachill-users, init priority 10) has already created the table.
 */
function extrachill_community_maybe_migrate_notifications() {
	// Already migrated network-wide? Bail.
	if ( get_site_option( EXTRACHILL_COMMUNITY_NOTIFICATIONS_MIGRATED_FLAG ) ) {
		return;
	}

	// The substrate must be loaded for the table helper to exist.
	if ( ! function_exists( 'extrachill_users_notifications_table_name' ) ) {
		return;
	}

	extrachill_community_migrate_notification_blobs();

	update_site_option( EXTRACHILL_COMMUNITY_NOTIFICATIONS_MIGRATED_FLAG, time() );
}
add_action( 'init', 'extrachill_community_maybe_migrate_notifications', 20 );

/**
 * Migrate every user's notification blob into the substrate table.
 *
 * Idempotent at the row level: each migrated user's source meta key is renamed
 * to `extrachill_notifications_migrated`, so a re-run never double-imports even
 * if the flag is cleared. user_meta is global in multisite.
 *
 * @return int Number of notification rows inserted.
 */
function extrachill_community_migrate_notification_blobs() {
	global $wpdb;

	$table = extrachill_users_notifications_table_name();

	// Collect user IDs that still have the legacy blob.
	$user_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core global table.
			'extrachill_notifications'
		)
	);

	if ( empty( $user_ids ) ) {
		return 0;
	}

	$inserted = 0;

	foreach ( $user_ids as $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			continue;
		}

		$blob = get_user_meta( $user_id, 'extrachill_notifications', true );

		if ( is_array( $blob ) ) {
			foreach ( $blob as $entry ) {
				if ( extrachill_community_migrate_single_notification( $table, $user_id, $entry ) ) {
					++$inserted;
				}
			}
		}

		// Preserve the source data under a renamed key (copy, do not destroy),
		// and remove the original so it is never re-imported and the bell/page
		// no longer read it.
		$existing = get_user_meta( $user_id, 'extrachill_notifications_migrated', true );
		if ( empty( $existing ) ) {
			update_user_meta( $user_id, 'extrachill_notifications_migrated', $blob );
		}
		delete_user_meta( $user_id, 'extrachill_notifications' );
	}

	return $inserted;
}

/**
 * Insert a single legacy blob entry into the substrate table.
 *
 * Preserves the original timestamp (converted from site-local to UTC) and the
 * read state, which ec_users_notify() cannot do (it always inserts as unread
 * with the current time). Hence the direct insert.
 *
 * @param string $table   Substrate table name.
 * @param int    $user_id Recipient user ID.
 * @param mixed  $entry   Legacy notification entry (associative array).
 * @return bool True if a row was inserted.
 */
function extrachill_community_migrate_single_notification( $table, $user_id, $entry ) {
	global $wpdb;

	if ( ! is_array( $entry ) ) {
		return false;
	}

	$actor_id = isset( $entry['actor_id'] ) ? (int) $entry['actor_id'] : 0;
	$type     = isset( $entry['type'] ) ? sanitize_key( $entry['type'] ) : '';
	$link     = isset( $entry['link'] ) ? esc_url_raw( (string) $entry['link'] ) : '';

	// Legacy blob stored the subject under 'topic_title'.
	$title = '';
	if ( isset( $entry['title'] ) && '' !== $entry['title'] ) {
		$title = (string) $entry['title'];
	} elseif ( ! empty( $entry['topic_title'] ) ) {
		$title = (string) $entry['topic_title'];
	}
	$title = sanitize_text_field( $title );

	// Skip entries missing required fields rather than inserting junk rows.
	if ( $actor_id <= 0 || '' === $type || '' === $link || '' === $title ) {
		return false;
	}

	// Related object id: legacy used 'item_id' or 'post_id'.
	$item_id = 0;
	if ( ! empty( $entry['item_id'] ) ) {
		$item_id = (int) $entry['item_id'];
	} elseif ( ! empty( $entry['post_id'] ) ) {
		$item_id = (int) $entry['post_id'];
	}

	$is_read = ! empty( $entry['read'] ) ? 1 : 0;

	// Legacy 'time' was site-local (current_time('mysql')); the table stores
	// UTC. Convert, falling back to "now" in UTC if the value is unparseable.
	$created_at = current_time( 'mysql', true );
	if ( ! empty( $entry['time'] ) ) {
		$converted = get_gmt_from_date( (string) $entry['time'], 'Y-m-d H:i:s' );
		if ( $converted ) {
			$created_at = $converted;
		}
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
			'is_read'    => $is_read,
			'created_at' => $created_at,
		),
		array( '%d', '%d', '%s', '%s', '%s', $item_id > 0 ? '%d' : null, '%d', '%s' )
	);

	return (bool) $result;
}
