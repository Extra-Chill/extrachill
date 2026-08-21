<?php
/**
 * Moderation Email Helpers
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build the per-reason moderation email copy map.
 *
 * Each entry is keyed by `reason_key` (the same keys defined in
 * extrachill_users_get_moderation_policy_definitions(): spam, abuse,
 * impersonation, fraud, other) and provides the subject + body copy for the
 * notice sent to the actioned user. A special `__suspended` key supplies the
 * copy used when the moderation state is `suspended` rather than `banned`, and
 * `__default` is the fallback when a reason_key has no explicit entry.
 *
 * The whole map is filterable so operators or other plugins can override or
 * extend the wording without forking this plugin:
 *
 *     add_filter( 'extrachill_users_moderation_email_copy', function ( $copy ) {
 *         $copy['spam']['body'] = __( 'Custom spam notice…', 'my-plugin' );
 *         return $copy;
 *     } );
 *
 * Each body string is plain text; it is wrapped in a paragraph and escaped by
 * the caller. Keep all copy firm, factual, and professional — it lands in a
 * real inbox.
 *
 * @return array<string,array{subject:string,body:string}> Copy map.
 */
function extrachill_users_get_moderation_email_copy() {
	$copy = array(
		'spam'          => array(
			'subject' => __( 'Your Extra Chill account has been permanently banned for spam', 'extrachill-users' ),
			'body'    => __( 'Your Extra Chill account has been permanently banned for posting spam, and the content associated with your account has been removed from public view. This decision is final. If you believe this was made in error, you may reply to this email.', 'extrachill-users' ),
		),
		'abuse'         => array(
			'subject' => __( 'Your Extra Chill account has been banned', 'extrachill-users' ),
			'body'    => __( 'Your Extra Chill account has been banned for abusive behavior that violates our community guidelines, and your content has been removed from public view. We take the safety and respect of our community seriously. If you believe this was made in error, you may reply to this email to appeal.', 'extrachill-users' ),
		),
		'impersonation' => array(
			'subject' => __( 'Your Extra Chill account has been banned', 'extrachill-users' ),
			'body'    => __( 'Your Extra Chill account has been banned for impersonating another person, artist, or organization. Misrepresenting your identity is not permitted on Extra Chill. If you believe this was made in error, you may reply to this email to appeal.', 'extrachill-users' ),
		),
		'fraud'         => array(
			'subject' => __( 'Your Extra Chill account has been banned', 'extrachill-users' ),
			'body'    => __( 'Your Extra Chill account has been banned for fraudulent activity, and your content has been removed from public view. Fraud and deceptive practices are strictly prohibited on Extra Chill. If you believe this was made in error, you may reply to this email to appeal.', 'extrachill-users' ),
		),
		'other'         => array(
			'subject' => __( 'Your Extra Chill account has been banned', 'extrachill-users' ),
			'body'    => __( 'Your Extra Chill account has been banned for violating our community guidelines. If you believe this was made in error, you may reply to this email to appeal.', 'extrachill-users' ),
		),
		'__suspended'   => array(
			'subject' => __( 'Your Extra Chill account has been suspended', 'extrachill-users' ),
			'body'    => __( 'Your Extra Chill account has been suspended pending review. While suspended, you will not be able to sign in. If you believe this was made in error, you may reply to this email.', 'extrachill-users' ),
		),
		'__default'     => array(
			'subject' => __( 'Your Extra Chill account has been banned', 'extrachill-users' ),
			'body'    => __( 'Your Extra Chill account has been banned. If you believe this was made in error, you may reply to this email to appeal.', 'extrachill-users' ),
		),
	);

	/**
	 * Filter the per-reason moderation email copy map.
	 *
	 * @param array<string,array{subject:string,body:string}> $copy Copy map keyed by reason_key
	 *                                                              (plus `__suspended` and `__default`).
	 */
	$copy = apply_filters( 'extrachill_users_moderation_email_copy', $copy );

	return is_array( $copy ) ? $copy : array();
}

/**
 * Resolve the subject + body copy for a given moderation status.
 *
 * Suspensions take precedence over reason-specific copy (a suspended account
 * has not been permanently actioned). Otherwise the reason_key selects the
 * copy, falling back to `__default` for unknown keys.
 *
 * @param string $reason_key Moderation reason key.
 * @param string $state      Moderation state (e.g. `banned`, `suspended`).
 * @return array{subject:string,body:string} Resolved copy.
 */
function extrachill_users_resolve_moderation_email_copy( string $reason_key, string $state ) {
	$copy = extrachill_users_get_moderation_email_copy();

	if ( 'suspended' === $state && isset( $copy['__suspended'] ) ) {
		$entry = $copy['__suspended'];
	} elseif ( isset( $copy[ $reason_key ] ) ) {
		$entry = $copy[ $reason_key ];
	} elseif ( isset( $copy['__default'] ) ) {
		$entry = $copy['__default'];
	} else {
		$entry = array(
			'subject' => __( 'Your Extra Chill account has been banned', 'extrachill-users' ),
			'body'    => __( 'Your Extra Chill account has been banned.', 'extrachill-users' ),
		);
	}

	return array(
		'subject' => isset( $entry['subject'] ) ? (string) $entry['subject'] : '',
		'body'    => isset( $entry['body'] ) ? (string) $entry['body'] : '',
	);
}

/**
 * Send the moderation notice email to an actioned user.
 *
 * Copy is resolved per reason_key (and state) from the filterable
 * extrachill_users_get_moderation_email_copy() map. The send is queued via
 * ec_send_email_queued() so moderation actions do not block on SMTP.
 *
 * @param WP_User $user   Actioned user.
 * @param array   $status Moderation status payload (state, reason_key, reason, …).
 * @return bool Whether the queue/send call reported success.
 */
function extrachill_users_send_moderation_email( WP_User $user, array $status ) {
	$reason_key = isset( $status['reason_key'] ) ? (string) $status['reason_key'] : 'other';
	$state      = isset( $status['state'] ) ? (string) $status['state'] : 'banned';
	$reason     = isset( $status['reason'] ) ? (string) $status['reason'] : '';

	$resolved = extrachill_users_resolve_moderation_email_copy( $reason_key, $state );
	$subject  = $resolved['subject'];
	$message  = $resolved['body'];

	$body_html = '<p>' . esc_html( $message ) . '</p>';

	if ( $reason ) {
		$body_html .= '<p><strong>' . esc_html__( 'Reason:', 'extrachill-users' ) . '</strong> ' . esc_html( $reason ) . '</p>';
	}

	$result = ec_send_email_queued(
		array(
			'to'       => $user->user_email,
			'subject'  => $subject,
			'template' => 'extrachill/minimal',
			'context'  => array(
				'subject_html'   => esc_html( $subject ),
				'body_html'      => $body_html,
				'recipient_name' => $user->display_name,
			),
		)
	);

	return is_array( $result ) && ! empty( $result['success'] );
}
