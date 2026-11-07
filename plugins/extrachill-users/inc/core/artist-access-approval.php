<?php
/**
 * Artist access pending-action approvals. Data Machine is optional.
 *
 * @package ExtraChill\Users
 */

use DataMachine\Engine\AI\Actions\PendingActionHelper;
use DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt;
use DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility;

defined( 'ABSPATH' ) || exit;

add_filter( 'datamachine_pending_action_handlers', 'extrachill_users_artist_access_action_handlers' );

/** Register only when the receipt authority is available. */
function extrachill_users_artist_access_action_handlers( $handlers ) {
	if ( class_exists( PendingActionAuthorizationReceipt::class ) ) {
		$handlers['extrachill_artist_access'] = array(
			'apply'       => 'extrachill_users_apply_artist_access_action',
			'can_resolve' => 'extrachill_users_can_resolve_artist_access_action',
		);
	}
	return $handlers;
}

/** Signed links authorize anonymous humans; otherwise require a network admin. */
function extrachill_users_can_resolve_artist_access_action( $payload, $decision, $user_id, $context = array() ) {
	if ( is_array( $context ) && 'signed_url' === ( $context['resolution_transport'] ?? '' ) ) {
		return true;
	}
	if ( $user_id > 0 && user_can( $user_id, 'manage_network_options' ) ) {
		return true;
	}
	return new WP_Error( 'artist_access_forbidden', 'Only a network administrator can approve artist access.', array( 'status' => 403 ) );
}

/** Consume the one-time authorization before invoking the internal grant. */
function extrachill_users_apply_artist_access_action( array $input, array $payload, array $receipt ) {
	$subject    = (string) ( $payload['agent'] ?? $payload['creator'] ?? '' );
	$workspace  = isset( $payload['workspace'] ) && is_array( $payload['workspace'] ) ? $payload['workspace'] : array();
	$authorized = PendingActionAuthorizationReceipt::consume( $receipt, 'extrachill_artist_access', 'extrachill_artist_access', $input, $input, $subject, $workspace );
	if ( is_wp_error( $authorized ) ) {
		return $authorized;
	}
	return extrachill_users_grant_artist_access( absint( $input['user_id'] ?? 0 ), sanitize_text_field( $input['type'] ?? '' ) );
}

/**
 * Stage and sign an approval, retaining the action ID even if signing fails.
 * No reject link: pending-action rejection does not clear the access request.
 *
 * @return string Approve URL, or empty string for manage-only email fallback.
 */
function extrachill_users_stage_artist_access_approval( $user_id, $user, $type ) {
	if ( ! class_exists( PendingActionHelper::class ) || ! class_exists( SignPendingActionResolutionAbility::class ) || ! class_exists( PendingActionAuthorizationReceipt::class ) ) {
		return '';
	}
	$input   = array(
		'user_id' => (int) $user_id,
		'type'    => $type,
	);
	$summary = sprintf( 'Approve artist platform access for %s (%s) as %s', $user->display_name, $user->user_email, $type );
	$staged  = PendingActionHelper::stage(
		array(
			'kind'            => 'extrachill_artist_access',
			'summary'         => $summary,
			'apply_input'     => $input,
			'preview_data'    => $input + array( 'summary' => $summary ),
			'ttl'             => 2592000,
			'resolver_grants' => array(
				array(
					'type'  => 'operator',
					'kinds' => array( 'extrachill_artist_access' ),
				),
			),
		)
	);
	if ( isset( $staged['staged'] ) && false === $staged['staged'] ) {
		return '';
	}
	$action_id = (string) ( $staged['action_id'] ?? $staged['payload']['pending_action']['action_id'] ?? $staged['payload']['action_id'] ?? '' );
	if ( '' === $action_id ) {
		return '';
	}
	$request = get_user_meta( $user_id, 'artist_access_request', true );
	if ( is_array( $request ) ) {
		$request['action_id'] = $action_id;
		update_user_meta( $user_id, 'artist_access_request', $request );
	}
	$signed = SignPendingActionResolutionAbility::execute(
		array(
			'action_id' => $action_id,
			'lifetime'  => 2592000,
		)
	);
	return ! empty( $signed['success'] ) ? (string) ( $signed['approve_url'] ?? '' ) : '';
}
