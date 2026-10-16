<?php
/**
 * Photo Contest domain: voting.
 *
 * Delegates to the shared image-voting backend with the entry-derived
 * instance key, after enforcing the voting window and entry visibility.
 * No IP handling and no rate limiting live here: per-client admission is
 * owned by the transport layer (extrachill-api public-write admission).
 *
 * @package ExtraChillContentBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Process a vote on a contest entry (business logic).
 *
 * Dedup (one vote per email per entry) happens inside
 * extrachill_content_blocks_process_image_vote(); the newsletter subscribe
 * uses the dedicated photo_contest integration context.
 *
 * @param int      $post_id    Contest post ID.
 * @param string   $contest_id Contest block instance id.
 * @param int      $entry_id   Entry attachment ID.
 * @param string   $email      Voter email.
 * @param int|null $now        Timestamp override for window checks (tests).
 * @return array|\WP_Error array{message, vote_count} on success, status-coded WP_Error on failure.
 */
function extrachill_content_blocks_photo_contest_process_vote( $post_id, $contest_id, $entry_id, $email, $now = null ) {
	$settings = extrachill_content_blocks_photo_contest_get_settings( $post_id, $contest_id );
	if ( null === $settings ) {
		return extrachill_content_blocks_photo_contest_error(
			'contest_not_found',
			'Contest not found.',
			404
		);
	}

	if ( extrachill_content_blocks_photo_contest_is_closed( $settings['voting_closes_at'], $now ) ) {
		return extrachill_content_blocks_photo_contest_error(
			'voting_closed',
			'Voting is closed for this contest.',
			403
		);
	}

	if ( ! extrachill_content_blocks_photo_contest_is_valid_entry( $post_id, $contest_id, $entry_id ) ) {
		return extrachill_content_blocks_photo_contest_error(
			'entry_not_found',
			'Entry not found.',
			404
		);
	}

	$result = extrachill_content_blocks_process_image_vote(
		(int) $post_id,
		extrachill_content_blocks_photo_contest_entry_instance_id( $contest_id, $entry_id ),
		(string) $email,
		'photo_contest'
	);

	if ( empty( $result['success'] ) ) {
		$code = (string) ( $result['code'] ?? 'vote_failed' );

		return extrachill_content_blocks_photo_contest_error(
			$code,
			(string) ( $result['message'] ?? 'Vote failed.' ),
			'already_voted' === $code ? 409 : 500
		);
	}

	return array(
		'message'    => (string) $result['message'],
		'vote_count' => (int) $result['vote_count'],
	);
}
