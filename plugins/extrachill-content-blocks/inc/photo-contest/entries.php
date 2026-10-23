<?php
/**
 * Photo Contest domain: entries (query, entry data, ranking) and contest
 * settings.
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
 * Domain errors are WP_Error objects carrying an HTTP status in
 * data['status'] (400 validation, 403 closed window / honeypot, 404 not
 * found, 409 conflict) so abilities and REST adapters can pass them through
 * unchanged.
 *
 * @package ExtraChillContentBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Entry meta keys (on the attachment).
define( 'EXTRACHILL_PHOTO_CONTEST_META_CONTEST', '_photo_contest_id' );
define( 'EXTRACHILL_PHOTO_CONTEST_META_EMAIL', '_photo_contest_entrant_email' );
define( 'EXTRACHILL_PHOTO_CONTEST_META_NAME', '_photo_contest_display_name' );
define( 'EXTRACHILL_PHOTO_CONTEST_META_INSTAGRAM', '_photo_contest_instagram' );
define( 'EXTRACHILL_PHOTO_CONTEST_META_HIDDEN', '_photo_contest_hidden' );
define( 'EXTRACHILL_PHOTO_CONTEST_META_HASH', '_photo_contest_photo_hash' );

/**
 * Maximum accepted photo upload size in bytes (10 MB).
 */
define( 'EXTRACHILL_PHOTO_CONTEST_MAX_UPLOAD_BYTES', 10 * 1024 * 1024 );

/**
 * Build a status-coded domain WP_Error.
 *
 * Every photo-contest domain failure flows through here so the HTTP status
 * always rides along in data['status'] and the core Abilities REST runner
 * (and the extrachill/v1 adapter routes) return proper 4xx codes instead
 * of 500.
 *
 * @param string $code    Machine error code.
 * @param string $message Human-readable message.
 * @param int    $status  HTTP status (400, 403, 404, 409).
 * @param array  $extra   Additional data merged into the error data.
 * @return \WP_Error
 */
function extrachill_content_blocks_photo_contest_error( $code, $message, $status, array $extra = array() ) {
	return new \WP_Error(
		(string) $code,
		(string) $message,
		array_merge( array( 'status' => (int) $status ), $extra )
	);
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
 * The opening/closing dates and finalists options are block attributes. Server-side
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

	$settings = array(
		'entries_open_at'  => (string) ( $attrs['entriesOpenAt'] ?? '' ),
		'voting_opens_at'  => (string) ( $attrs['votingOpensAt'] ?? '' ),
		'entries_close_at' => (string) ( $attrs['entriesCloseAt'] ?? '' ),
		'voting_closes_at' => (string) ( $attrs['votingClosesAt'] ?? '' ),
		'show_finalists'   => (bool) ( $attrs['showFinalists'] ?? false ),
		'finalist_count'   => max( 1, (int) ( $attrs['finalistCount'] ?? 10 ) ),
	);

	// Separate keys avoid read-modify-write races between phases/contests.
	foreach ( array( 'entries' => 'entries_close_at', 'voting' => 'voting_closes_at' ) as $phase => $key ) {
		$manual = get_post_meta( $post_id, extrachill_content_blocks_photo_contest_phase_meta_key( $contest_id, $phase ), true );
		$settings[ $phase . '_manually_closed' ] = '' !== (string) $manual;
		$scheduled = extrachill_content_blocks_photo_contest_time( $settings[ $key ] );
		if ( '' !== (string) $manual && ( null === $scheduled || (int) $manual < $scheduled ) ) {
			$settings[ $key ] = gmdate( 'c', (int) $manual );
		}
	}

	return $settings;
}

/** Contest-scoped manual close key on the contest post. */
function extrachill_content_blocks_photo_contest_phase_meta_key( $contest_id, $phase ) {
	return '_photo_contest_close_' . $phase . '_' . hash( 'sha256', (string) $contest_id );
}

