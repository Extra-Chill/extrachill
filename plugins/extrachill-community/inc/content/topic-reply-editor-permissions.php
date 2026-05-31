<?php
/**
 * Topic & Reply Editor Permissions
 *
 * Permission callbacks for the editor load/update abilities plus the shared
 * permissions-envelope builder consumed by the editor load callbacks. Enforces
 * bbPress edit caps and the bbp_past_edit_lock window.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

/**
 * Permission: can the current caller load this topic for editing?
 *
 * @param array $input Ability input.
 * @return bool|WP_Error
 */
function extrachill_community_ability_get_topic_for_editor_permission( $input = array() ) {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$topic_id = isset( $input['topic_id'] ) ? (int) $input['topic_id'] : 0;
	if ( $topic_id <= 0 ) {
		return false;
	}
	return current_user_can( 'read_topic', $topic_id );
}

/**
 * Permission: can the current caller load this reply for editing?
 *
 * @param array $input Ability input.
 * @return bool|WP_Error
 */
function extrachill_community_ability_get_reply_for_editor_permission( $input = array() ) {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$reply_id = isset( $input['reply_id'] ) ? (int) $input['reply_id'] : 0;
	if ( $reply_id <= 0 ) {
		return false;
	}
	return current_user_can( 'read_reply', $reply_id );
}

/**
 * Permission: can the current caller update this topic?
 *
 * Enforces edit_topic cap + bbp_past_edit_lock window (matches existing UI guard
 * in loop-single-reply-card.php).
 *
 * @param array $input Ability input.
 * @return bool|WP_Error
 */
function extrachill_community_ability_update_topic_permission( $input = array() ) {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$topic_id = isset( $input['topic_id'] ) ? (int) $input['topic_id'] : 0;
	if ( $topic_id <= 0 ) {
		return false;
	}
	if ( ! current_user_can( 'edit_topic', $topic_id ) ) {
		return false;
	}
	if ( function_exists( 'bbp_past_edit_lock' ) ) {
		$post = get_post( $topic_id );
		if ( $post && bbp_past_edit_lock( $post->post_date_gmt ) ) {
			return new WP_Error(
				'edit_lock_expired',
				__( 'The edit window for this topic has expired.', 'extra-chill-community' ),
				array( 'status' => 403 )
			);
		}
	}
	return true;
}

/**
 * Permission: can the current caller update this reply?
 *
 * @param array $input Ability input.
 * @return bool|WP_Error
 */
function extrachill_community_ability_update_reply_permission( $input = array() ) {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$reply_id = isset( $input['reply_id'] ) ? (int) $input['reply_id'] : 0;
	if ( $reply_id <= 0 ) {
		return false;
	}
	if ( ! current_user_can( 'edit_reply', $reply_id ) ) {
		return false;
	}
	if ( function_exists( 'bbp_past_edit_lock' ) ) {
		$post = get_post( $reply_id );
		if ( $post && bbp_past_edit_lock( $post->post_date_gmt ) ) {
			return new WP_Error(
				'edit_lock_expired',
				__( 'The edit window for this reply has expired.', 'extra-chill-community' ),
				array( 'status' => 403 )
			);
		}
	}
	return true;
}

/**
 * Build the permissions envelope for a topic/reply load response.
 *
 * Computed from the current user's caps so the native client can disable
 * buttons pre-submit. Matches the contract documented in extrachill-multisite#33.
 *
 * @param int    $post_id Post ID.
 * @param string $type    Either 'topic' or 'reply'.
 * @return array{canSave: bool, canUploadMedia: bool, canDelete: bool}
 */
function extrachill_community_build_editor_permissions( $post_id, $type ) {
	$edit_cap   = ( 'topic' === $type ) ? 'edit_topic' : 'edit_reply';
	$delete_cap = ( 'topic' === $type ) ? 'delete_topic' : 'delete_reply';

	$can_save = current_user_can( $edit_cap, $post_id );
	if ( $can_save && function_exists( 'bbp_past_edit_lock' ) ) {
		$post = get_post( $post_id );
		if ( $post && bbp_past_edit_lock( $post->post_date_gmt ) ) {
			$can_save = false;
		}
	}

	return array(
		'canSave'        => (bool) $can_save,
		'canUploadMedia' => (bool) ( is_user_logged_in() && current_user_can( 'upload_files' ) ),
		'canDelete'      => (bool) current_user_can( $delete_cap, $post_id ),
	);
}
