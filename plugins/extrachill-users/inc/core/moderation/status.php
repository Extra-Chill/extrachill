<?php
/**
 * Moderation Status Helpers
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

function extrachill_users_get_moderation_status( int $user_id ): array {
	if ( $user_id <= 0 ) {
		return array(
			'active' => true,
			'state'  => 'active',
			'user_id' => $user_id,
		);
	}

	$record = get_user_meta( $user_id, extrachill_users_moderation_meta_key(), true );

	if ( ( ! is_array( $record ) || empty( $record['state'] ) ) ) {
		$legacy = get_user_meta( $user_id, extrachill_users_legacy_ban_meta_key(), true );
		if ( is_array( $legacy ) && ! empty( $legacy['banned'] ) ) {
			$record = array(
				'state'      => 'banned',
				'reason_key' => 'other',
				'reason'     => isset( $legacy['reason'] ) ? (string) $legacy['reason'] : '',
				'note'       => isset( $legacy['note'] ) ? (string) $legacy['note'] : '',
				'source'     => isset( $legacy['source'] ) ? (string) $legacy['source'] : '',
				'acted_at'   => isset( $legacy['banned_at'] ) ? (int) $legacy['banned_at'] : 0,
				'acted_by'   => isset( $legacy['banned_by'] ) ? (int) $legacy['banned_by'] : 0,
				'effects'    => extrachill_users_get_moderation_policy( 'other' )['effects'],
			);
		}
	}

	if ( ! is_array( $record ) || empty( $record['state'] ) || 'active' === $record['state'] ) {
		return array(
			'active' => true,
			'state'  => 'active',
			'user_id' => $user_id,
		);
	}

	$reason_key = isset( $record['reason_key'] ) ? (string) $record['reason_key'] : 'other';
	$policy     = extrachill_users_get_moderation_policy( $reason_key );

	return array(
		'active'      => false,
		'state'       => isset( $record['state'] ) ? (string) $record['state'] : 'banned',
		'reason_key'  => $reason_key,
		'reason'      => isset( $record['reason'] ) ? (string) $record['reason'] : '',
		'note'        => isset( $record['note'] ) ? (string) $record['note'] : '',
		'source'      => isset( $record['source'] ) ? (string) $record['source'] : '',
		'acted_at'    => isset( $record['acted_at'] ) ? (int) $record['acted_at'] : 0,
		'acted_by'    => isset( $record['acted_by'] ) ? (int) $record['acted_by'] : 0,
		'effects'     => isset( $policy['effects'] ) ? $policy['effects'] : array(),
		'policy'      => $policy,
		'user_id'     => $user_id,
	);
}

function extrachill_users_is_blocked( int $user_id ): bool {
	$status = extrachill_users_get_moderation_status( $user_id );
	return empty( $status['active'] ) && ! empty( $status['effects']['block_login'] );
}
