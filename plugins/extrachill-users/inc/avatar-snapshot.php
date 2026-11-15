<?php
/**
 * Avatar URL Snapshot
 *
 * Custom avatars are attachments on the community blog, referenced by the
 * global `custom_avatar_id` user meta. Turning that ID into image URLs needs
 * the community blog, so rendering an avatar used to cost a switch_to_blog()
 * on every request.
 *
 * This file owns a global (unprefixed) user meta snapshot, `ec_avatar_urls`:
 *
 *     array(
 *         'attachment_id' => 123,
 *         'urls'          => array( 'thumbnail' => '...', 'medium' => '...', 'large' => '...' ),
 *     )
 *
 * `attachment_id` records which `custom_avatar_id` the URLs were built from,
 * so a snapshot left behind by a writer that did not refresh it is detected
 * as stale and rebuilt. An empty `urls` array records that the assigned
 * attachment is not a usable image, so that case does not re-switch either.
 *
 * - Write: extrachill_users_refresh_avatar_snapshot() (one community switch).
 * - Read:  extrachill_users_get_avatar_snapshot() (no switch).
 * - Invalidate: attachment deletion/edits on the community blog.
 * - Backfill: extrachill_users_backfill_avatar_snapshots() (WP-CLI adapter
 *   in extrachill-cli: `wp extrachill users avatar-snapshot backfill`).
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User meta key holding the avatar URL snapshot.
 */
const EXTRACHILL_USERS_AVATAR_SNAPSHOT_KEY = 'ec_avatar_urls';

/**
 * WordPress image sizes captured in the snapshot.
 *
 * Matches every value extrachill_avatar_image_size() can return.
 *
 * @return string[]
 */
function extrachill_users_avatar_snapshot_sizes() {
	return array( 'thumbnail', 'medium', 'large' );
}

/**
 * Read the assigned avatar attachment ID from global user meta.
 *
 * @param int $user_id User ID.
 * @return int Attachment ID, or 0 when none is assigned.
 */
function extrachill_users_get_assigned_avatar_id( $user_id ) {
	return (int) get_user_meta( (int) $user_id, 'custom_avatar_id', true );
}

/**
 * Read a user's avatar URL snapshot without switching blogs.
 *
 * @param int $user_id User ID.
 * @return array{attachment_id:int,urls:array<string,string>}|null Snapshot, or null
 *         when missing, malformed, or built from a different attachment than the
 *         one currently assigned.
 */
function extrachill_users_get_avatar_snapshot( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return null;
	}

	$snapshot = get_user_meta( $user_id, EXTRACHILL_USERS_AVATAR_SNAPSHOT_KEY, true );
	if ( ! is_array( $snapshot ) || ! isset( $snapshot['attachment_id'], $snapshot['urls'] ) || ! is_array( $snapshot['urls'] ) ) {
		return null;
	}

	$assigned_id = extrachill_users_get_assigned_avatar_id( $user_id );
	if ( $assigned_id <= 0 || (int) $snapshot['attachment_id'] !== $assigned_id ) {
		return null;
	}

	$urls = array();
	foreach ( $snapshot['urls'] as $size => $url ) {
		if ( is_string( $size ) && is_string( $url ) && '' !== $url ) {
			$urls[ $size ] = $url;
		}
	}

	return array(
		'attachment_id' => $assigned_id,
		'urls'          => $urls,
	);
}

/**
 * Resolve avatar URLs for an attachment. Caller must be on the community blog.
 *
 * @param int $attachment_id Attachment ID on the community blog.
 * @return array<string,string> Size name => URL; empty when not a usable image.
 */
function extrachill_users_resolve_avatar_urls( $attachment_id ) {
	$urls = array();
	if ( ! wp_attachment_is_image( $attachment_id ) ) {
		return $urls;
	}

	foreach ( extrachill_users_avatar_snapshot_sizes() as $size ) {
		$url = wp_get_attachment_image_url( $attachment_id, $size );
		if ( is_string( $url ) && '' !== $url ) {
			$urls[ $size ] = $url;
		}
	}

	return $urls;
}

/**
 * Build and store a user's avatar URL snapshot from `custom_avatar_id`.
 *
 * Call after assigning or unassigning an avatar. Switches to the community
 * blog at most once (not at all when already there). Deletes the snapshot
 * when no avatar is assigned.
 *
 * @param int $user_id User ID.
 * @return array{attachment_id:int,urls:array<string,string>}|null Stored snapshot,
 *         or null when no avatar is assigned or the community blog is unknown.
 */
function extrachill_users_refresh_avatar_snapshot( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return null;
	}

	$attachment_id = extrachill_users_get_assigned_avatar_id( $user_id );
	if ( $attachment_id <= 0 ) {
		delete_user_meta( $user_id, EXTRACHILL_USERS_AVATAR_SNAPSHOT_KEY );
		return null;
	}

	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'community' ) : 0;
	if ( $community_blog_id <= 0 ) {
		return null;
	}

	$switched = function_exists( 'switch_to_blog' ) && (int) get_current_blog_id() !== $community_blog_id;
	if ( $switched ) {
		switch_to_blog( $community_blog_id );
	}

	try {
		$urls = extrachill_users_resolve_avatar_urls( $attachment_id );
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	$snapshot = array(
		'attachment_id' => $attachment_id,
		'urls'          => $urls,
	);
	update_user_meta( $user_id, EXTRACHILL_USERS_AVATAR_SNAPSHOT_KEY, $snapshot );

	return $snapshot;
}

