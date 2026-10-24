<?php
/**
 * Photo Contest domain: entry submission.
 *
 * No IP handling and no rate limiting live here: per-client admission is
 * owned by the transport layer (extrachill-api public-write admission).
 *
 * @package ExtraChillContentBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize an Instagram handle to Instagram's valid charset.
 *
 * Accepts "@handle", a bare handle, or a pasted profile URL and reduces it to
 * letters, digits, periods, and underscores (max 30 chars — Instagram's
 * username limit). The stored handle is rendered verbatim into
 * https://instagram.com/<handle> links on the frontend, so anything outside
 * the valid charset is stripped rather than escaped.
 *
 * @param string $handle Raw handle input.
 * @return string Normalized handle ('' when nothing valid remains).
 */
function extrachill_content_blocks_photo_contest_sanitize_instagram( $handle ) {
	$handle = trim( (string) $handle );

	// Reduce a pasted profile URL to its path (people paste their profile link).
	$handle = (string) preg_replace( '#^https?://(www\.)?instagram\.com/#i', '', $handle );

	// Drop a leading @ and anything after a path/query separator.
	$handle = ltrim( $handle, '@' );
	$handle = (string) preg_replace( '#[/?\#].*$#', '', $handle );

	// Instagram usernames: letters, digits, periods, underscores.
	$handle = (string) preg_replace( '/[^A-Za-z0-9._]/', '', $handle );

	return substr( $handle, 0, 30 );
}

/**
 * Validate a $_FILES-shaped upload before touching the filesystem.
 *
 * @param mixed $file Candidate file array.
 * @return true|\WP_Error True when valid, else a status-coded WP_Error.
 */
function extrachill_content_blocks_photo_contest_validate_photo( $file ) {
	if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || empty( $file['name'] ) ) {
		return extrachill_content_blocks_photo_contest_error(
			'missing_photo',
			'A photo is required to enter.',
			400
		);
	}

	if ( isset( $file['error'] ) && 0 !== (int) $file['error'] ) {
		return extrachill_content_blocks_photo_contest_error(
			'upload_failed',
			'The photo upload failed — please try again.',
			400
		);
	}

	if ( isset( $file['size'] ) && (int) $file['size'] > EXTRACHILL_PHOTO_CONTEST_MAX_UPLOAD_BYTES ) {
		return extrachill_content_blocks_photo_contest_error(
			'photo_too_large',
			'Photo is too large (10 MB max).',
			400
		);
	}

	$filetype = wp_check_filetype( (string) $file['name'], extrachill_content_blocks_photo_contest_allowed_mimes() );
	if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
		return extrachill_content_blocks_photo_contest_error(
			'invalid_photo_type',
			'Please upload a JPG, PNG, GIF, WEBP, or HEIC photo.',
			400
		);
	}

	return true;
}

/**
 * Process a photo-contest entry submission (business logic).
 *
 * Validation order: honeypot → contest exists → entries window → email →
 * name → duplicate entry → photo validity → duplicate photo → store.
 * Entries go live immediately; admins hide bad ones via the hide ability /
 * the front-end moderator controls.
 *
 * @param array $args {
 *     Submission arguments.
 *
 *     @type int    $post_id      Contest post ID.
 *     @type string $contest_id   Contest block instance id.
 *     @type string $email        Entrant email.
 *     @type string $display_name Entrant display name.
 *     @type string $instagram    Optional Instagram handle.
 *     @type string $honeypot     Honeypot field value (must be empty).
 *     @type array  $file         One $_FILES-shaped upload.
 *     @type int    $now          Timestamp override for window checks (tests).
 * }
 * @return array|\WP_Error array{message, entry} on success, status-coded WP_Error on failure.
 */
