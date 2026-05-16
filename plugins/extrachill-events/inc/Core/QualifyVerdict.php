<?php
/**
 * Qualify Verdict — taxonomy + URL canonicalizer.
 *
 * Phase 1 of qualify v2: this file currently exposes only the URL canonicalizer
 * that both the persistence layer and CLI commands depend on. The full verdict
 * taxonomy constants and the resolver decision tree are added in the next
 * commit (feat(core): QualifyVerdict taxonomy + verdict resolver decision
 * tree).
 *
 * Keeping the canonicalizer co-located with the taxonomy ensures every consumer
 * (qualify execution, persistence, requalify-pending lookups, audit commands)
 * uses identical URL normalization rules.
 *
 * @package ExtraChillEvents\Core
 * @since   0.20.0
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QualifyVerdict {

	// ---- Verdict taxonomy ----
	//
	// These constants replace the simple `qualified: bool` returned by qualify
	// v1. Each value represents a specific outcome the resolver maps a
	// fingerprint to. The CLI and persistence layer treat verdicts as opaque
	// strings — only the resolver and (read-only) consumers care about which
	// constant maps to which behavior.

	/** ≥ MIN_EVENTS_FOR_STRUCTURED_QUALIFICATION extracted via a non-vision extractor. */
	public const QUALIFIED_STRUCTURED = 'qualified_structured';

	/** Only the vision_flyer fallback fired; operator review required before wiring a flow. */
	public const QUALIFIED_FOR_FLYER = 'qualified_for_flyer';

	/** Page reachable, structured data present, but our extractors do not cover it. Fixable. */
	public const EXTRACTION_GAP = 'extraction_gap';

	/** Reservation-only platform (OpenTable/Resy/Tock). Permanently disqualify. */
	public const RESERVATION_ONLY = 'reservation_only';

	/** HTTP 403/429 or Cloudflare challenge. Revisit if proxy support lands. */
	public const BOT_BLOCKED = 'bot_blocked';

	/** DNS / timeout / 5xx. Operational — retry queue then disqualify. */
	public const UNREACHABLE = 'unreachable';

	/** Ticketmaster / Live Nation venue. Already in the dedicated TM pipeline. */
	public const COVERED_ELSEWHERE = 'covered_elsewhere';

	/**
	 * Minimum number of events a non-vision extractor must return before
	 * qualify v2 will issue a QUALIFIED_STRUCTURED verdict. Single-event pages
	 * sneak through other tighter checks too easily.
	 */
	public const MIN_EVENTS_FOR_STRUCTURED_QUALIFICATION = 2;

	/**
	 * The full enum of valid verdict values. Useful for input validation in
	 * CLI commands (e.g. --verdict=<one of these>).
	 *
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array(
			self::QUALIFIED_STRUCTURED,
			self::QUALIFIED_FOR_FLYER,
			self::EXTRACTION_GAP,
			self::RESERVATION_ONLY,
			self::BOT_BLOCKED,
			self::UNREACHABLE,
			self::COVERED_ELSEWHERE,
		);
	}

	/**
	 * Whether a verdict counts as "qualified" from the operator's perspective.
	 *
	 * QUALIFIED_FOR_FLYER is included even though it requires operator review,
	 * because it represents a real signal that this venue has events to scrape
	 * (just via a different handler than universal_web_scraper).
	 *
	 * @param string $verdict One of the constants.
	 * @return bool
	 */
	public static function is_qualified( string $verdict ): bool {
		return in_array(
			$verdict,
			array( self::QUALIFIED_STRUCTURED, self::QUALIFIED_FOR_FLYER ),
			true
		);
	}

	// ---- Agent guidance ----
	//
	// These are verdict-specific strings aimed at chat agents that read
	// verdicts from the persisted log and decide what to do next. They are
	// intentionally explicit and prescriptive — agents key off the wording.
	// Refine wording in this file only; downstream consumers must not
	// hand-roll their own guidance strings.

	public const GUIDANCE_QUALIFIED_STRUCTURED = 'Safe to promote to a live flow. Recommend the operator wire it via wp extrachill venues add.';

	public const GUIDANCE_QUALIFIED_FOR_FLYER = 'Vision detected a likely flyer image, but no structured events were extracted. Fetch the source_url directly. Verify the image is actually an event flyer (not a logo, decoration, or stale ad). If it is a genuine flyer, recommend the operator wire it with the event_flyer handler, NOT universal_web_scraper. If it is noise, recommend pausing and filing the URL for re-qualification when extractor coverage improves.';

	public const GUIDANCE_EXTRACTION_GAP = 'Page is reachable and contains structured data, but our extractors did not parse it. Fetch the URL via WebFetch and inspect the HTML. If you can identify a predictable pattern (JSON-LD shape, platform-specific markup, etc.), file an issue against data-machine-events suggesting the extractor fix. DO NOT recommend wiring this venue until the extractor lands.';

	public const GUIDANCE_BOT_BLOCKED = 'HTTP 403/429 — venue origin is blocking our scraper. No code-level fix is possible without proxy support. Park the URL; revisit when proxy support lands. Do NOT recommend wiring.';

	public const GUIDANCE_RESERVATION_ONLY = 'Venue uses OpenTable/Resy/Tock for reservations only and does not publish event listings. Permanently disqualified. Do NOT recommend wiring. Do NOT file an extractor issue.';

	public const GUIDANCE_UNREACHABLE = 'Site DNS/timeout/5xx. Could be transient. Requeue for re-qualification in 7 days. If still unreachable then, permanently disqualify.';

	public const GUIDANCE_COVERED_ELSEWHERE = 'Venue is a Ticketmaster/Live Nation property. Already covered by the dedicated Ticketmaster flow. Do NOT recommend wiring.';

	/**
	 * Verdict → agent guidance string. Returns an empty string when the
	 * verdict is unrecognized (defensive; never expected at runtime).
	 *
	 * @param string $verdict One of the verdict constants.
	 * @return string Guidance text aimed at chat agents reading the verdict.
	 */
	public static function guidance_for( string $verdict ): string {
		switch ( $verdict ) {
			case self::QUALIFIED_STRUCTURED:
				return self::GUIDANCE_QUALIFIED_STRUCTURED;
			case self::QUALIFIED_FOR_FLYER:
				return self::GUIDANCE_QUALIFIED_FOR_FLYER;
			case self::EXTRACTION_GAP:
				return self::GUIDANCE_EXTRACTION_GAP;
			case self::BOT_BLOCKED:
				return self::GUIDANCE_BOT_BLOCKED;
			case self::RESERVATION_ONLY:
				return self::GUIDANCE_RESERVATION_ONLY;
			case self::UNREACHABLE:
				return self::GUIDANCE_UNREACHABLE;
			case self::COVERED_ELSEWHERE:
				return self::GUIDANCE_COVERED_ELSEWHERE;
			default:
				return '';
		}
	}

	/**
	 * Whether a verdict is fixable by shipping a new/improved extractor.
	 *
	 * Used by requalify-pending to decide which verdicts to consider
	 * candidates for automatic re-qualification when a new extractor lands.
	 *
	 * @param string $verdict One of the constants.
	 * @return bool
	 */
	public static function is_requalifiable( string $verdict ): bool {
		return in_array(
			$verdict,
			array( self::EXTRACTION_GAP, self::BOT_BLOCKED, self::UNREACHABLE ),
			true
		);
	}

	/**
	 * Canonicalize a URL for verdict lookup / dedup.
	 *
	 * Rules:
	 *  - lowercase host
	 *  - drop trailing slash on path (but preserve a single "/" for bare hosts)
	 *  - drop fragment
	 *  - keep query string ONLY when it materially identifies the events
	 *    page (e.g. ?view=calendar / ?page=events). Default: drop query.
	 *
	 * Both the persistence layer and the requalify-pending command call this
	 * helper so the same input URL always produces the same canonical form,
	 * regardless of trailing-slash / casing / fragment variations.
	 *
	 * @param string $url Raw URL.
	 * @return string Canonical URL, or '' if the input is unparseable.
	 */
	public static function canonicalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		// Add scheme if missing — wp_parse_url chokes on bare hostnames.
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] ?? 'https' );
		$host   = strtolower( $parts['host'] );
		$path   = $parts['path'] ?? '';
		$query  = $parts['query'] ?? '';

		// Drop trailing slash on path. A bare "/" collapses to '' so
		// "https://example.com" and "https://example.com/" canonicalize identically.
		if ( '/' === $path ) {
			$path = '';
		} elseif ( '' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		// Keep query keys that materially identify the events page; drop all
		// others (tracking params, session ids, etc.).
		$identifying_params = self::identifying_query_params();
		$kept_query         = '';
		if ( '' !== $query ) {
			parse_str( $query, $parsed );
			$keep = array();
			foreach ( $parsed as $k => $v ) {
				if ( in_array( strtolower( (string) $k ), $identifying_params, true ) ) {
					$keep[ $k ] = $v;
				}
			}
			if ( ! empty( $keep ) ) {
				ksort( $keep );
				$kept_query = http_build_query( $keep );
			}
		}

		$canonical = $scheme . '://' . $host . $path;
		if ( '' !== $kept_query ) {
			$canonical .= '?' . $kept_query;
		}

		return $canonical;
	}

	/**
	 * Query parameters worth keeping during canonicalization.
	 *
	 * These are parameters that actually identify which page on the venue's
	 * site is being looked at. Tracking junk (utm_*, fbclid, etc.) is dropped.
	 *
	 * Lowercase comparison only. Extend via the
	 * `extrachill_events_qualify_identifying_query_params` filter.
	 *
	 * @return array<int,string>
	 */
	private static function identifying_query_params(): array {
		$defaults = array(
			'view',
			'page',
			'page_id',
			'p',
			'cat',
			'tag',
			'taxonomy',
			'term',
			'eventdisplay',
			'event_display',
			'tribe_event_display',
		);

		/**
		 * Filter the list of query parameters preserved during canonicalization.
		 *
		 * @param array $params Default list of identifying parameter names (lowercase).
		 */
		$filtered = apply_filters( 'extrachill_events_qualify_identifying_query_params', $defaults );

		return is_array( $filtered ) ? array_map( 'strtolower', $filtered ) : $defaults;
	}

	/**
	 * SHA1 hash of the canonical URL, used as the lookup index in the
	 * verdicts table. Returns '' when the URL cannot be canonicalized.
	 *
	 * @param string $url Raw URL.
	 * @return string 40-char hex hash, or empty string.
	 */
	public static function url_hash( string $url ): string {
		$canonical = self::canonicalize_url( $url );
		return '' === $canonical ? '' : sha1( $canonical );
	}
}
