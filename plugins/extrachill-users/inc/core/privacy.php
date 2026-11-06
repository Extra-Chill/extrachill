<?php
/**
 * Core privacy lifecycle for Users-owned network data.
 *
 * @package ExtraChill\Users
 */
defined( 'ABSPATH' ) || exit;

/** Trusted inventory: table suffix => user-reference columns. */
function extrachill_users_privacy_tables() {
	return array(
		'ec_concert_tracking'     => array( 'user_id' ),
		'ec_concert_import_runs'  => array( 'user_id' ),
		'ec_entity_subscriptions' => array( 'user_id' ),
		'ec_notifications'        => array( 'user_id', 'actor_id' ),
	);
}

/** Users-owned metadata only. Never erase arbitrary cross-plugin metadata. */
function extrachill_users_privacy_meta_keys() {
	return array(
		'registration_timestamp', 'registration_source', 'registration_method',
		'registration_page', 'registration_referrer', 'registration_utm',
		'last_login', 'last_active', 'onboarding_completed', 'onboarding_completed_at',
		'onboarding_from_join', 'onboarding_redirect_url', 'onboarding_join_intent',
		'onboarding_reminder_sent_at', 'welcome_email_sent', 'welcome_email_complete_sent',
		'welcome_email_incomplete_sent', 'user_is_artist',
		'user_is_professional', 'artist_access_request', 'ec_unclaimed',
		'ec_custom_title', 'local_city', '_user_profile_dynamic_links',
		'custom_avatar', 'custom_avatar_id', 'extrachill_total_points',
		'extrachill_team', 'extrachill_team_manual_override', 'extrachill_lifetime_membership',
		'extrachill_user_moderation', 'extrachill_user_ban',
		'extrachill_artist_dispatch_access', 'extrachill_artist_dispatch_access_event',
		'extrachill_artist_dispatch_access_lock', 'extrachill_artist_dispatch_delivery_receipt',
		'extrachill_newsletter_registration_consent', 'extrachill_notifications',
		'ec_notifications_last_emailed', 'ec_notification_emails_disabled',
		'ec_auto_subscribe_replies_disabled', '_extrachill_default_event_location',
		'_extrachill_local_scene', '_extrachill_local_scene_visibility',
		'_extrachill_local_scene_prompt_dismissed', '_extrachill_concert_history_visibility',
		'_extrachill_event_attendance_visibility', '_extrachill_onboarding_artist_grant',
		'_ec_account_claim_minted_at', 'google_user_id', 'apple_user_id', 'apple_refresh_token', 'oauth_linked_at',
	);
}

/** Return true, false (absent), or null (database failure). */
function extrachill_users_privacy_table_exists( $table ) {
	global $wpdb;
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	return $wpdb->last_error ? null : $found === $table;
}

/**
 * Delete at most $limit rows per owned table and metadata per call.
 * Always drain the first remaining rows, never OFFSET: retries are safe.
 * Notifications involving the user as actor are deleted too, since titles,
 * links and producer keys can contain identifiers even after actor_id is zeroed.
 *
 * @return array Core eraser response plus numeric removed/retained/failed counts.
 */
function extrachill_users_erase_user_data_batch( $user_id, $limit = 100 ) {
	global $wpdb;
	$result = array(
		'items_removed' => false, 'items_retained' => false, 'messages' => array(),
		'done' => true, 'removed' => 0, 'retained' => 0, 'failed' => 0,
	);
	$user_id = absint( $user_id );
	$limit   = max( 1, min( 1000, (int) $limit ) );
	if ( ! $user_id ) {
		return $result;
	}

	foreach ( extrachill_users_privacy_tables() as $suffix => $columns ) {
		$table  = $wpdb->base_prefix . $suffix;
		$exists = extrachill_users_privacy_table_exists( $table );
		if ( false === $exists ) {
			continue;
		}
		if ( null === $exists ) {
			++$result['failed'];
			continue;
		}
		$where = implode( ' OR ', array_map( static function ( $column ) use ( $user_id ) {
			return $column . ' = ' . $user_id;
		}, $columns ) );
		// Table and columns come exclusively from the fixed inventory above.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$where} LIMIT %d", $limit ) );
		if ( false === $deleted ) {
			++$result['failed'];
		} else {
			$result['removed'] += $deleted;
			if ( $deleted === $limit ) {
				$result['done'] = false;
			}
		}
	}

	$keys = extrachill_users_privacy_meta_keys();
	$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	$args = array_merge( array( $user_id ), $keys, array(
		$wpdb->esc_like( 'ec_concert_import_' ) . '%' . $wpdb->esc_like( '_username' ),
		$wpdb->esc_like( 'welcome_email_sent_' ) . '%',
		$limit,
	) );
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND (meta_key IN ({$placeholders}) OR meta_key LIKE %s OR meta_key LIKE %s) LIMIT %d",
		$args
	) );
	if ( $wpdb->last_error ) {
		++$result['failed'];
	} else {
		foreach ( $ids as $id ) {
			if ( delete_metadata_by_mid( 'user', $id ) ) {
				++$result['removed'];
			} else {
				++$result['failed'];
			}
		}
		if ( count( $ids ) === $limit ) {
			$result['done'] = false;
		}
	}
	$result['items_removed'] = $result['removed'] > 0;
	$result['items_retained'] = $result['failed'] > 0;
	$result['done'] = $result['done'] && ! $result['failed'];
	$result['messages'][] = sprintf(
		/* translators: 1: removed count, 2: retained count, 3: failed operations. */
		__( 'Users data: %1$d removed; %2$d retained by policy; %3$d failed operations. Retry failures before closing the request.', 'extrachill-users' ),
		$result['removed'], $result['retained'], $result['failed']
	);
	return $result;
}