/**
 * Delete the snapshot of every user whose avatar is the given attachment.
 *
 * The next render rebuilds the snapshot lazily.
 *
 * @param int $attachment_id Attachment ID on the community blog.
 * @return int Number of users whose snapshot was cleared.
 */
function extrachill_users_clear_avatar_snapshots_for_attachment( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	if ( $attachment_id <= 0 ) {
		return 0;
	}

	$user_ids = get_users(
		array(
			'blog_id'    => 0,
			'meta_key'   => 'custom_avatar_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fires only on community attachment changes.
			'meta_value' => (string) $attachment_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Fires only on community attachment changes.
			'fields'     => 'ID',
		)
	);

	$cleared = 0;
	foreach ( (array) $user_ids as $user_id ) {
		delete_user_meta( (int) $user_id, EXTRACHILL_USERS_AVATAR_SNAPSHOT_KEY );
		++$cleared;
	}

	return $cleared;
}

/**
 * Whether the current blog is the community blog, where avatars live.
 *
 * @return bool
 */
function extrachill_users_is_avatar_blog() {
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'community' ) : 0;
	return $community_blog_id > 0 && (int) get_current_blog_id() === $community_blog_id;
}

/**
 * Invalidate snapshots when a community attachment is deleted or edited.
 *
 * The plugin is network-active, so these hooks also run on other blogs,
 * where attachment IDs do not refer to avatars.
 *
 * @param int $post_id Attachment ID.
 */
function extrachill_users_invalidate_avatar_snapshots( $post_id ) {
	if ( ! extrachill_users_is_avatar_blog() ) {
		return;
	}

	extrachill_users_clear_avatar_snapshots_for_attachment( (int) $post_id );
}
add_action( 'delete_attachment', 'extrachill_users_invalidate_avatar_snapshots', 10, 1 );
add_action( 'attachment_updated', 'extrachill_users_invalidate_avatar_snapshots', 10, 1 );

/**
 * Invalidate snapshots when an attachment's file or size metadata changes.
 *
 * Covers image-editor saves and metadata regeneration, which write post
 * meta without necessarily updating the attachment post.
 *
 * @param int    $meta_id  Meta ID.
 * @param int    $post_id  Post ID.
 * @param string $meta_key Meta key.
 */
function extrachill_users_invalidate_avatar_snapshots_on_meta( $meta_id, $post_id, $meta_key ) {
	if ( '_wp_attachment_metadata' !== $meta_key && '_wp_attached_file' !== $meta_key ) {
		return;
	}

	extrachill_users_invalidate_avatar_snapshots( (int) $post_id );
}
add_action( 'updated_post_meta', 'extrachill_users_invalidate_avatar_snapshots_on_meta', 10, 3 );

/**
 * Build missing or stale avatar snapshots in bounded batches.
 *
 * Switches to the community blog once per batch rather than once per user.
 *
 * @param array $args {
 *     Optional.
 *
 *     @type int  $batch_size Users per batch. Default 100, max 500.
 *     @type int  $limit      Max users to scan; 0 for all. Default 0.
 *     @type bool $dry_run    Count without writing. Default false.
 * }
 * @return array{scanned:int,fresh:int,written:int,would_write:int,batches:int,dry_run:bool}
 */
function extrachill_users_backfill_avatar_snapshots( $args = array() ) {
	$batch_size = isset( $args['batch_size'] ) ? (int) $args['batch_size'] : 100;
	$batch_size = max( 1, min( 500, $batch_size ) );
	$limit      = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;
	$dry_run    = ! empty( $args['dry_run'] );

	$report = array(
		'scanned'     => 0,
		'fresh'       => 0,
		'written'     => 0,
		'would_write' => 0,
		'batches'     => 0,
		'dry_run'     => $dry_run,
	);

	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'community' ) : 0;
	$offset            = 0;

	while ( 0 === $limit || $report['scanned'] < $limit ) {
		$number = 0 === $limit ? $batch_size : min( $batch_size, $limit - $report['scanned'] );

		$user_ids = get_users(
			array(
				'blog_id'      => 0,
				'meta_key'     => 'custom_avatar_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded maintenance backfill.
				'meta_compare' => 'EXISTS',
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'number'       => $number,
				'offset'       => $offset,
				'fields'       => 'ID',
			)
		);
		$user_ids = array_map( 'intval', (array) $user_ids );

		if ( empty( $user_ids ) ) {
			break;
		}

		++$report['batches'];
		$offset += count( $user_ids );

		$switched = ! $dry_run && $community_blog_id > 0 && function_exists( 'switch_to_blog' ) && (int) get_current_blog_id() !== $community_blog_id;
		if ( $switched ) {
			switch_to_blog( $community_blog_id );
		}

		try {
			foreach ( $user_ids as $user_id ) {
				++$report['scanned'];

				if ( null !== extrachill_users_get_avatar_snapshot( $user_id ) ) {
					++$report['fresh'];
					continue;
				}

				if ( $dry_run ) {
					++$report['would_write'];
					continue;
				}

				if ( null !== extrachill_users_refresh_avatar_snapshot( $user_id ) ) {
					++$report['written'];
				}
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}

		if ( count( $user_ids ) < $number ) {
			break;
		}
	}

	return $report;
}
