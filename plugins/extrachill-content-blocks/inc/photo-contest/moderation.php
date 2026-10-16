<?php
/**
 * Photo Contest domain: moderation.
 *
 * @package ExtraChillContentBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moderation permission: staff who can edit others' posts.
 *
 * @return bool
 */
function extrachill_content_blocks_photo_contest_can_moderate(): bool {
	return current_user_can( 'edit_others_posts' );
}

/**
 * Hide or unhide an entry (admin moderation, one action).
 *
 * Hidden entries disappear from the public grid and can no longer receive
 * votes; their historical votes remain in the comments table.
 *
 * @param int  $entry_id Entry attachment ID.
 * @param bool $hidden   True to hide, false to restore.
 * @return array|\WP_Error array{message} on success, status-coded WP_Error on failure.
 */
function extrachill_content_blocks_photo_contest_set_entry_hidden( $entry_id, $hidden ) {
	$entry = get_post( $entry_id );
	if ( ! $entry || 'attachment' !== $entry->post_type
		|| '' === (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_CONTEST, true ) ) {
		return extrachill_content_blocks_photo_contest_error(
			'entry_not_found',
			'Entry not found.',
			404
		);
	}

	if ( $hidden ) {
		update_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_HIDDEN, '1' );
	} else {
		delete_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_HIDDEN );
	}

	return array(
		'message' => $hidden ? 'Entry hidden.' : 'Entry restored.',
	);
}
