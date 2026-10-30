<?php
/**
 * bbPress Draft Storage (custom table)
 *
 * Single source of truth for the bbPress draft storage backend. Replaces the
 * previous read/modify/write loop over a single `ec_bbpress_drafts` user_meta
 * blob, which dropped concurrent writes silently.
 *
 * Storage shape: one row per draft, keyed on
 * (user_id, blog_id, type, forum_id, topic_id, reply_to). Writes use
 * INSERT ... ON DUPLICATE KEY UPDATE so concurrent saves from multiple tabs
 * upsert atomically without a read/modify/write window.
 *
 * Lazy migration: the first read for a user with legacy user_meta backfills
 * any rows into the table and deletes the user_meta blob. No site-wide
 * migration script needed; the data heals as users return to compose.
 *
 * @package ExtraChillCommunity
 * @subpackage ForumFeatures\Content\Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the unprefixed table name for the bbPress drafts table.
 *
 * The table is created per-blog (uses $wpdb->prefix) because draft context
 * already includes blog_id — but on multisite each subsite's storage is
 * isolated, matching how the rest of the plugin treats forum content.
 *
 * @return string
 */
function extrachill_community_bbpress_drafts_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'ec_bbpress_drafts';
}

/**
 * The schema version. Bump when the CREATE TABLE statement changes.
 */
function extrachill_community_bbpress_drafts_schema_version() {
	return '1';
}

/**
 * Option key tracking the installed schema version for this blog.
 */
function extrachill_community_bbpress_drafts_schema_option() {
	return 'extrachill_community_bbpress_drafts_schema_version';
}

/**
 * Install or upgrade the drafts table for the current blog.
 *
 * Idempotent. Safe to call on every plugin load — guarded by an option so
 * dbDelta only runs when the schema version changes.
 */
function extrachill_community_bbpress_drafts_install_table() {
	$installed = get_option( extrachill_community_bbpress_drafts_schema_option(), '0' );
	$target    = extrachill_community_bbpress_drafts_schema_version();

	if ( (string) $installed === (string) $target ) {
		return;
	}

	global $wpdb;
	$table           = extrachill_community_bbpress_drafts_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table (
		draft_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT(20) UNSIGNED NOT NULL,
		blog_id BIGINT(20) UNSIGNED NOT NULL,
		type VARCHAR(16) NOT NULL,
		forum_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		topic_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		reply_to BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		title TEXT NULL,
		content LONGTEXT NULL,
		updated_at BIGINT(20) UNSIGNED NOT NULL,
		PRIMARY KEY  (draft_id),
		UNIQUE KEY uniq_draft (user_id, blog_id, type, forum_id, topic_id, reply_to),
		KEY idx_user_updated (user_id, updated_at)
	) $charset_collate;";

	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}

	dbDelta( $sql );

	update_option( extrachill_community_bbpress_drafts_schema_option(), $target, false );
}
add_action( 'plugins_loaded', 'extrachill_community_bbpress_drafts_install_table', 15 );

/**
 * Upsert a single draft row.
 *
 * Atomic — INSERT ... ON DUPLICATE KEY UPDATE relies on the UNIQUE KEY
 * uniq_draft so concurrent writes from two browser tabs collapse to a single
 * row without a read/modify/write window.
 *
 * @param int   $user_id Draft owner.
 * @param array $draft   Draft fields. Must include `type`.
 * @return array|false Stored row on success, false on failure.
 */
function extrachill_community_bbpress_drafts_upsert( $user_id, array $draft ) {
	global $wpdb;

	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return false;
	}

	$row = array(
		'user_id'    => $user_id,
		'blog_id'    => isset( $draft['blog_id'] ) ? (int) $draft['blog_id'] : (int) get_current_blog_id(),
		'type'       => isset( $draft['type'] ) ? (string) $draft['type'] : '',
		'forum_id'   => isset( $draft['forum_id'] ) ? (int) $draft['forum_id'] : 0,
		'topic_id'   => isset( $draft['topic_id'] ) ? (int) $draft['topic_id'] : 0,
		'reply_to'   => isset( $draft['reply_to'] ) ? (int) $draft['reply_to'] : 0,
		'title'      => isset( $draft['title'] ) ? (string) $draft['title'] : '',
		'content'    => isset( $draft['content'] ) ? (string) $draft['content'] : '',
		'updated_at' => isset( $draft['updated_at'] ) ? (int) $draft['updated_at'] : time(),
	);

	if ( '' === $row['type'] ) {
		return false;
	}

	$table = extrachill_community_bbpress_drafts_table_name();

	// Manual INSERT ... ON DUPLICATE KEY UPDATE — $wpdb->replace would delete
	// the row first (losing draft_id continuity), so we do this explicitly.
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
	$sql = $wpdb->prepare(
		"INSERT INTO {$table}
			(user_id, blog_id, type, forum_id, topic_id, reply_to, title, content, updated_at)
		VALUES (%d, %d, %s, %d, %d, %d, %s, %s, %d)
		ON DUPLICATE KEY UPDATE
			title = VALUES(title),
			content = VALUES(content),
			updated_at = VALUES(updated_at)",
		$row['user_id'],
		$row['blog_id'],
		$row['type'],
		$row['forum_id'],
		$row['topic_id'],
		$row['reply_to'],
		$row['title'],
		$row['content'],
		$row['updated_at']
	);
	$result = $wpdb->query( $sql );
	// phpcs:enable WordPress.DB.PreparedSQL
	if ( false === $result ) {
		return false;
	}

	return $row;
}

