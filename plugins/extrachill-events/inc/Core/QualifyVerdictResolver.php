<?php
/**
 * Qualify Verdict Resolver
 *
 * Pure decision tree that maps a fingerprint (assembled by
 * VenueQualificationAbilities) to a structured verdict. No I/O, no DB calls —
 * the resolver only inspects the fingerprint array and emits a verdict bundle.
 *
 * Resolution order (FIRST match wins — order matters):
 *  1. ticketmaster_precheck.disqualified                → COVERED_ELSEWHERE
 *  2. http_status == 0 OR timeout                       → UNREACHABLE
 *  3. http_status in {403, 429} OR cloudflare_challenge → BOT_BLOCKED
 *  4. http_status >= 500                                → UNREACHABLE
 *  5. extractor returned ≥ MIN events (non-vision)      → QUALIFIED_STRUCTURED
 *  6. only vision_flyer fired                           → QUALIFIED_FOR_FLYER
 *  7. JSON-LD/microdata present but 0 events extracted  → EXTRACTION_GAP
 *  8. platform detected but no extractor exists for it  → EXTRACTION_GAP
 *  9. reservation-only platform and no other platforms  → RESERVATION_ONLY
 * 10. default                                           → UNSUPPORTED_SOURCE
 *
 * @package ExtraChillEvents\Core
 * @since   0.20.0
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QualifyVerdictResolver {

	/**
	 * Reservation-only platforms. If the only thing we detect is one of
	 * these, the venue has no event listings to scrape.
	 *
	 * @var array<int,string>
	 */
	private const RESERVATION_PLATFORMS = array( 'opentable', 'resy', 'tock' );

	/**
	 * Platforms with no extractor in data-machine-events at all. Resolver
	 * uses this list when the fingerprint did not also include an explicit
	 * extractor_attempts entry showing the missing extractor — defensive
	 * fallback only.
	 *
	 * Kept here (and not in the detector) so platform detection stays purely
	 * about "what is this page running on" while the resolver owns "do we
	 * have code that handles it".
	 *
	 * @var array<int,string>
	 */
	private const PLATFORMS_WITHOUT_EXTRACTOR = array();

	/**
	 * Resolve a fingerprint to a verdict bundle.
	 *
	 * @param array $fingerprint Fingerprint payload assembled by the qualify
	 *                           ability. See class docblock for the schema
	 *                           (mirrors the issue prompt verbatim).
	 * @return array{
	 *     verdict: string,
	 *     events_url: string,
	 *     improvement_hint: string,
	 *     agent_guidance: string,
	 *     event_count: int
	 * }
	 */
	public static function resolve( array $fingerprint ): array {
		// ---- 1. Ticketmaster precheck wins immediately. ----
		$tm = $fingerprint['ticketmaster_precheck'] ?? array();
		if ( ! empty( $tm['disqualified'] ) ) {
			return self::bundle(
				QualifyVerdict::COVERED_ELSEWHERE,
				'',
				sprintf(
					'Ticketmaster/Live Nation property detected (%s). Already in the dedicated TM pipeline.',
					(string) ( $tm['matched'] ?? 'TM precheck' )
				),
				0
			);
		}

		$http_status = (int) ( $fingerprint['http_status'] ?? 0 );

		// ---- 2. Unreachable: no response at all. ----
		if ( 0 === $http_status || ! empty( $fingerprint['timeout'] ) ) {
			return self::bundle(
				QualifyVerdict::UNREACHABLE,
				'',
				'No HTTP response (DNS failure, timeout, or connection reset). Retry in 7 days.',
				0
			);
		}

		// ---- 3. Bot-blocked: 403/429 or Cloudflare challenge. ----
		$cloudflare = ! empty( $fingerprint['cloudflare_challenge'] );
		if ( 403 === $http_status || 429 === $http_status || $cloudflare ) {
			$detail = $cloudflare ? 'Cloudflare challenge detected' : sprintf( 'HTTP %d returned', $http_status );
			return self::bundle(
				QualifyVerdict::BOT_BLOCKED,
				'',
				$detail . '. Venue origin is blocking our scraper; revisit if proxy support lands.',
				0
			);
		}

		// ---- 4. 5xx is transient infrastructure, treat as unreachable. ----
		if ( $http_status >= 500 ) {
			return self::bundle(
				QualifyVerdict::UNREACHABLE,
				'',
				sprintf( 'HTTP %d returned — treating as transient infrastructure failure.', $http_status ),
				0
			);
		}

		$attempts = isset( $fingerprint['extractor_attempts'] ) && is_array( $fingerprint['extractor_attempts'] )
			? $fingerprint['extractor_attempts']
			: array();

		// ---- 5. Structured qualification — non-vision extractor returned enough events. ----
		// Pick the per-shape threshold: detail pages legitimately serve 1
		// Event by design; listing pages need ≥2 to guard against
		// stray-snippet false positives (issue #77).
		$shape      = (string) ( $fingerprint['structured_data']['event_page_shape'] ?? QualifyVerdict::EVENT_PAGE_SHAPE_UNKNOWN );
		$min_events = ( QualifyVerdict::EVENT_PAGE_SHAPE_DETAIL === $shape )
			? QualifyVerdict::MIN_EVENTS_FOR_DETAIL_PAGE
			: QualifyVerdict::MIN_EVENTS_FOR_STRUCTURED_QUALIFICATION;

		$best_structured = self::best_structured_attempt( $attempts, $min_events );
		if ( null !== $best_structured ) {
			$events = (int) ( $best_structured['events'] ?? 0 );
			$url    = (string) ( $best_structured['events_url'] ?? ( $fingerprint['final_url'] ?? '' ) );
			return self::bundle(
				QualifyVerdict::QUALIFIED_STRUCTURED,
				$url,
				sprintf(
					'%s extracted %d events at %s. Safe to wire as a universal_web_scraper flow.',
					(string) ( $best_structured['name'] ?? 'Extractor' ),
					$events,
					$url
				),
				$events
			);
		}

		// ---- 6. Vision fallback only — operator review required. ----
		$vision                  = self::vision_attempt( $attempts );
		$structured_attempts_ran = self::structured_attempts_ran( $attempts );
		if ( null !== $vision && 0 === self::sum_structured_events( $attempts ) ) {
			$url = (string) ( $vision['events_url'] ?? ( $fingerprint['final_url'] ?? '' ) );
			return self::bundle(
				QualifyVerdict::QUALIFIED_FOR_FLYER,
				$url,
				'Vision flyer detected; operator review recommended before wiring a flow. Consider using event_flyer handler instead of universal_web_scraper.',
				(int) ( $vision['events'] ?? 0 )
			);
		}

		$structured = isset( $fingerprint['structured_data'] ) && is_array( $fingerprint['structured_data'] )
			? $fingerprint['structured_data']
			: array();

		$platforms = isset( $fingerprint['platforms_detected'] ) && is_array( $fingerprint['platforms_detected'] )
			? array_values( array_unique( $fingerprint['platforms_detected'] ) )
			: array();

		// ---- 7. JSON-LD / microdata present but no events extracted. ----
		$has_structured_data = ! empty( $structured['jsonld_event_graph_present'] )
			|| (int) ( $structured['jsonld_events'] ?? 0 ) > 0
			|| (int) ( $structured['microdata_events'] ?? 0 ) > 0;
		if ( $has_structured_data ) {
			return self::bundle(
				QualifyVerdict::EXTRACTION_GAP,
				'',
				'Structured Event data found but extractor could not parse it. Likely fix: improve JsonLdExtractor or add a platform-specific handler.',
				0
			);
		}

		// ---- 8. Platform detected but no extractor exists for it. ----
		$missing_extractor_platforms = self::platforms_without_extractor( $platforms, $attempts );
		if ( ! empty( $missing_extractor_platforms ) ) {
			$platform = $missing_extractor_platforms[0];
			return self::bundle(
				QualifyVerdict::EXTRACTION_GAP,
				'',
				sprintf( '%s platform detected; no extractor exists. Likely fixable with a new %sExtractor.', $platform, self::platform_class_prefix( $platform ) ),
				0
			);
		}

		// ---- 9. Reservation-only platforms and nothing else. ----
		$non_reservation          = array_values(
			array_filter(
				$platforms,
				static function ( $p ) {
					return ! in_array( $p, self::RESERVATION_PLATFORMS, true );
				}
			)
		);
		$has_reservation_platform = (bool) array_intersect( $platforms, self::RESERVATION_PLATFORMS );
		if ( $has_reservation_platform && empty( $non_reservation ) ) {
			return self::bundle(
				QualifyVerdict::RESERVATION_ONLY,
				'',
				'Reservation-only platform. No events to scrape.',
				0
			);
		}

		// ---- 10. Default fallback. ----
		return self::bundle(
			QualifyVerdict::UNSUPPORTED_SOURCE,
			'',
			'Reachable source contains no structured event data or detected platform requiring a missing extractor.',
			0
		);
	}

	/**
	 * Build the verdict bundle, attaching the canonical agent guidance for
	 * the verdict so callers cannot drift.
	 */
	private static function bundle( string $verdict, string $events_url, string $improvement_hint, int $event_count ): array {
		return array(
			'verdict'          => $verdict,
			'events_url'       => $events_url,
			'improvement_hint' => $improvement_hint,
			'agent_guidance'   => QualifyVerdict::guidance_for( $verdict ),
			'event_count'      => $event_count < 0 ? 0 : $event_count,
		);
	}

	/**
	 * Find the extractor attempt with the highest event count that meets the
	 * structured-qualification threshold (non-vision pathway).
	 *
	 * @param array $attempts   Extractor attempts from the fingerprint.
	 * @param int   $min_events Minimum event count required to qualify.
	 *                          Defaults to the listing-page threshold for
	 *                          back-compat; callers select
	 *                          MIN_EVENTS_FOR_DETAIL_PAGE when the
	 *                          fingerprint indicates a detail-shaped page.
	 * @return array|null Best attempt, or null if none qualify.
	 */
	private static function best_structured_attempt( array $attempts, int $min_events = QualifyVerdict::MIN_EVENTS_FOR_STRUCTURED_QUALIFICATION ): ?array {
		$best = null;
		foreach ( $attempts as $attempt ) {
			if ( ! is_array( $attempt ) ) {
				continue;
			}
			if ( self::is_vision_attempt( $attempt ) ) {
				continue;
			}
			$events = (int) ( $attempt['events'] ?? 0 );
			if ( $events < $min_events ) {
				continue;
			}
			if ( null === $best || $events > (int) ( $best['events'] ?? 0 ) ) {
				$best = $attempt;
			}
		}
		return $best;
	}

	/**
	 * Find the vision_flyer attempt that returned a payload, if any.
	 */
	private static function vision_attempt( array $attempts ): ?array {
		foreach ( $attempts as $attempt ) {
			if ( ! is_array( $attempt ) ) {
				continue;
			}
			if ( self::is_vision_attempt( $attempt ) && (int) ( $attempt['events'] ?? 0 ) > 0 ) {
				return $attempt;
			}
		}
		return null;
	}

	/**
	 * Whether an extractor attempt represents the vision fallback rather
	 * than a structured extractor.
	 */
	private static function is_vision_attempt( array $attempt ): bool {
		$name = strtolower( (string) ( $attempt['name'] ?? '' ) );
		if ( false !== strpos( $name, 'vision' ) ) {
			return true;
		}
		$source = strtolower( (string) ( $attempt['source_type'] ?? '' ) );
		return 'vision_flyer' === $source || 'vision' === $source;
	}

	/**
	 * Whether ANY structured (non-vision) extractor actually ran.
	 */
	private static function structured_attempts_ran( array $attempts ): bool {
		foreach ( $attempts as $attempt ) {
			if ( ! is_array( $attempt ) ) {
				continue;
			}
			if ( self::is_vision_attempt( $attempt ) ) {
				continue;
			}
			if ( ! empty( $attempt['ran'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Total events returned by structured (non-vision) extractors.
	 */
	private static function sum_structured_events( array $attempts ): int {
		$sum = 0;
		foreach ( $attempts as $attempt ) {
			if ( ! is_array( $attempt ) ) {
				continue;
			}
			if ( self::is_vision_attempt( $attempt ) ) {
				continue;
			}
			$sum += max( 0, (int) ( $attempt['events'] ?? 0 ) );
		}
		return $sum;
	}

	/**
	 * Platforms detected for which the fingerprint shows no extractor exists.
	 *
	 * Two signals can mark an extractor as missing:
	 *  - explicit `exists: false` in extractor_attempts
	 *  - PLATFORMS_WITHOUT_EXTRACTOR (defensive fallback when the detector
	 *    knows about a platform that ships no extractor at all)
	 *
	 * @param array $platforms Detected platforms.
	 * @param array $attempts  Extractor attempts.
	 * @return array<int,string>
	 */
	private static function platforms_without_extractor( array $platforms, array $attempts ): array {
		$missing = array();
		foreach ( $platforms as $platform ) {
			$expected_prefix = self::platform_class_prefix( $platform );
			$has_extractor   = true;
			foreach ( $attempts as $attempt ) {
				if ( ! is_array( $attempt ) ) {
					continue;
				}
				$name = (string) ( $attempt['name'] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				if ( stripos( $name, $expected_prefix ) === 0 ) {
					$has_extractor = ! empty( $attempt['exists'] );
					break;
				}
			}
			if ( ! $has_extractor || in_array( $platform, self::PLATFORMS_WITHOUT_EXTRACTOR, true ) ) {
				$missing[] = $platform;
			}
		}
		return $missing;
	}

	/**
	 * Convert a platform slug (e.g. "bandzoogle", "wordpress_tribe") to the
	 * Pascal-cased extractor class prefix the resolver expects to find in
	 * the data-machine-events Extractors directory.
	 */
	private static function platform_class_prefix( string $platform ): string {
		$parts = explode( '_', $platform );
		$parts = array_map( 'ucfirst', $parts );
		return implode( '', $parts );
	}
}
