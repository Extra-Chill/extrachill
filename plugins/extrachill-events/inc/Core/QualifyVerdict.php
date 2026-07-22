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

	/** Page reachable, but no evidence identifies actionable extractor work. */
	public const UNSUPPORTED_SOURCE = 'unsupported_source';

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
	 * qualify v2 will issue a QUALIFIED_STRUCTURED verdict for a LISTING
	 * page (or a page whose shape is unknown). Single-event pages sneak
	 * through other tighter checks too easily on listing-shaped URLs.
	 */
	public const MIN_EVENTS_FOR_STRUCTURED_QUALIFICATION = 2;

	/**
	 * Minimum events threshold for a single-event DETAIL page. Detail-page
	 * URLs (e.g. `/schedule/<slug>`) emit exactly one Event by design, so
	 * the listing-page threshold misclassifies them as extraction_gap.
	 * Resolver picks this constant when `event_page_shape === "detail"`.
	 *
	 * @since 0.20.1
	 */
	public const MIN_EVENTS_FOR_DETAIL_PAGE = 1;

	// ---- Event page shape enum ----
	//
	// Populated by QualifyFingerprinter::detect_event_page_shape() and stored
	// on the fingerprint at `structured_data.event_page_shape`. The verdict
	// resolver reads this to decide which MIN_EVENTS_* threshold to apply.

	/** Single-event detail page (e.g. /schedule/<event-slug>). 1-event pages are legitimate here. */
	public const EVENT_PAGE_SHAPE_DETAIL = 'detail';

	/** Listing / calendar page. Single events are not enough; ≥2 required. */
	public const EVENT_PAGE_SHAPE_LISTING = 'listing';

	/** Shape detector did not find sufficient signal. Conservative default — treated as listing. */
	public const EVENT_PAGE_SHAPE_UNKNOWN = 'unknown';

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
			self::UNSUPPORTED_SOURCE,
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

	public const GUIDANCE_UNSUPPORTED_SOURCE = 'Page is reachable, but the fingerprint contains no structured event data or detected platform with a missing extractor. Do NOT treat this as extractor work or recommend wiring. Re-qualify only if the source changes or relevant platform support lands.';

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
			case self::UNSUPPORTED_SOURCE:
				return self::GUIDANCE_UNSUPPORTED_SOURCE;
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
	 * Whether a verdict can change through source recovery, source updates, or
	 * new/improved extractor support.
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
			array( self::EXTRACTION_GAP, self::UNSUPPORTED_SOURCE, self::BOT_BLOCKED, self::UNREACHABLE ),
			true
		);
	}

	// ---- Pause confirmation rules ----
	//
	// Per-verdict rules that govern when `unqualifiable-flows --auto-pause`
	// will actually pause a flow. The platform is designed to run unattended
	// for weeks at a time, so a 30-second transient outage during an audit
	// window must not pause a healthy venue.
	//
	// Shape: ['verdicts' => N, 'hours' => H] — the last N verdicts for the
	// URL must all match the candidate verdict AND the oldest of those N
	// rows must be at least H hours old. `null` means the verdict is never
	// auto-paused (qualified states).
	//
	// QUALIFIED_STRUCTURED / QUALIFIED_FOR_FLYER: never auto-paused.
	// EXTRACTION_GAP: 2 verdicts over ≥48h — extractor gaps don't fix
	// themselves between consecutive audit runs.
	// UNSUPPORTED_SOURCE: 2 verdicts over ≥48h — require confirmation before
	// pausing a source that may have temporarily stopped exposing events.
	// BOT_BLOCKED / UNREACHABLE: 3 verdicts over ≥7 days — Cloudflare rules
	// flip; DNS/timeout/5xx is often transient.
	// RESERVATION_ONLY / COVERED_ELSEWHERE: single verdict — these are
	// permanent disqualifications by design.

	/**
	 * Per-verdict pause-confirmation rules.
	 *
	 * @var array<string, ?array{verdicts:int,hours:int}>
	 */
	public const CONFIRMATION_RULES = array(
		self::QUALIFIED_STRUCTURED => null,
		self::QUALIFIED_FOR_FLYER  => null,
		self::EXTRACTION_GAP       => array(
			'verdicts' => 2,
			'hours'    => 48,
		),
		self::UNSUPPORTED_SOURCE   => array(
			'verdicts' => 2,
			'hours'    => 48,
		),
		self::BOT_BLOCKED          => array(
			'verdicts' => 3,
			'hours'    => 168,
		),
		self::UNREACHABLE          => array(
			'verdicts' => 3,
			'hours'    => 168,
		),
		self::RESERVATION_ONLY     => array(
			'verdicts' => 1,
			'hours'    => 0,
		),
		self::COVERED_ELSEWHERE    => array(
			'verdicts' => 1,
			'hours'    => 0,
		),
	);

	/**
	 * Pause-confirmation rule for a verdict.
	 *
	 * Returns the tuple ['verdicts' => N, 'hours' => H] from
	 * CONFIRMATION_RULES, or null when the verdict is never auto-paused.
	 *
	 * @param string $verdict One of the verdict constants.
	 * @return array{verdicts:int,hours:int}|null
	 */
	public static function confirmation_for( string $verdict ): ?array {
		return self::CONFIRMATION_RULES[ $verdict ] ?? null;
	}

	// ---- Recheck intervals ----
	//
	// Per-verdict cadence for re-running qualify against a paused flow's
	// source_url. When a recheck job fires, the handler calls qualify; if
	// the new verdict is QUALIFIED_STRUCTURED the flow is auto-resumed,
	// otherwise the recheck is rescheduled at the next interval (or escalated
	// to the digest after 6 consecutive failures).
	//
	// EXTRACTION_GAP: 14d — extractor coverage usually ships in batches.
	// UNSUPPORTED_SOURCE: 30d — sources may change or gain platform support.
	// BOT_BLOCKED:    7d  — Cloudflare rules can flip.
	// UNREACHABLE:    3d  — DNS/timeout/5xx is often short-lived.
	// QUALIFIED_FOR_FLYER: 21d — operator-review state; long cadence.
	// RESERVATION_ONLY / COVERED_ELSEWHERE: null — permanent, no recheck.

	/**
	 * Per-verdict recheck cadence in seconds.
	 *
	 * @var array<string, ?int>
	 */
	public const RECHECK_INTERVALS = array(
		self::EXTRACTION_GAP      => 14 * DAY_IN_SECONDS,
		self::UNSUPPORTED_SOURCE  => 30 * DAY_IN_SECONDS,
		self::BOT_BLOCKED         => 7 * DAY_IN_SECONDS,
		self::UNREACHABLE         => 3 * DAY_IN_SECONDS,
		self::QUALIFIED_FOR_FLYER => 21 * DAY_IN_SECONDS,
		self::RESERVATION_ONLY    => null,
		self::COVERED_ELSEWHERE   => null,
	);

	/**
	 * Recheck interval for a verdict, in seconds.
	 *
	 * Returns null when the verdict is permanently disqualifying (no
	 * recheck is ever scheduled). Filterable via
	 * `dme_qualify_recheck_interval` so operators can tune cadence
	 * without a code change.
	 *
	 * @param string $verdict One of the verdict constants.
	 * @return int|null Interval in seconds, or null for "never recheck".
	 */
	public static function recheck_interval_for( string $verdict ): ?int {
		$default = self::RECHECK_INTERVALS[ $verdict ] ?? null;
		/**
		 * Filter the per-verdict recheck cadence.
		 *
		 * @param int|null $interval Default interval in seconds (null = never).
		 * @param string   $verdict  The verdict the interval applies to.
		 */
		return apply_filters( 'dme_qualify_recheck_interval', $default, $verdict );
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