/** Cache-safe public live phase data (never embedded in the page config). */
function extrachill_content_blocks_photo_contest_phase_state( array $settings ) {
	return array(
		'entries_closed' => extrachill_content_blocks_photo_contest_is_closed( $settings['entries_close_at'] ),
		'voting_closed' => extrachill_content_blocks_photo_contest_is_closed( $settings['voting_closes_at'] ),
		'entries_manually_closed' => $settings['entries_manually_closed'],
		'voting_manually_closed' => $settings['voting_manually_closed'],
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
	$timestamp = extrachill_content_blocks_photo_contest_time( $close_at );
	return null !== $timestamp && ( null === $now ? time() : (int) $now ) >= $timestamp;
}

/**
 * Whether the opening time has arrived. Unset/invalid dates open immediately.
 *
 * @param string   $open_at Site-local datetime attribute.
 * @param int|null $now     Unix timestamp override for tests.
 * @return bool
 */
function extrachill_content_blocks_photo_contest_has_opened( $open_at, $now = null ) {
	$timestamp = extrachill_content_blocks_photo_contest_time( $open_at );
	return null === $timestamp || ( null === $now ? time() : (int) $now ) >= $timestamp;
}

/**
 * Parse a window date in the site timezone; null means unset or invalid.
 *
 * @param string $close_at Site-local datetime attribute.
 * @return int|null
 */
function extrachill_content_blocks_photo_contest_time( $close_at ) {
	$close_at = trim( (string) $close_at );
	if ( '' === $close_at ) {
		return null;
	}

	if ( function_exists( 'wp_timezone' ) ) {
		try {
			$dt        = new DateTimeImmutable( $close_at, wp_timezone() );
			$timestamp = $dt->getTimestamp();
		} catch ( Exception $e ) {
			return null;
		}
	} else {
		$timestamp = strtotime( $close_at );
		if ( false === $timestamp ) {
			return null;
		}
	}

	return $timestamp;
}

/**
 * Format a window date for visitors in the site timezone.
 *
 * @param string $date Site-local datetime attribute.
 * @return string
 */
function extrachill_content_blocks_photo_contest_format_time( $date ) {
	$timestamp = extrachill_content_blocks_photo_contest_time( $date );
	if ( null === $timestamp ) {
		return '';
	}
	return function_exists( 'wp_date' )
		? wp_date( 'F j, Y g:i a T', $timestamp )
		: date( 'F j, Y g:i a T', $timestamp );
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
 * Content hash of an uploaded photo, for exact-duplicate rejection.
 *
 * Hashes the uploaded temp file before it is stored. Returns '' when the
 * file cannot be read (dedup is then skipped rather than blocking a
 * legitimate entry).
 *
 * @param mixed $file One $_FILES-shaped entry.
 * @return string md5 content hash, or '' when unavailable.
 */
function extrachill_content_blocks_photo_contest_photo_hash( $file ) {
	$tmp_name = is_array( $file ) ? (string) ( $file['tmp_name'] ?? '' ) : '';
	if ( '' === $tmp_name || ! is_readable( $tmp_name ) || ! is_file( $tmp_name ) ) {
		return '';
	}

	$hash = md5_file( $tmp_name );

	return false === $hash ? '' : (string) $hash;
}

/**
 * Find an existing entry with the same photo content hash within a contest.
 *
 * Includes hidden entries so a hidden photo cannot simply be resubmitted.
 *
 * @param int    $post_id    Contest post ID.
 * @param string $contest_id Contest block instance id.
 * @param string $hash       Photo content hash.
 * @return int Entry attachment ID, or 0 when none.
 */
function extrachill_content_blocks_photo_contest_find_entry_by_hash( $post_id, $contest_id, $hash ) {
	$hash = (string) $hash;
	if ( '' === $hash ) {
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
					'key'   => EXTRACHILL_PHOTO_CONTEST_META_HASH,
					'value' => $hash,
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
 * Validate that an attachment is a visible entry of the given contest.
 *
 * @param int    $post_id      Contest post ID.
 * @param string $contest_id   Contest block instance id.
 * @param int    $entry_id     Entry attachment ID.
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
