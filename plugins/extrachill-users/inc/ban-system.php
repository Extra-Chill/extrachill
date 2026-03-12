<?php
/**
 * User Ban System
 *
 * Shared helpers for banning and unbanning users across all auth surfaces.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

function extrachill_users_ban_meta_key() {
	return 'extrachill_user_ban';
}

function extrachill_users_get_ban_status( int $user_id ): array {
	if ( $user_id <= 0 ) {
		return array(
			'banned' => false,
		);
	}

	$ban = get_user_meta( $user_id, extrachill_users_ban_meta_key(), true );

	if ( ! is_array( $ban ) || empty( $ban['banned'] ) ) {
		return array(
			'banned' => false,
		);
	}

	return array(
		'banned'     => true,
		'reason'     => isset( $ban['reason'] ) ? (string) $ban['reason'] : '',
		'banned_at'  => isset( $ban['banned_at'] ) ? (int) $ban['banned_at'] : 0,
		'banned_by'  => isset( $ban['banned_by'] ) ? (int) $ban['banned_by'] : 0,
		'note'       => isset( $ban['note'] ) ? (string) $ban['note'] : '',
		'source'     => isset( $ban['source'] ) ? (string) $ban['source'] : '',
		'user_id'    => $user_id,
	);
}

function extrachill_users_is_banned( int $user_id ): bool {
	$status = extrachill_users_get_ban_status( $user_id );
	return ! empty( $status['banned'] );
}

function extrachill_users_ban_user( int $user_id, array $args = array() ) {
	if ( $user_id <= 0 ) {
		return new WP_Error( 'invalid_user', __( 'A valid user ID is required.', 'extrachill-users' ) );
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', __( 'User not found.', 'extrachill-users' ) );
	}

	$actor_id = isset( $args['banned_by'] ) ? (int) $args['banned_by'] : get_current_user_id();
	$payload  = array(
		'banned'    => true,
		'reason'    => isset( $args['reason'] ) ? sanitize_text_field( (string) $args['reason'] ) : '',
		'note'      => isset( $args['note'] ) ? sanitize_textarea_field( (string) $args['note'] ) : '',
		'source'    => isset( $args['source'] ) ? sanitize_text_field( (string) $args['source'] ) : '',
		'banned_at' => time(),
		'banned_by' => $actor_id > 0 ? $actor_id : 0,
	);

	update_user_meta( $user_id, extrachill_users_ban_meta_key(), $payload );

	if ( function_exists( 'WP_Session_Tokens' ) ) {
		$manager = WP_Session_Tokens::get_instance( $user_id );
		$manager->destroy_all();
	}

	return extrachill_users_get_ban_status( $user_id );
}

function extrachill_users_unban_user( int $user_id ) {
	if ( $user_id <= 0 ) {
		return new WP_Error( 'invalid_user', __( 'A valid user ID is required.', 'extrachill-users' ) );
	}

	delete_user_meta( $user_id, extrachill_users_ban_meta_key() );

	return array(
		'banned'  => false,
		'user_id' => $user_id,
	);
}

function extrachill_users_block_banned_cookie_auth( $user, $username, $password ) {
	if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
		return $user;
	}

	if ( extrachill_users_is_banned( (int) $user->ID ) ) {
		return new WP_Error(
			'extrachill_user_banned',
			__( 'This account has been suspended. Please contact support if you believe this is a mistake.', 'extrachill-users' )
		);
	}

	return $user;
}
add_filter( 'authenticate', 'extrachill_users_block_banned_cookie_auth', 30, 3 );
