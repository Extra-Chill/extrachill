<?php
/**
 * Moderation Actions
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

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

	if ( ! empty( $policy['effects']['revoke_sessions'] ) && function_exists( 'WP_Session_Tokens' ) ) {
		$manager = WP_Session_Tokens::get_instance( $user_id );
		$manager->destroy_all();
	}

	$results = array();
	if ( ! empty( $policy['effects']['mark_content_spam'] ) || ! empty( $policy['effects']['hide_content'] ) ) {
		$results['content'] = extrachill_users_apply_spam_visibility_to_user_content( $user_id );
	}

	$status = extrachill_users_get_moderation_status( $user_id );

	if ( ! empty( $policy['effects']['send_email'] ) ) {
		extrachill_users_send_moderation_email( $user, $status );
	}

	if ( ! empty( $results ) ) {
		$status['results'] = $results;
	}

	return $status;
}

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
