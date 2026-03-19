<?php
/**
 * Moderation Policies
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

function extrachill_users_get_moderation_policy_definitions() {
	return array(
		'spam'          => array(
			'label'   => __( 'Spam', 'extrachill-users' ),
			'effects' => array(
				'block_login'       => true,
				'revoke_sessions'   => true,
				'send_email'        => true,
				'hide_content'      => true,
				'mark_content_spam' => true,
			),
		),
		'abuse'         => array(
			'label'   => __( 'Abuse', 'extrachill-users' ),
			'effects' => array(
				'block_login'       => true,
				'revoke_sessions'   => true,
				'send_email'        => true,
				'hide_content'      => false,
				'mark_content_spam' => false,
			),
		),
		'impersonation' => array(
			'label'   => __( 'Impersonation', 'extrachill-users' ),
			'effects' => array(
				'block_login'       => true,
				'revoke_sessions'   => true,
				'send_email'        => true,
				'hide_content'      => false,
				'mark_content_spam' => false,
			),
		),
		'fraud'         => array(
			'label'   => __( 'Fraud', 'extrachill-users' ),
			'effects' => array(
				'block_login'       => true,
				'revoke_sessions'   => true,
				'send_email'        => true,
				'hide_content'      => false,
				'mark_content_spam' => false,
			),
		),
		'other'         => array(
			'label'   => __( 'Other', 'extrachill-users' ),
			'effects' => array(
				'block_login'       => true,
				'revoke_sessions'   => true,
				'send_email'        => true,
				'hide_content'      => false,
				'mark_content_spam' => false,
			),
		),
	);
}

function extrachill_users_get_moderation_policy( string $reason_key ) {
	$policies = extrachill_users_get_moderation_policy_definitions();
	return $policies[ $reason_key ] ?? $policies['other'];
}