/** Email is resolved by core's normal privacy workflow, not a parallel request store. */
function extrachill_users_personal_data_eraser( $email_address, $page = 1 ) {
	unset( $page ); // Batches always drain the first remaining rows.
	$user   = get_user_by( 'email', $email_address );
	$result = extrachill_users_erase_user_data_batch( $user ? $user->ID : 0 );

	// Core's erasure UI requests the next page while done is false, so a
	// persistent storage failure would loop forever. Report it as retained
	// items and finish; the operator reruns the request after repairing storage.
	if ( $result['failed'] ) {
		$result['done'] = true;
	}

	return $result;
}

function extrachill_users_register_personal_data_eraser( $erasers ) {
	$erasers['extrachill-users'] = array(
		'eraser_friendly_name' => __( 'Extra Chill Users', 'extrachill-users' ),
		'callback' => 'extrachill_users_personal_data_eraser',
	);
	return $erasers;
}
add_filter( 'wp_privacy_personal_data_erasers', 'extrachill_users_register_personal_data_eraser' );

/**
 * Account deletion drains the same batches synchronously, before ID removal.
 * Fail closed on storage failure so a reused integer ID cannot inherit data.
 *
 * On multisite, `wp_delete_user()` fires `delete_user` but only removes the
 * user from the current site. Network data is erased only when the network
 * account itself is deleted (`wpmu_delete_user`).
 *
 * @param int $user_id Still-existing account ID.
 */
function extrachill_users_erase_deleted_account( $user_id ) {
	if ( is_multisite() && 'delete_user' === current_action() ) {
		return;
	}

	do {
		$result = extrachill_users_erase_user_data_batch( $user_id, 1000 );
		if ( $result['failed'] ) {
			wp_die( esc_html__( 'Users data cleanup failed. Account deletion stopped; retry after repairing database access.', 'extrachill-users' ) );
		}
	} while ( ! $result['done'] );

	/**
	 * Owner-specific cleanup contract, account deletion only.
	 * Owners must also register their own core email erasers for privacy requests.
	 * No Users code accesses their tables. Callbacks must be idempotent and must
	 * stop deletion (wp_die) on failure, before WordPress removes the account.
	 *
	 * @param int $user_id Still-existing account ID.
	 */
	do_action( 'extrachill_users_erase_user_data', (int) $user_id );
}
add_action( 'delete_user', 'extrachill_users_erase_deleted_account' );
add_action( 'wpmu_delete_user', 'extrachill_users_erase_deleted_account' );

/** Aggregate-only diagnostics: no identifiers, titles, emails or usernames. */
function extrachill_users_privacy_orphan_counts() {
	global $wpdb;
	$counts = array();
	foreach ( extrachill_users_privacy_tables() as $suffix => $columns ) {
		$table = $wpdb->base_prefix . $suffix;
		$exists = extrachill_users_privacy_table_exists( $table );
		foreach ( $columns as $column ) {
			$key = $suffix . '.' . $column;
			if ( true !== $exists ) {
				$counts[ $key ] = false === $exists ? 0 : null;
				continue;
			}
			$count = $wpdb->get_var( "SELECT COUNT(DISTINCT t.{$column}) FROM {$table} t LEFT JOIN {$wpdb->users} u ON u.ID = t.{$column} WHERE t.{$column} <> 0 AND u.ID IS NULL" );
			$counts[ $key ] = $wpdb->last_error ? null : (int) $count;
		}
	}
	return $counts;
}