function extrachill_content_blocks_photo_contest_submit_entry( array $args ) {
	$post_id      = (int) ( $args['post_id'] ?? 0 );
	$contest_id   = (string) ( $args['contest_id'] ?? '' );
	$email        = strtolower( trim( (string) ( $args['email'] ?? '' ) ) );
	$display_name = sanitize_text_field( (string) ( $args['display_name'] ?? '' ) );
	$instagram    = extrachill_content_blocks_photo_contest_sanitize_instagram( (string) ( $args['instagram'] ?? '' ) );
	$honeypot     = trim( (string) ( $args['honeypot'] ?? '' ) );
	$file         = $args['file'] ?? null;
	$now          = isset( $args['now'] ) ? (int) $args['now'] : null;

	// Honeypot: bots fill every field. Fail without detail.
	if ( '' !== $honeypot ) {
		return extrachill_content_blocks_photo_contest_error(
			'invalid_submission',
			'Submission could not be processed.',
			403
		);
	}

	$settings = extrachill_content_blocks_photo_contest_get_settings( $post_id, $contest_id );
	if ( null === $settings ) {
		return extrachill_content_blocks_photo_contest_error(
			'contest_not_found',
			'Contest not found.',
			404
		);
	}

	if ( ! extrachill_content_blocks_photo_contest_has_opened( $settings['entries_open_at'], $now ) ) {
		return extrachill_content_blocks_photo_contest_error(
			'entries_not_open',
			'Entries open ' . extrachill_content_blocks_photo_contest_format_time( $settings['entries_open_at'] ) . '.',
			403
		);
	}

	if ( extrachill_content_blocks_photo_contest_is_closed( $settings['entries_close_at'], $now ) ) {
		return extrachill_content_blocks_photo_contest_error(
			'entries_closed',
			'Entries are closed for this contest.',
			403
		);
	}

	// Team-added entries: a logged-in moderator can enter someone without an
	// email (backup path when the entrant can't or won't submit themselves).
	$team_added = extrachill_content_blocks_photo_contest_can_moderate();

	if ( ! ( $team_added && '' === $email ) && ! is_email( $email ) ) {
		return extrachill_content_blocks_photo_contest_error(
			'invalid_email',
			'A valid email address is required.',
			400
		);
	}

	if ( '' === $display_name ) {
		return extrachill_content_blocks_photo_contest_error(
			'missing_name',
			'A display name is required.',
			400
		);
	}

	// One entry per email per contest. Return the existing entry so the
	// entrant gets their share link back instead of a dead end.
	$existing_id = '' === $email ? 0 : extrachill_content_blocks_photo_contest_find_entry_by_email( $post_id, $contest_id, $email );
	if ( $existing_id > 0 ) {
		return extrachill_content_blocks_photo_contest_error(
			'already_entered',
			'You already entered this contest — share your entry to get votes!',
			409,
			array( 'entry' => extrachill_content_blocks_photo_contest_entry_data( $existing_id ) )
		);
	}

	$valid = extrachill_content_blocks_photo_contest_validate_photo( $file );
	if ( true !== $valid ) {
		return $valid;
	}

	// Exact-duplicate photo (same content hash within this contest), checked
	// before the file is stored. Hidden entries count: hiding a photo must
	// not allow the identical file back in.
	$photo_hash = extrachill_content_blocks_photo_contest_photo_hash( $file );
	if ( '' !== $photo_hash && extrachill_content_blocks_photo_contest_find_entry_by_hash( $post_id, $contest_id, $photo_hash ) > 0 ) {
		return extrachill_content_blocks_photo_contest_error(
			'duplicate_photo',
			'That photo has already been entered in this contest — try a different shot!',
			409
		);
	}

	$stored = extrachill_content_blocks_photo_contest_store_photo( $post_id, $file, $display_name );
	if ( is_wp_error( $stored ) ) {
		return $stored;
	}

	$entry_id = (int) $stored;

	update_post_meta( $entry_id, EXTRACHILL_PHOTO_CONTEST_META_CONTEST, $contest_id );
	if ( '' !== $email ) {
		update_post_meta( $entry_id, EXTRACHILL_PHOTO_CONTEST_META_EMAIL, $email );
	}
	if ( $team_added ) {
		update_post_meta( $entry_id, '_photo_contest_added_by', get_current_user_id() );
	}
	update_post_meta( $entry_id, EXTRACHILL_PHOTO_CONTEST_META_NAME, $display_name );
	if ( '' !== $photo_hash ) {
		update_post_meta( $entry_id, EXTRACHILL_PHOTO_CONTEST_META_HASH, $photo_hash );
	}
	if ( '' !== $instagram ) {
		update_post_meta( $entry_id, EXTRACHILL_PHOTO_CONTEST_META_INSTAGRAM, $instagram );
	}

	// Newsletter sync (non-blocking), dedicated photo_contest integration.
	// extrachill_network_subscribe is a cross-plugin bridge (extrachill-newsletter),
	// so the function_exists guard stays.
	if ( '' !== $email && function_exists( 'extrachill_network_subscribe' ) ) {
		$subscription_result = extrachill_network_subscribe( $email, 'photo_contest' );
		if ( empty( $subscription_result['success'] ) ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'Photo contest newsletter subscription failed for %s: %s',
					$email,
					(string) ( $subscription_result['message'] ?? 'unknown error' )
				)
			);
		}
	}

	return array(
		'message' => 'Entry submitted! Share your link to get votes.',
		'entry'   => extrachill_content_blocks_photo_contest_entry_data( $entry_id ),
	);
}
