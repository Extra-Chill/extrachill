<?php
/**
 * Photo Contest Block business logic.
 *
 * A photo contest is one block instance (keyed by its contestId attribute) on
 * a contest post. Entries are user-submitted photos stored as native WordPress
 * attachments parented to the contest post, with entry details (contest id,
 * entrant email, display name, Instagram handle, hidden flag) in post meta.
 * Entries are data — never written into post_content — so concurrent public
 * submissions can never clobber the page (the read-modify-write pattern
 * removed in issue #13 stays dead).
 *
 * Voting fully reuses the image-voting backend: each vote is one anonymous
 * image_vote comment on the contest post, deduped per (post × instance ×
 * email) by extrachill_content_blocks_process_image_vote(). The per-entry
 * vote key is a derived instance id of the form "<contestId>:entry-<entryId>"
 * so every entry gets its own dedup bucket while the existing image-voting
 * block keeps working untouched.
 *
 * REST transport lives in extrachill-api under
 * /extrachill/v1/content-blocks/photo-contest/ (submit, entries, vote); the
 * abilities in inc/abilities/photo-contest-*.php are the canonical seam.
 *
 * @package ExtraChillContentBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Entry meta keys (on the attachment).
if ( ! defined( 'EXTRACHILL_PHOTO_CONTEST_META_CONTEST' ) ) {
	define( 'EXTRACHILL_PHOTO_CONTEST_META_CONTEST', '_photo_contest_id' );
}
if ( ! defined( 'EXTRACHILL_PHOTO_CONTEST_META_EMAIL' ) ) {
	define( 'EXTRACHILL_PHOTO_CONTEST_META_EMAIL', '_photo_contest_entrant_email' );
}
if ( ! defined( 'EXTRACHILL_PHOTO_CONTEST_META_NAME' ) ) {
	define( 'EXTRACHILL_PHOTO_CONTEST_META_NAME', '_photo_contest_display_name' );
}
if ( ! defined( 'EXTRACHILL_PHOTO_CONTEST_META_INSTAGRAM' ) ) {
	define( 'EXTRACHILL_PHOTO_CONTEST_META_INSTAGRAM', '_photo_contest_instagram' );
}
if ( ! defined( 'EXTRACHILL_PHOTO_CONTEST_META_HIDDEN' ) ) {
	define( 'EXTRACHILL_PHOTO_CONTEST_META_HIDDEN', '_photo_contest_hidden' );
}

/**
 * Maximum accepted photo upload size in bytes (10 MB).
 */
if ( ! defined( 'EXTRACHILL_PHOTO_CONTEST_MAX_UPLOAD_BYTES' ) ) {
	define( 'EXTRACHILL_PHOTO_CONTEST_MAX_UPLOAD_BYTES', 10 * 1024 * 1024 );
}

/**
 * Submissions allowed per IP inside the rate-limit window.
 */
if ( ! defined( 'EXTRACHILL_PHOTO_CONTEST_RATE_LIMIT' ) ) {
	define( 'EXTRACHILL_PHOTO_CONTEST_RATE_LIMIT', 5 );
}

/**
 * Rate-limit window in seconds (10 minutes).
 */
if ( ! defined( 'EXTRACHILL_PHOTO_CONTEST_RATE_WINDOW' ) ) {
	define( 'EXTRACHILL_PHOTO_CONTEST_RATE_WINDOW', 600 );
}

/**
 * Votes allowed per IP inside the rate-limit window. Deliberately generous:
 * a whole party may share venue wifi behind one IP.
 */
if ( ! defined( 'EXTRACHILL_PHOTO_CONTEST_VOTE_RATE_LIMIT' ) ) {
	define( 'EXTRACHILL_PHOTO_CONTEST_VOTE_RATE_LIMIT', 60 );
}

/**
 * Image MIME types accepted for entry photos.
 *
 * @return array<string, string> ext-pattern => mime map (wp_handle_upload shape).
 */
function extrachill_content_blocks_photo_contest_allowed_mimes() {
	return array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'heic'         => 'image/heic',
	);
}

/**
 * Derive the image_vote instance id for a contest entry.
 *
 * Votes reuse the image-voting comment backend; this key gives every entry
 * its own dedup bucket within the contest while remaining disjoint from the
 * plain image-voting block's uniqueBlockId namespace.
 *
 * @param string $contest_id Contest block instance id (contestId attribute).
 * @param int    $entry_id   Entry attachment ID.
 * @return string Vote instance key.
 */
