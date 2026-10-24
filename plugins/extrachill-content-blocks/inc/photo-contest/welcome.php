<?php
/**
 * Photo Contest domain: participant event + per-contest welcome email.
 *
 * Entry and first-vote fire `extrachill_content_blocks_photo_contest_participant`.
 * The listener here sends the contest's welcome email when the block has copy
 * for that role (empty copy = off). Delivery goes through the platform mail
 * bridge (ec_send_email_queued → datamachine/send-email-queued), so a slow or
 * failed send never blocks an entry or vote.
 *
 * Copy placeholders: {name} (entrant), {costume} (entry voted for / entered),
 * {entry_link}, {contest_link}.
 *
 * @package ExtraChillContentBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fire the participant event.
 *
 * @param string $role       'entrant' or 'voter'.
 * @param int    $post_id    Contest post ID.
 * @param string $contest_id Contest block instance id.
 * @param int    $entry_id   Entry entered or voted for.
 * @param string $email      Participant email.
 */
function extrachill_content_blocks_photo_contest_fire_participant( $role, $post_id, $contest_id, $entry_id, $email ) {
	if ( '' === (string) $email ) {
		return;
	}

	do_action(
		'extrachill_content_blocks_photo_contest_participant',
		array(
			'role'       => (string) $role,
			'post_id'    => (int) $post_id,
			'contest_id' => (string) $contest_id,
			'entry_id'   => (int) $entry_id,
			'email'      => (string) $email,
		)
	);
}

/**
 * Whether this is the email's first vote anywhere in the contest.
 *
 * Call after the vote is stored. Votes are image_vote comments on the contest
 * post whose instance_id is `<contest_id>:entry-<id>`.
 *
 * @param int    $post_id    Contest post ID.
 * @param string $contest_id Contest block instance id.
 * @param string $email      Voter email.
 * @return bool
 */
function extrachill_content_blocks_photo_contest_is_first_vote( $post_id, $contest_id, $email ) {
	$count = get_comments(
		array(
			'post_id'      => (int) $post_id,
			'author_email' => (string) $email,
			'type'         => EXTRACHILL_IMAGE_VOTE_COMMENT_TYPE,
			'meta_key'     => 'instance_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'   => (string) $contest_id . ':entry-', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_compare' => 'LIKE',
			'status'       => 'approve',
			'count'        => true,
		)
	);

	return 1 === (int) $count;
}

/**
 * Build the welcome email for a participant event (pure given settings).
 *
 * @param array  $settings Contest settings (welcome_* keys).
 * @param array  $event    Participant event payload.
 * @param array  $entry    Entry data (display_name, share_url).
 * @param string $contest_url Contest page URL.
 * @return array|null ec_send_email args, or null when this role has no copy.
 */
function extrachill_content_blocks_photo_contest_welcome_message( array $settings, array $event, array $entry, $contest_url ) {
	$role = 'entrant' === ( $event['role'] ?? '' ) ? 'entrant' : 'voter';
	$body = trim( (string) ( $settings[ 'welcome_' . $role . '_body' ] ?? '' ) );
	if ( '' === $body || '' === (string) ( $event['email'] ?? '' ) ) {
		return null;
	}

	$costume    = (string) ( $entry['display_name'] ?? '' );
	$entry_link = (string) ( $entry['share_url'] ?? '' );
	$vars       = array(
		'{name}'         => 'entrant' === $role ? $costume : '',
		'{costume}'      => $costume,
		'{entry_link}'   => $entry_link,
		'{contest_link}' => (string) $contest_url,
	);

	$subject = trim( strtr( (string) ( $settings['welcome_subject'] ?? '' ), $vars ) );
	if ( '' === $subject ) {
		$subject = 'entrant' === $role ? 'Your entry is in!' : 'Thanks for voting!';
	}

	// Escape the copy, then substitute (escaped) values and linkify URLs.
	$html = esc_html( $body );
	foreach ( $vars as $token => $value ) {
		$html = str_replace( $token, esc_html( $value ), $html );
	}
	$html       = make_clickable( $html );
	$paragraphs = preg_split( "/\n\s*\n/", str_replace( "\r", '', $html ) );
	$body_html  = '';
	foreach ( $paragraphs as $paragraph ) {
		if ( '' !== trim( $paragraph ) ) {
			$body_html .= '<p>' . nl2br( trim( $paragraph ) ) . '</p>';
		}
	}

	return array(
		'to'       => (string) $event['email'],
		'subject'  => $subject,
		'template' => 'extrachill/branded',
		'context'  => array(
			'recipient_name' => $vars['{name}'],
			'body_html'      => $body_html,
			'cta_url'        => 'entrant' === $role ? $entry_link : (string) $contest_url,
			'cta_label'      => 'entrant' === $role ? 'See your entry' : 'See all entries',
			'preheader'      => $subject,
		),
	);
}

/**
 * Participant listener: queue the contest's welcome email.
 *
 * @param array $event Participant event payload.
 */
function extrachill_content_blocks_photo_contest_send_welcome( $event ) {
	if ( ! is_array( $event ) || ! function_exists( 'ec_send_email_queued' ) ) {
		return;
	}

	$settings = extrachill_content_blocks_photo_contest_get_settings( (int) $event['post_id'], (string) $event['contest_id'] );
	if ( null === $settings ) {
		return;
	}

	$message = extrachill_content_blocks_photo_contest_welcome_message(
		$settings,
		$event,
		extrachill_content_blocks_photo_contest_entry_data( (int) $event['entry_id'] ),
		(string) get_permalink( (int) $event['post_id'] )
	);
	if ( null === $message ) {
		return;
	}

	// ec_send_email_queued is the cross-plugin mail bridge (extrachill-network).
	$result = ec_send_email_queued( $message );
	if ( empty( $result['success'] ) ) {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'Photo contest welcome email failed for %s: %s',
				(string) $event['email'],
				(string) ( $result['error'] ?? $result['message'] ?? 'unknown error' )
			)
		);
	}
}
add_action( 'extrachill_content_blocks_photo_contest_participant', 'extrachill_content_blocks_photo_contest_send_welcome' );
