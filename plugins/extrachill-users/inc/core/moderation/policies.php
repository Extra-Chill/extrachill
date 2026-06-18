<?php
/**
 * Moderation Policies
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-reason moderation policy map.
 *
 * The `reason_key` chosen for a ban is also the suspend-vs-hide-content switch:
 * the same value that records *why* a user was banned silently decides whether
 * their existing content stays live or gets hidden. Operators who want to ban a
 * user but keep their content (a pure login suspension) should pick `other` or
 * `impersonation`; `spam`/`abuse`/`fraud` additionally hide content.
 *
 * Effects per reason_key:
 *
 *   reason_key    | block_login | revoke_sessions | send_email | hide_content | mark_content_spam
 *   --------------|-------------|-----------------|------------|--------------|------------------
 *   spam          | yes         | yes             | yes        | YES          | YES
 *   abuse         | yes         | yes             | yes        | YES          | no
 *   impersonation | yes         | yes             | yes        | no           | no
 *   fraud         | yes         | yes             | yes        | YES          | no
 *   other         | yes         | yes             | yes        | no           | no
 *
 * When `hide_content` is set, the user's content is hidden via
 * extrachill_users_apply_spam_visibility_to_user_content(): posts go to draft and
 * bbPress topics/replies and comments are marked spam. `mark_content_spam`
 * (spam only) additionally flags the content as spam rather than merely hiding it.
 * `impersonation`/`other` leave all content live and only suspend login.
 *
 * @return array Map of reason_key => array{ label: string, effects: array<string,bool> }.
 */
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
				'hide_content'      => true,
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
				'hide_content'      => true,
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