function extrachill_content_blocks_photo_contest_entry_instance_id( $contest_id, $entry_id ) {
	return (string) $contest_id . ':entry-' . (int) $entry_id;
}

/**
 * Find a photo-contest block with the given contestId in a parsed block tree.
 *
 * Recurses into innerBlocks so a contest wrapped in a Group/Columns (or any
 * other container) is still found by the server-side window enforcement.
 *
 * @param array  $blocks     parse_blocks() output (or innerBlocks subtree).
 * @param string $contest_id Contest block instance id.
 * @return array|null Matching block attrs or null.
 */
function extrachill_content_blocks_photo_contest_find_block_attrs( array $blocks, $contest_id ) {
	foreach ( $blocks as $block ) {
		if ( ( $block['blockName'] ?? '' ) === 'extrachill/photo-contest' ) {
			$attrs = (array) ( $block['attrs'] ?? array() );
			if ( (string) ( $attrs['contestId'] ?? '' ) === (string) $contest_id ) {
				return $attrs;
			}
		}

		$inner = $block['innerBlocks'] ?? array();
		if ( is_array( $inner ) && ! empty( $inner ) ) {
			$found = extrachill_content_blocks_photo_contest_find_block_attrs( $inner, $contest_id );
			if ( null !== $found ) {
				return $found;
			}
		}
	}

	return null;
}

/**
 * Read a photo-contest block's settings out of the contest post's content.
 *
 * The close dates / finalists options are block attributes. Server-side
 * enforcement (submission + vote windows) reads them back out of post_content
 * with parse_blocks — a read, never a write. Nested blocks (e.g. inside a
 * Group) are found recursively.
 *
 * @param int    $post_id    Contest post ID.
 * @param string $contest_id Contest block instance id.
 * @return array|null Settings array or null when no matching block exists.
 */
function extrachill_content_blocks_photo_contest_get_settings( $post_id, $contest_id ) {
	$post = get_post( $post_id );
	if ( ! $post || '' === (string) $contest_id ) {
		return null;
	}

	$attrs = extrachill_content_blocks_photo_contest_find_block_attrs(
		parse_blocks( (string) $post->post_content ),
		$contest_id
	);

	if ( null === $attrs ) {
		return null;
	}

	return array(
		'entries_close_at' => (string) ( $attrs['entriesCloseAt'] ?? '' ),
		'voting_closes_at' => (string) ( $attrs['votingClosesAt'] ?? '' ),
		'show_finalists'   => (bool) ( $attrs['showFinalists'] ?? false ),
		'finalist_count'   => max( 1, (int) ( $attrs['finalistCount'] ?? 10 ) ),
	);
}

/**
 * Whether a close date (block attribute, site-local datetime string) has passed.
 *
 * Empty string means "no close date" and never closes.
 *
 * @param string   $close_at Datetime string from block attributes.
 * @param int|null $now      Unix timestamp override (tests); defaults to now.
 * @return bool True when closed.
 */
function extrachill_content_blocks_photo_contest_is_closed( $close_at, $now = null ) {
	$close_at = trim( (string) $close_at );
	if ( '' === $close_at ) {
		return false;
	}

	if ( function_exists( 'wp_timezone' ) ) {
		try {
			$dt        = new DateTimeImmutable( $close_at, wp_timezone() );
			$timestamp = $dt->getTimestamp();
		} catch ( Exception $e ) {
			return false;
		}
	} else {
		$timestamp = strtotime( $close_at );
		if ( false === $timestamp ) {
			return false;
		}
	}

	$now = null === $now ? time() : (int) $now;

	return $now >= $timestamp;
}

/**
 * Entry attachment IDs for a contest.
 *
 * @param int    $post_id        Contest post ID.
 * @param string $contest_id     Contest block instance id.
 * @param bool   $include_hidden Include admin-hidden entries.
 * @return int[] Attachment IDs, oldest first.
 */
function extrachill_content_blocks_photo_contest_get_entry_ids( $post_id, $contest_id, $include_hidden = false ) {
	$meta_query = array(
		array(
			'key'   => EXTRACHILL_PHOTO_CONTEST_META_CONTEST,
			'value' => (string) $contest_id,
		),
	);

	if ( ! $include_hidden ) {
		$meta_query[] = array(
			'key'     => EXTRACHILL_PHOTO_CONTEST_META_HIDDEN,
			'compare' => 'NOT EXISTS',
		);
	}

	$ids = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_parent'    => (int) $post_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		)
	);

	return array_map( 'intval', (array) $ids );
}

