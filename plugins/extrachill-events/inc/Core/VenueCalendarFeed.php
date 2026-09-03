<?php
/**
 * Venue calendar feed binding.
 *
 * Binds one venue term to one public calendar feed URL (ICS/iCal, including
 * Google Calendar public addresses) so the venue's events can be imported
 * automatically instead of retyped by hand.
 *
 * **Storage lives here, not in `Venue_Taxonomy::$meta_fields`.** That array is
 * the venue *profile contract*: every entry is auto-rendered as a wp-admin term
 * form input, saved from `$_POST`, emitted in public venue profile output,
 * normalized by `VenueService::normalize_venue_data()`, and — critically —
 * copied loser-to-winner on venue merge by `VenueMergeHelper::fill_empty_meta()`.
 *
 * Feed state must not inherit through a merge. Merging duplicate venue terms
 * would otherwise hand the winner a dead feed's error state, or a feed URL its
 * owner never authorized. Profile fields (address, phone) are owner-declared
 * facts and are mergeable by nature; feed status is machine sync state with a
 * different lifecycle and a different authority. `_ec_priority_venue` sets the
 * same precedent — venue term meta deliberately kept out of the profile map.
 *
 * This class owns storage and validation only. Authorization is the caller's
 * job (see VenueCalendarFeedAbilities); fetching and event upsert belong to the
 * sync runner.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueCalendarFeed {

	public const META_URL         = '_venue_calendar_feed_url';
	public const META_STATUS      = '_venue_calendar_feed_status';
	public const META_LAST_SYNCED = '_venue_calendar_last_synced';
	public const META_LAST_ERROR  = '_venue_calendar_last_error';

	public const STATUS_ACTIVE = 'active';
	public const STATUS_PAUSED = 'paused';
	public const STATUS_ERROR  = 'error';

	/**
	 * Source name recorded on every event created from a feed.
	 *
	 * Paired with the ICS UID as source_id, this yields a source identity
	 * namespace distinct from every other ingestion path. That separation is
	 * what makes it structurally impossible for a feed sync to adopt,
	 * overwrite, or unpublish a scraped or publicly-submitted event.
	 */
	public const SOURCE_NAME = 'venue_calendar_feed';

	/** Maximum bytes read from a feed response. */
	public const MAX_FEED_BYTES = 5242880;

	/** Consecutive failures tolerated before the binding is parked in error. */
	public const MAX_CONSECUTIVE_FAILURES = 5;

	/** Return every supported binding status. */
	public static function statuses(): array {
		return array( self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_ERROR );
	}

	/**
	 * Read the current binding for one venue.
	 *
	 * @param int $venue_term_id Venue term ID.
	 * @return array Binding state; `bound` is false when no URL is stored.
	 */
	public static function get( int $venue_term_id ): array {
		$url = (string) get_term_meta( $venue_term_id, self::META_URL, true );

		if ( '' === $url ) {
			return array(
				'venue_term_id' => $venue_term_id,
				'bound'         => false,
				'feed_url'      => '',
				'status'        => '',
				'last_synced'   => '',
				'last_error'    => '',
			);
		}

		return array(
			'venue_term_id' => $venue_term_id,
			'bound'         => true,
			'feed_url'      => $url,
			'status'        => (string) get_term_meta( $venue_term_id, self::META_STATUS, true ),
			'last_synced'   => (string) get_term_meta( $venue_term_id, self::META_LAST_SYNCED, true ),
			'last_error'    => (string) get_term_meta( $venue_term_id, self::META_LAST_ERROR, true ),
		);
	}

	/**
	 * Bind a validated feed URL to a venue.
	 *
	 * Callers must authorize first. The URL is expected to have already passed
	 * `validate_url()`; this method re-normalizes defensively but does not
	 * fetch.
	 *
	 * @param int    $venue_term_id Venue term ID.
	 * @param string $url           Feed URL.
	 * @return array|\WP_Error Binding state, or a validation error.
	 */
	public static function bind( int $venue_term_id, string $url ) {
		$normalized = self::normalize_url( $url );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		update_term_meta( $venue_term_id, self::META_URL, $normalized );
		update_term_meta( $venue_term_id, self::META_STATUS, self::STATUS_ACTIVE );
		delete_term_meta( $venue_term_id, self::META_LAST_ERROR );

		return self::get( $venue_term_id );
	}

	/**
	 * Remove a venue's feed binding.
	 *
	 * Only the binding is removed. Events already imported are left untouched —
	 * unbinding is not a retraction of published history.
	 *
	 * @param int $venue_term_id Venue term ID.
	 * @return array Cleared binding state.
	 */
	public static function unbind( int $venue_term_id ): array {
		delete_term_meta( $venue_term_id, self::META_URL );
		delete_term_meta( $venue_term_id, self::META_STATUS );
		delete_term_meta( $venue_term_id, self::META_LAST_SYNCED );
		delete_term_meta( $venue_term_id, self::META_LAST_ERROR );

		return self::get( $venue_term_id );
	}

	/**
	 * Record a successful sync.
	 *
	 * @param int $venue_term_id Venue term ID.
	 */
	public static function record_success( int $venue_term_id ): void {
		update_term_meta( $venue_term_id, self::META_STATUS, self::STATUS_ACTIVE );
		update_term_meta( $venue_term_id, self::META_LAST_SYNCED, gmdate( 'Y-m-d H:i:s' ) );
		delete_term_meta( $venue_term_id, self::META_LAST_ERROR );
	}

	/**
	 * Record a failed sync, parking the binding once failures accumulate.
	 *
	 * A dead feed must not be polled forever. After MAX_CONSECUTIVE_FAILURES the
	 * binding flips to `error` and the scheduled runner skips it until an
	 * operator retries from the console.
	 *
	 * @param int    $venue_term_id Venue term ID.
	 * @param string $message       Operator-facing error message.
	 * @param int    $failure_count Consecutive failure count including this one.
	 */
	public static function record_failure( int $venue_term_id, string $message, int $failure_count = 1 ): void {
		update_term_meta( $venue_term_id, self::META_LAST_ERROR, sanitize_text_field( $message ) );

		if ( $failure_count >= self::MAX_CONSECUTIVE_FAILURES ) {
			update_term_meta( $venue_term_id, self::META_STATUS, self::STATUS_ERROR );
		}
	}

	/**
	 * Validate and normalize a submitted feed URL.
	 *
	 * Rejects anything that is not an external HTTP(S) resource. The URL is
	 * user-supplied and will be fetched server-side on a schedule, so private,
	 * loopback, and link-local destinations are refused to avoid turning the
	 * scheduled sync into an SSRF primitive. Redirect hops must be re-validated
	 * by the fetcher with `is_safe_host()`.
	 *
	 * @param string $url Raw submitted URL.
	 * @return string|\WP_Error Normalized URL, or a specific validation error.
	 */
	public static function normalize_url( string $url ) {
		$url = trim( $url );

		if ( '' === $url ) {
			return new \WP_Error(
				'venue_calendar_feed_url_empty',
				__( 'Enter a calendar feed address.', 'extrachill-events' ),
				array( 'status' => 400 )
			);
		}

		// webcal:// is the subscription scheme Google and Apple hand out; it is
		// https:// over the wire.
		if ( 0 === stripos( $url, 'webcal://' ) ) {
			$url = 'https://' . substr( $url, 9 );
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return new \WP_Error(
				'venue_calendar_feed_url_malformed',
				__( 'That does not look like a valid calendar address.', 'extrachill-events' ),
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new \WP_Error(
				'venue_calendar_feed_url_scheme',
				__( 'Calendar addresses must start with https:// or webcal://.', 'extrachill-events' ),
				array( 'status' => 400 )
			);
		}

		if ( ! self::is_safe_host( $parts['host'] ) ) {
			return new \WP_Error(
				'venue_calendar_feed_url_host_blocked',
				__( 'That calendar address points to a private network location.', 'extrachill-events' ),
				array( 'status' => 400 )
			);
		}

		$sanitized = esc_url_raw( $url, array( 'http', 'https' ) );

		if ( '' === $sanitized ) {
			return new \WP_Error(
				'venue_calendar_feed_url_malformed',
				__( 'That does not look like a valid calendar address.', 'extrachill-events' ),
				array( 'status' => 400 )
			);
		}

		return $sanitized;
	}

	/**
	 * Whether a host is an acceptable fetch destination.
	 *
	 * Must be re-checked on every redirect hop, not only at bind time — a
	 * public URL can redirect to a private one.
	 *
	 * @param string $host Hostname or IP literal.
	 * @return bool
	 */
	public static function is_safe_host( string $host ): bool {
		$host = trim( $host, '[]' );

		if ( '' === $host ) {
			return false;
		}

		if ( 'localhost' === strtolower( $host ) ) {
			return false;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return (bool) filter_var(
				$host,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		}

		// Hostnames are resolved by the fetcher; a name that resolves into a
		// private range is caught there on the redirect re-check.
		return (bool) preg_match( '/^[A-Za-z0-9]([A-Za-z0-9\-\.]*[A-Za-z0-9])?$/', $host );
	}
}