/**
 * Fetch a single draft row matching the supplied context.
 *
 * @param int   $user_id Draft owner.
 * @param array $context Draft context (type, blog_id, forum_id, topic_id, reply_to).
 * @return array|null Stored row or null if none.
 */
function extrachill_community_bbpress_drafts_fetch( $user_id, array $context ) {
	global $wpdb;

	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return null;
	}

	extrachill_community_bbpress_drafts_migrate_user_meta_if_needed( $user_id );

	$table = extrachill_community_bbpress_drafts_table_name();
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table}
			WHERE user_id = %d
				AND blog_id = %d
				AND type = %s
				AND forum_id = %d
				AND topic_id = %d
				AND reply_to = %d
			LIMIT 1",
			$user_id,
			isset( $context['blog_id'] ) ? (int) $context['blog_id'] : (int) get_current_blog_id(),
			isset( $context['type'] ) ? (string) $context['type'] : '',
			isset( $context['forum_id'] ) ? (int) $context['forum_id'] : 0,
			isset( $context['topic_id'] ) ? (int) $context['topic_id'] : 0,
			isset( $context['reply_to'] ) ? (int) $context['reply_to'] : 0
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL

	return $row ? extrachill_community_bbpress_drafts_normalize_row( $row ) : null;
}

/**
 * Delete a single draft row matching the supplied context.
 *
 * @param int   $user_id Draft owner.
 * @param array $context Draft context (type, blog_id, forum_id, topic_id, reply_to).
 * @return bool True on success (including no-op when row didn't exist).
 */
function extrachill_community_bbpress_drafts_delete( $user_id, array $context ) {
	global $wpdb;

	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return false;
	}

	extrachill_community_bbpress_drafts_migrate_user_meta_if_needed( $user_id );

	$table  = extrachill_community_bbpress_drafts_table_name();
	$result = $wpdb->delete(
		$table,
		array(
			'user_id'  => $user_id,
			'blog_id'  => isset( $context['blog_id'] ) ? (int) $context['blog_id'] : (int) get_current_blog_id(),
			'type'     => isset( $context['type'] ) ? (string) $context['type'] : '',
			'forum_id' => isset( $context['forum_id'] ) ? (int) $context['forum_id'] : 0,
			'topic_id' => isset( $context['topic_id'] ) ? (int) $context['topic_id'] : 0,
			'reply_to' => isset( $context['reply_to'] ) ? (int) $context['reply_to'] : 0,
		),
		array( '%d', '%d', '%s', '%d', '%d', '%d' )
	);

	return false !== $result;
}

/**
 * Normalize a DB row into the public draft shape (matches pre-refactor output).
 *
 * @param array $row Raw DB row.
 * @return array
 */
function extrachill_community_bbpress_drafts_normalize_row( array $row ) {
	return array(
		'type'       => isset( $row['type'] ) ? (string) $row['type'] : '',
		'blog_id'    => isset( $row['blog_id'] ) ? (int) $row['blog_id'] : 0,
		'forum_id'   => isset( $row['forum_id'] ) ? (int) $row['forum_id'] : 0,
		'topic_id'   => isset( $row['topic_id'] ) ? (int) $row['topic_id'] : 0,
		'reply_to'   => isset( $row['reply_to'] ) ? (int) $row['reply_to'] : 0,
		'title'      => isset( $row['title'] ) ? (string) $row['title'] : '',
		'content'    => isset( $row['content'] ) ? (string) $row['content'] : '',
		'updated_at' => isset( $row['updated_at'] ) ? (int) $row['updated_at'] : 0,
	);
}

/**
 * Lazy migration: on first table read for a user with legacy user_meta,
 * backfill all rows into the table and delete the user_meta blob.
 *
 * Self-healing — no network-wide migration script needed. Users with no
 * legacy data pay only one user_meta lookup which is cached.
 *
 * @param int $user_id Draft owner.
 * @return void
 */
function extrachill_community_bbpress_drafts_migrate_user_meta_if_needed( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}

	$meta_key = 'ec_bbpress_drafts';
	$legacy   = get_user_meta( $user_id, $meta_key, true );

	if ( empty( $legacy ) || ! is_array( $legacy ) ) {
		return;
	}

	foreach ( $legacy as $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['type'] ) ) {
			continue;
		}
		extrachill_community_bbpress_drafts_upsert( $user_id, $entry );
	}

	delete_user_meta( $user_id, $meta_key );
}