/**
 * Find an existing entry for an email within a contest (one entry per email).
 *
 * Includes hidden entries so a hidden entrant cannot simply resubmit.
 *
 * @param int    $post_id    Contest post ID.
 * @param string $contest_id Contest block instance id.
 * @param string $email      Entrant email.
 * @return int Entry attachment ID, or 0 when none.
 */
function extrachill_content_blocks_photo_contest_find_entry_by_email( $post_id, $contest_id, $email ) {
	$email = strtolower( trim( (string) $email ) );
	if ( '' === $email ) {
		return 0;
	}

	$ids = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_parent'    => (int) $post_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => EXTRACHILL_PHOTO_CONTEST_META_CONTEST,
					'value' => (string) $contest_id,
				),
				array(
					'key'   => EXTRACHILL_PHOTO_CONTEST_META_EMAIL,
					'value' => $email,
				),
			),
		)
	);

	return empty( $ids ) ? 0 : (int) $ids[0];
}

/**
 * Public data for one entry. Never includes the entrant email.
 *
 * @param int $entry_id Entry attachment ID.
 * @return array|null Entry data or null for a non-entry attachment.
 */
function extrachill_content_blocks_photo_contest_entry_data( $entry_id ) {
	$entry = get_post( $entry_id );
	if ( ! $entry || 'attachment' !== $entry->post_type ) {
		return null;
	}

	$contest_id = (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_CONTEST, true );
	if ( '' === $contest_id ) {
		return null;
	}

	$post_id = (int) $entry->post_parent;

	$image_url = function_exists( 'wp_get_attachment_image_url' )
		? (string) wp_get_attachment_image_url( $entry->ID, 'large' )
		: '';
	if ( '' === $image_url ) {
		$image_url = (string) wp_get_attachment_url( $entry->ID );
	}

	$thumbnail_url = function_exists( 'wp_get_attachment_image_url' )
		? (string) wp_get_attachment_image_url( $entry->ID, 'medium_large' )
		: '';
	if ( '' === $thumbnail_url ) {
		$thumbnail_url = $image_url;
	}

	$share_url = add_query_arg( 'entry', (int) $entry->ID, (string) get_permalink( $post_id ) );

	return array(
		'id'            => (int) $entry->ID,
		'display_name'  => (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_NAME, true ),
		'instagram'     => (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_INSTAGRAM, true ),
		'image_url'     => $image_url,
		'thumbnail_url' => $thumbnail_url,
		'vote_count'    => extrachill_content_blocks_image_vote_count(
			$post_id,
			extrachill_content_blocks_photo_contest_entry_instance_id( $contest_id, $entry->ID )
		),
		'share_url'     => $share_url,
		'hidden'        => '' !== (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_HIDDEN, true ),
	);
}

/**
 * All visible entries for a contest with live vote counts.
 *
 * Order is insertion order; the frontend shuffles client-side on every page
 * load (the page is full-page cached, so a server-side shuffle would freeze).
 *
 * @param int    $post_id        Contest post ID.
 * @param string $contest_id     Contest block instance id.
 * @param bool   $include_hidden Include admin-hidden entries.
 * @return array[] Entry data arrays.
 */
function extrachill_content_blocks_photo_contest_get_entries( $post_id, $contest_id, $include_hidden = false ) {
	$entries = array();
	foreach ( extrachill_content_blocks_photo_contest_get_entry_ids( $post_id, $contest_id, $include_hidden ) as $entry_id ) {
		$data = extrachill_content_blocks_photo_contest_entry_data( $entry_id );
		if ( null !== $data ) {
			$entries[] = $data;
		}
	}

	return $entries;
}

/**
 * Top N entries by vote count (ties broken by earliest entry).
 *
 * @param int    $post_id        Contest post ID.
 * @param string $contest_id     Contest block instance id.
 * @param int    $limit          Number of entries to return.
 * @param bool   $include_hidden Include admin-hidden entries.
 * @return array[] Ranked entry data arrays.
 */
