<?php
/**
 * Moderation Actions
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Apply a moderation state and its configured effects.
 *
 * @param int   $user_id User ID.
 * @param array $args    Moderation inputs.
 * @return array|WP_Error
 */
function extrachill_users_apply_moderation_action( int $user_id, array $args = array() ) {
	if ( $user_id <= 0 ) {
		return new WP_Error( 'invalid_user', __( 'A valid user ID is required.', 'extrachill-users' ) );
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', __( 'User not found.', 'extrachill-users' ) );
	}

	$state      = isset( $args['state'] ) ? sanitize_key( (string) $args['state'] ) : 'banned';
	$reason_key = isset( $args['reason_key'] ) ? sanitize_key( (string) $args['reason_key'] ) : 'other';
	$actor_id   = isset( $args['acted_by'] ) ? (int) $args['acted_by'] : get_current_user_id();
	$policy     = extrachill_users_get_moderation_policy( $reason_key );

	$payload = array(
		'state'      => $state,
		'reason_key' => $reason_key,
		'reason'     => isset( $args['reason'] ) ? sanitize_text_field( (string) $args['reason'] ) : '',
		'note'       => isset( $args['note'] ) ? sanitize_textarea_field( (string) $args['note'] ) : '',
		'source'     => isset( $args['source'] ) ? sanitize_text_field( (string) $args['source'] ) : '',
		'acted_at'   => time(),
		'acted_by'   => $actor_id > 0 ? $actor_id : 0,
		'effects'    => isset( $policy['effects'] ) ? $policy['effects'] : array(),
	);

	update_user_meta( $user_id, extrachill_users_moderation_meta_key(), $payload );
	delete_user_meta( $user_id, extrachill_users_legacy_ban_meta_key() );

	if ( ! empty( $policy['effects']['revoke_sessions'] ) && class_exists( 'WP_Session_Tokens' ) ) {
		$manager = WP_Session_Tokens::get_instance( $user_id );
		$manager->destroy_all();

		if ( function_exists( 'wp_native_auth_revoke_user_refresh_tokens' ) ) {
			$native_result = wp_native_auth_revoke_user_refresh_tokens( $user_id );
			if ( is_wp_error( $native_result ) ) {
				return $native_result;
			}
		}
	}

	// Explicit, opt-in hard delete. DESTRUCTIVE AND IRREVERSIBLE — only fires
	// when the operator explicitly requests it; it is never derived from the
	// reason_key policy effects. When set, purge supersedes the hide path so we
	// don't draft/spam content we're about to permanently delete.
	$purge_content = ! empty( $args['purge_content'] );

	$results = array();
	if ( $purge_content ) {
		$results['purged'] = extrachill_users_purge_user_content( $user_id );
	} elseif ( ! empty( $policy['effects']['mark_content_spam'] ) || ! empty( $policy['effects']['hide_content'] ) ) {
		$results['content'] = extrachill_users_apply_spam_visibility_to_user_content( $user_id );
	}

	$status = extrachill_users_get_moderation_status( $user_id );
	if ( function_exists( 'ec_users_revoke_artist_dispatch_for_moderation' ) && empty( $status['active'] ) ) {
		$dispatch_reason = $payload['reason'] ? $payload['reason'] : $reason_key;
		$dispatch_result = ec_users_revoke_artist_dispatch_for_moderation( $user_id, $actor_id, $dispatch_reason );
		// Moderation remains successful when optional Artist Dispatch cleanup fails.
		// The ban and content visibility changes above are already committed.
		if ( is_wp_error( $dispatch_result ) ) {
			$results['artist_dispatch'] = array( 'error' => $dispatch_result->get_error_code() );
		}
	}

	if ( ! empty( $policy['effects']['send_email'] ) ) {
		extrachill_users_send_moderation_email( $user, $status );
	}

	if ( ! empty( $results ) ) {
		$status['results'] = $results;
	}

	return $status;
}

/**
 * Clear moderation state without restoring separately revoked product access.
 *
 * @param int $user_id User ID.
 * @return array|WP_Error
 */
function extrachill_users_clear_moderation_action( int $user_id ) {
	if ( $user_id <= 0 ) {
		return new WP_Error( 'invalid_user', __( 'A valid user ID is required.', 'extrachill-users' ) );
	}

	delete_user_meta( $user_id, extrachill_users_moderation_meta_key() );
	delete_user_meta( $user_id, extrachill_users_legacy_ban_meta_key() );

	return array(
		'active'  => true,
		'state'   => 'active',
		'user_id' => $user_id,
	);
}