function extrachill_content_blocks_photo_contest_top_entries( $post_id, $contest_id, $limit = 10, $include_hidden = false ) {
	$entries = extrachill_content_blocks_photo_contest_get_entries( $post_id, $contest_id, $include_hidden );

	usort(
		$entries,
		static function ( $a, $b ) {
			if ( $a['vote_count'] !== $b['vote_count'] ) {
				return $b['vote_count'] <=> $a['vote_count'];
			}
			return $a['id'] <=> $b['id'];
		}
	);

	return array_slice( $entries, 0, max( 1, (int) $limit ) );
}

/**
 * Store an uploaded entry photo as an attachment parented to the contest post.
 *
 * Guarded with function_exists so tests can substitute a fake that skips the
 * filesystem. Mirrors the anonymous-upload precedent in extrachill-events'
 * EventSubmissionAbilities::storeFlyer() (wp_handle_upload, test_form=false)
 * but keeps the file in the media library as a real attachment.
 *
 * @param int    $post_id      Contest post ID.
 * @param array  $file         One $_FILES-shaped entry.
 * @param string $display_name Entrant display name (used for the title).
 * @return int|array Attachment ID, or array{success:false,...} on failure.
 */
if ( ! function_exists( 'extrachill_content_blocks_photo_contest_store_photo' ) ) {
	/**
	 * Store an uploaded entry photo (see docblock above the guard).
	 *
	 * @param int    $post_id      Contest post ID.
	 * @param array  $file         One $_FILES-shaped entry.
	 * @param string $display_name Entrant display name.
	 * @return int|array Attachment ID or failure array.
	 */
	function extrachill_content_blocks_photo_contest_store_photo( $post_id, array $file, $display_name ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => extrachill_content_blocks_photo_contest_allowed_mimes(),
			)
		);

		if ( ! is_array( $upload ) || isset( $upload['error'] ) ) {
			return array(
				'success' => false,
				'message' => is_array( $upload ) && isset( $upload['error'] ) ? (string) $upload['error'] : 'Upload failed.',
				'code'    => 'upload_failed',
			);
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => (string) $upload['type'],
				'post_title'     => sanitize_text_field( $display_name . ' — contest entry' ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			(int) $post_id,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			return array(
				'success' => false,
				'message' => $attachment_id->get_error_message(),
				'code'    => 'upload_failed',
			);
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		if ( ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		return (int) $attachment_id;
	}
}

/**
 * Normalize an Instagram handle to Instagram's valid charset.
 *
 * Accepts "@handle", a bare handle, or a pasted profile URL and reduces it to
 * letters, digits, periods, and underscores (max 30 chars — Instagram's
 * username limit). The stored handle is rendered verbatim into
 * https://instagram.com/<handle> links on the frontend and into CLI output,
 * so anything outside the valid charset is stripped rather than escaped.
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
 * @return true|array True when valid, else array{success:false,...}.
 */
function extrachill_content_blocks_photo_contest_validate_photo( $file ) {
	if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || empty( $file['name'] ) ) {
		return array(
			'success' => false,
			'message' => 'A photo is required to enter.',
			'code'    => 'missing_photo',
		);
	}

	if ( isset( $file['error'] ) && 0 !== (int) $file['error'] ) {
		return array(
			'success' => false,
			'message' => 'The photo upload failed — please try again.',
			'code'    => 'upload_failed',
		);
	}

	if ( isset( $file['size'] ) && (int) $file['size'] > EXTRACHILL_PHOTO_CONTEST_MAX_UPLOAD_BYTES ) {
		return array(
			'success' => false,
			'message' => 'Photo is too large (10 MB max).',
			'code'    => 'photo_too_large',
		);
	}

	$filetype = wp_check_filetype( (string) $file['name'], extrachill_content_blocks_photo_contest_allowed_mimes() );
	if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
		return array(
			'success' => false,
			'message' => 'Please upload a JPG, PNG, GIF, WEBP, or HEIC photo.',
			'code'    => 'invalid_photo_type',
		);
	}

	return true;
}

/**
 * Transient-based submission rate limit per IP.
 *
 * @param string $ip Client IP ('' skips the check — e.g. CLI).
 * @return bool True when the submission may proceed.
 */
function extrachill_content_blocks_photo_contest_rate_limit_ok( $ip ) {
	$ip = trim( (string) $ip );
	if ( '' === $ip ) {
		return true;
	}

	$key   = 'ec_photo_contest_' . md5( $ip );
	$count = (int) get_transient( $key );

	if ( $count >= EXTRACHILL_PHOTO_CONTEST_RATE_LIMIT ) {
		return false;
	}

	set_transient( $key, $count + 1, EXTRACHILL_PHOTO_CONTEST_RATE_WINDOW );

	return true;
}

/**
 * Transient-based vote rate limit per IP.
 *
 * Parallel to the submission limiter above but with its own transient bucket
 * and a much higher ceiling, since legitimate voters cluster behind shared
 * venue wifi. Email dedup (one vote per email per entry) remains the primary
 * control; this only caps bulk abuse.
 *
 * @param string $ip Client IP ('' skips the check — e.g. CLI).
 * @return bool True when the vote may proceed.
 */
function extrachill_content_blocks_photo_contest_vote_rate_limit_ok( $ip ) {
	$ip = trim( (string) $ip );
	if ( '' === $ip ) {
		return true;
	}

	$key   = 'ec_photo_contest_vote_' . md5( $ip );
	$count = (int) get_transient( $key );

	if ( $count >= EXTRACHILL_PHOTO_CONTEST_VOTE_RATE_LIMIT ) {
		return false;
	}

	set_transient( $key, $count + 1, EXTRACHILL_PHOTO_CONTEST_RATE_WINDOW );

	return true;
}

/**
 * Process a photo-contest entry submission (business logic).
 *
 * Validation order: honeypot → rate limit → contest exists → entries window →
 * email → name → duplicate entry → photo validity → store. Entries go live
 * immediately; admins hide bad ones via the hide ability / CLI.
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
 *     @type string $ip           Client IP for rate limiting ('' to skip).
 *     @type int    $now          Timestamp override for window checks (tests).
 * }
 * @return array Result: success, message, code?, entry?
 */
function extrachill_content_blocks_photo_contest_submit_entry( array $args ) {
	$post_id      = (int) ( $args['post_id'] ?? 0 );
	$contest_id   = (string) ( $args['contest_id'] ?? '' );
	$email        = strtolower( trim( (string) ( $args['email'] ?? '' ) ) );
	$display_name = sanitize_text_field( (string) ( $args['display_name'] ?? '' ) );
	$instagram    = extrachill_content_blocks_photo_contest_sanitize_instagram( (string) ( $args['instagram'] ?? '' ) );
	$honeypot     = trim( (string) ( $args['honeypot'] ?? '' ) );
	$file         = $args['file'] ?? null;
	$ip           = (string) ( $args['ip'] ?? ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$now          = isset( $args['now'] ) ? (int) $args['now'] : null;

	// Honeypot: bots fill every field. Fail without detail.
	if ( '' !== $honeypot ) {
		return array(
			'success' => false,
			'message' => 'Submission could not be processed.',
			'code'    => 'invalid_submission',
		);
	}

	if ( ! extrachill_content_blocks_photo_contest_rate_limit_ok( $ip ) ) {
		return array(
			'success' => false,
			'message' => 'Too many submissions — please wait a few minutes and try again.',
			'code'    => 'rate_limited',
		);
	}

	$settings = extrachill_content_blocks_photo_contest_get_settings( $post_id, $contest_id );
	if ( null === $settings ) {
		return array(
			'success' => false,
			'message' => 'Contest not found.',
			'code'    => 'contest_not_found',
		);
	}

	if ( extrachill_content_blocks_photo_contest_is_closed( $settings['entries_close_at'], $now ) ) {
		return array(
			'success' => false,
			'message' => 'Entries are closed for this contest.',
			'code'    => 'entries_closed',
		);
	}

	if ( ! is_email( $email ) ) {
		return array(
			'success' => false,
			'message' => 'A valid email address is required.',
			'code'    => 'invalid_email',
		);
	}

	if ( '' === $display_name ) {
		return array(
			'success' => false,
			'message' => 'A display name is required.',
			'code'    => 'missing_name',
		);
	}

	// One entry per email per contest. Return the existing entry so the
	// entrant gets their share link back instead of a dead end.
	$existing_id = extrachill_content_blocks_photo_contest_find_entry_by_email( $post_id, $contest_id, $email );
	if ( $existing_id > 0 ) {
		return array(
			'success' => false,
			'message' => 'You already entered this contest — share your entry to get votes!',
			'code'    => 'already_entered',
			'entry'   => extrachill_content_blocks_photo_contest_entry_data( $existing_id ),
		);
	}

	$valid = extrachill_content_blocks_photo_contest_validate_photo( $file );
	if ( true !== $valid ) {
		return $valid;
	}

	$stored = extrachill_content_blocks_photo_contest_store_photo( $post_id, $file, $display_name );
	if ( is_array( $stored ) ) {
		return $stored;
	}

	$entry_id = (int) $stored;

	update_post_meta( $entry_id, EXTRACHILL_PHOTO_CONTEST_META_CONTEST, $contest_id );
	update_post_meta( $entry_id, EXTRACHILL_PHOTO_CONTEST_META_EMAIL, $email );
	update_post_meta( $entry_id, EXTRACHILL_PHOTO_CONTEST_META_NAME, $display_name );
	if ( '' !== $instagram ) {
		update_post_meta( $entry_id, EXTRACHILL_PHOTO_CONTEST_META_INSTAGRAM, $instagram );
	}

	// Newsletter sync (non-blocking), same bridge the vote path uses.
	if ( function_exists( 'extrachill_network_subscribe' ) ) {
		$subscription_result = extrachill_network_subscribe( $email, 'image_voting' );
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
		'success' => true,
		'message' => 'Entry submitted! Share your link to get votes.',
		'entry'   => extrachill_content_blocks_photo_contest_entry_data( $entry_id ),
	);
}

/**
 * Validate that an attachment is a visible entry of the given contest.
 *
 * @param int    $post_id    Contest post ID.
 * @param string $contest_id Contest block instance id.
 * @param int    $entry_id   Entry attachment ID.
 * @param bool   $allow_hidden Accept hidden entries.
 * @return bool True when the entry belongs to the contest.
 */
function extrachill_content_blocks_photo_contest_is_valid_entry( $post_id, $contest_id, $entry_id, $allow_hidden = false ) {
	$entry = get_post( $entry_id );
	if ( ! $entry || 'attachment' !== $entry->post_type || (int) $entry->post_parent !== (int) $post_id ) {
		return false;
	}

	if ( (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_CONTEST, true ) !== (string) $contest_id ) {
		return false;
	}

	if ( ! $allow_hidden && '' !== (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_HIDDEN, true ) ) {
		return false;
	}

	return true;
}

/**
 * Process a vote on a contest entry (business logic).
 *
 * Delegates to the shared image-voting backend with the entry-derived
 * instance key, after enforcing the voting window and entry visibility.
 * Dedup (one vote per email per entry) and newsletter subscribe happen
 * inside extrachill_content_blocks_process_image_vote().
 *
 * @param int      $post_id    Contest post ID.
 * @param string   $contest_id Contest block instance id.
 * @param int      $entry_id   Entry attachment ID.
 * @param string   $email      Voter email.
 * @param int|null $now        Timestamp override for window checks (tests).
 * @return array Result: success, message, code?, vote_count?
 */
function extrachill_content_blocks_photo_contest_process_vote( $post_id, $contest_id, $entry_id, $email, $now = null ) {
	$settings = extrachill_content_blocks_photo_contest_get_settings( $post_id, $contest_id );
	if ( null === $settings ) {
		return array(
			'success' => false,
			'message' => 'Contest not found.',
			'code'    => 'contest_not_found',
		);
	}

	if ( extrachill_content_blocks_photo_contest_is_closed( $settings['voting_closes_at'], $now ) ) {
		return array(
			'success' => false,
			'message' => 'Voting is closed for this contest.',
			'code'    => 'voting_closed',
		);
	}

	if ( ! extrachill_content_blocks_photo_contest_is_valid_entry( $post_id, $contest_id, $entry_id ) ) {
		return array(
			'success' => false,
			'message' => 'Entry not found.',
			'code'    => 'entry_not_found',
		);
	}

	return extrachill_content_blocks_process_image_vote(
		(int) $post_id,
		extrachill_content_blocks_photo_contest_entry_instance_id( $contest_id, $entry_id ),
		(string) $email
	);
}

/**
 * Hide or unhide an entry (admin moderation, one action).
 *
 * Hidden entries disappear from the public grid and can no longer receive
 * votes; their historical votes remain in the comments table.
 *
 * @param int  $entry_id Entry attachment ID.
 * @param bool $hidden   True to hide, false to restore.
 * @return array Result: success, message, code?
 */
function extrachill_content_blocks_photo_contest_set_entry_hidden( $entry_id, $hidden ) {
	$entry = get_post( $entry_id );
	if ( ! $entry || 'attachment' !== $entry->post_type
		|| '' === (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_CONTEST, true ) ) {
		return array(
			'success' => false,
			'message' => 'Entry not found.',
			'code'    => 'entry_not_found',
		);
	}

	if ( $hidden ) {
		update_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_HIDDEN, '1' );
	} else {
		delete_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_HIDDEN );
	}

	return array(
		'success' => true,
		'message' => $hidden ? 'Entry hidden.' : 'Entry restored.',
	);
}

/**
 * Compute Open Graph overrides for an entry deep link (?entry=<id>).
 *
 * Pure given an OG array and IDs; the wp_head-time wrappers below feed it the
 * request context. The entry photo becomes og:image so a shared deep link
 * unfurls with the entrant's costume, not the page's featured image.
 *
 * @param array $og_data  OG property => value map from extrachill-seo.
 * @param int   $post_id  Queried contest post ID.
 * @param int   $entry_id Entry attachment ID from ?entry=.
 * @return array Possibly-modified OG data.
 */
function extrachill_content_blocks_photo_contest_og_overrides( array $og_data, $post_id, $entry_id ) {
	$entry = get_post( $entry_id );
	if ( ! $entry || 'attachment' !== $entry->post_type || (int) $entry->post_parent !== (int) $post_id ) {
		return $og_data;
	}

	$contest_id = (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_CONTEST, true );
	if ( '' === $contest_id ) {
		return $og_data;
	}

	if ( '' !== (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_HIDDEN, true ) ) {
		return $og_data;
	}

	$image_url = function_exists( 'wp_get_attachment_image_url' )
		? (string) wp_get_attachment_image_url( $entry->ID, 'large' )
		: '';
	if ( '' === $image_url ) {
		$image_url = (string) wp_get_attachment_url( $entry->ID );
	}

	if ( '' === $image_url ) {
		return $og_data;
	}

	$display_name = (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_NAME, true );

	$og_data['og:image'] = $image_url;
	unset( $og_data['og:image:width'], $og_data['og:image:height'] );

	if ( '' !== $display_name ) {
		$og_data['og:image:alt'] = sprintf( "%s's contest entry", $display_name );
		$og_data['og:title']     = sprintf( 'Vote for %s!', $display_name );
	}

	$og_data['og:url'] = add_query_arg( 'entry', (int) $entry->ID, (string) get_permalink( $post_id ) );

	return $og_data;
}

/**
 * Swap in the entry photo on ?entry= deep links (wp_head-time OG filter).
 *
 * @param array $og_data OG data from extrachill-seo.
 * @return array
 */
function extrachill_content_blocks_photo_contest_filter_og_data( $og_data ) {
	if ( ! is_array( $og_data ) || ! is_singular() || empty( $_GET['entry'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $og_data;
	}

	$entry_id = absint( wp_unslash( $_GET['entry'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 0 === $entry_id ) {
		return $og_data;
	}

	return extrachill_content_blocks_photo_contest_og_overrides( $og_data, (int) get_queried_object_id(), $entry_id );
}
add_filter( 'extrachill_seo_open_graph_data', 'extrachill_content_blocks_photo_contest_filter_og_data', 20 );

/**
 * Fallback OG image for contest posts without a featured image.
 *
 * The extrachill-seo plugin fires this only when the post has no featured
 * image; the data filter above still wins when one exists.
 *
 * @param string   $image_url Current (empty) image URL.
 * @param \WP_Post $post      Queried post.
 * @return string
 */
function extrachill_content_blocks_photo_contest_filter_og_image_url( $image_url, $post ) {
	if ( '' !== (string) $image_url || empty( $_GET['entry'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $image_url;
	}

	$entry_id = absint( wp_unslash( $_GET['entry'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$entry    = get_post( $entry_id );
	if ( ! $entry || 'attachment' !== $entry->post_type || (int) $entry->post_parent !== (int) $post->ID ) {
		return $image_url;
	}

	if ( '' === (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_CONTEST, true )
		|| '' !== (string) get_post_meta( $entry->ID, EXTRACHILL_PHOTO_CONTEST_META_HIDDEN, true ) ) {
		return $image_url;
	}

	$url = function_exists( 'wp_get_attachment_image_url' )
		? (string) wp_get_attachment_image_url( $entry->ID, 'large' )
		: '';

	return '' !== $url ? $url : (string) wp_get_attachment_url( $entry->ID );
}
add_filter( 'extrachill_seo_singular_og_image_url', 'extrachill_content_blocks_photo_contest_filter_og_image_url', 10, 2 );
