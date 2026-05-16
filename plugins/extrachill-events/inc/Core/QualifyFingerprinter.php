<?php
/**
 * Qualify Fingerprinter
 *
 * Assembles the fingerprint that QualifyVerdictResolver consumes. This is the
 * I/O layer of qualify v2:
 *  - fetches the homepage HTML once (for platform detection / structured_data)
 *  - records HTTP status, final URL after redirects, redirect chain
 *  - runs PlatformDetector against the HTML
 *  - probes the Tribe REST endpoint when the homepage looks like WP
 *  - walks every candidate URL through data-machine-events/test-event-scraper
 *    and records the result as an extractor_attempts entry
 *  - records ticketmaster_precheck output
 *
 * The fingerprint is then passed to the resolver. Persistence (writing to the
 * verdicts table) happens in VenueQualificationAbilities after resolution so
 * the agent_guidance + verdict can be stored alongside the fingerprint.
 *
 * @package ExtraChillEvents\Core
 * @since   0.20.0
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QualifyFingerprinter {

	/**
	 * Extractor classes that ship with data-machine-events. Used to mark
	 * `extractor_attempts[].exists` for fingerprint consumers (resolver,
	 * agents reading the verdict log). Kept here so qualify v2 has a
	 * single source of truth without reflecting into the DM-events plugin.
	 *
	 * When data-machine-events ships a new extractor, add it here. The
	 * detector's platform list and this list should stay in sync.
	 *
	 * @var array<int,string>
	 */
	private const KNOWN_EXTRACTORS = array(
		'AegAxsExtractor',
		'BandzoogleExtractor',
		'CraftpeakExtractor',
		'DoStuffExtractor',
		'DuskFmExtractor',
		'ElfsightEventsExtractor',
		'EmbeddedCalendarExtractor',
		'EventbriteExtractor',
		'FirebaseExtractor',
		'FreshtixExtractor',
		'GenericHtmlEventsExtractor',
		'GigwellExtractor',
		'GoDaddyExtractor',
		'IcsExtractor',
		'JsonLdExtractor',
		'MicrodataExtractor',
		'MusicItemExtractor',
		'NocodeflowExtractor',
		'OpenDateExtractor',
		'PrekindleExtractor',
		'RedRocksExtractor',
		'RhpEventsExtractor',
		'ShowareExtractor',
		'ShowtimeExtractor',
		'SofarSoundsExtractor',
		'SpotHopperExtractor',
		'SquareOnlineExtractor',
		'SquarespaceExtractor',
		'TimelyExtractor',
		'VenuePilotExtractor',
		'VisionExtractor',
		'WebflowExtractor',
		'WeeblyExtractor',
		'WixEventsExtractor',
		'WordPressExtractor',
	);

	/**
	 * Map a platform slug (from PlatformDetector) to the extractor class
	 * we expect to handle it. Used to populate extractor_attempts with the
	 * `exists` flag the resolver checks.
	 *
	 * @var array<string,string>
	 */
	private const PLATFORM_TO_EXTRACTOR = array(
		'bandzoogle'          => 'BandzoogleExtractor',
		'squarespace'         => 'SquarespaceExtractor',
		'webflow'             => 'WebflowExtractor',
		'wix'                 => 'WixEventsExtractor',
		'eventbrite'          => 'EventbriteExtractor',
		'wordpress_tribe'     => 'WordPressExtractor',
		'wordpress_generic'   => 'GenericHtmlEventsExtractor',
		// reservation-only platforms have no extractor by design
		'opentable'           => '',
		'resy'                => '',
		'tock'                => '',
		'dice_fm'             => '',
		'ticketmaster_widget' => '',
	);

	/**
	 * Fetch the homepage HTML, follow redirects, and capture metadata used
	 * by the rest of the fingerprint pipeline.
	 *
	 * @param string $url Homepage URL.
	 * @return array{
	 *     http_status:int,
	 *     final_url:string,
	 *     redirects:array,
	 *     timeout:bool,
	 *     cloudflare_challenge:bool,
	 *     html:string
	 * }
	 */
	public static function fetch_homepage( string $url ): array {
		$result = array(
			'http_status'          => 0,
			'final_url'            => $url,
			'redirects'            => array(),
			'timeout'              => false,
			'cloudflare_challenge' => false,
			'html'                 => '',
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 12,
				'redirection' => 5,
				'user-agent'  => 'Mozilla/5.0 (compatible; ExtraChillBot/1.0; +https://extrachill.com)',
				'headers'     => array( 'Accept' => 'text/html' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$code = $response->get_error_code();
			if ( false !== stripos( (string) $code, 'timeout' )
				|| false !== stripos( (string) $response->get_error_message(), 'timed out' ) ) {
				$result['timeout'] = true;
			}
			return $result;
		}

		$result['http_status'] = (int) wp_remote_retrieve_response_code( $response );

		// Final URL after redirect chain (when WP exposes it).
		if ( isset( $response['http_response'] ) && is_object( $response['http_response'] )
			&& method_exists( $response['http_response'], 'get_response_object' ) ) {
			$resp = $response['http_response']->get_response_object();
			if ( isset( $resp->url ) && is_string( $resp->url ) && '' !== $resp->url ) {
				$result['final_url'] = $resp->url;
				if ( $result['final_url'] !== $url ) {
					$result['redirects'][] = $url . ' → ' . $result['final_url'];
				}
			}
		}

		$body            = (string) wp_remote_retrieve_body( $response );
		$result['html']  = $body;
		$server          = (string) wp_remote_retrieve_header( $response, 'server' );
		$cf_ray          = (string) wp_remote_retrieve_header( $response, 'cf-ray' );
		$lc_body         = strtolower( $body );

		// Cloudflare challenge detection. Multiple signals — any one is
		// enough to mark bot_blocked downstream.
		if (
			( $result['http_status'] >= 400 && $result['http_status'] < 500
				&& ( false !== strpos( $lc_body, 'cf-browser-verification' )
					|| false !== strpos( $lc_body, 'attention required! | cloudflare' ) ) )
			|| false !== strpos( $lc_body, 'cf_chl_opt' )
			|| ( '' !== $cf_ray && $result['http_status'] === 403 )
		) {
			$result['cloudflare_challenge'] = true;
		}

		// Server header hint — not authoritative, just helpful for tests.
		unset( $server );

		return $result;
	}

	/**
	 * Assemble the platforms_detected list. Combines the HTML detector with
	 * a Tribe REST probe (since `wordpress_tribe` cannot be detected from
	 * HTML alone).
	 *
	 * @param string $html   Homepage HTML.
	 * @param string $origin Scheme + host (no trailing slash).
	 * @return array<int,string>
	 */
	public static function build_platforms_list( string $html, string $origin ): array {
		$platforms = PlatformDetector::detect_platforms( $html );

		// Promote wordpress_generic → wordpress_tribe when the API responds.
		// Only probe if the HTML at least looks like WP.
		$looks_like_wp = in_array( 'wordpress_generic', $platforms, true )
			|| false !== stripos( $html, '/wp-json/' )
			|| false !== stripos( $html, 'wp-content/' );

		if ( $looks_like_wp && PlatformDetector::probe_tribe_api( $origin ) ) {
			$platforms = array_values(
				array_filter(
					$platforms,
					static function ( $p ) {
						return 'wordpress_generic' !== $p;
					}
				)
			);
			$platforms[] = 'wordpress_tribe';
		}

		return array_values( array_unique( $platforms ) );
	}

	/**
	 * Build an extractor_attempts entry by running a single URL through the
	 * data-machine-events/test-event-scraper ability and inspecting the
	 * result.
	 *
	 * @param string $url URL to test.
	 * @return array Attempt record.
	 */
	public static function run_extractor_attempt( string $url ): array {
		$attempt = array(
			'url'        => $url,
			'events_url' => $url,
			'events'     => 0,
			'ran'        => false,
			'name'       => '',
			'exists'     => true,
			'matched'    => false,
			'source_type' => '',
		);

		$ability = function_exists( 'wp_get_ability' )
			? wp_get_ability( 'data-machine-events/test-event-scraper' )
			: null;

		if ( ! $ability ) {
			$attempt['name']   = 'TestEventScraper';
			$attempt['exists'] = false;
			return $attempt;
		}

		$result = $ability->execute( array( 'target_url' => $url ) );

		if ( is_wp_error( $result ) ) {
			$attempt['ran']  = true;
			$attempt['name'] = 'TestEventScraper';
			return $attempt;
		}

		$extraction       = is_array( $result['extraction_info'] ?? null ) ? $result['extraction_info'] : array();
		$extraction_method = (string) ( $extraction['extraction_method'] ?? '' );
		$source_type      = (string) ( $extraction['source_type'] ?? '' );
		$payload_type     = (string) ( $extraction['payload_type'] ?? '' );

		$attempt['ran']         = true;
		$attempt['matched']     = ! empty( $result['success'] );
		$attempt['source_type'] = $source_type ?: $payload_type;
		$attempt['name']        = self::extractor_class_from_method( $extraction_method, $payload_type, $source_type );

		// Count events for the extractor_attempts entry.
		if ( ! empty( $result['success'] ) ) {
			$event_data       = is_array( $result['event_data'] ?? null ) ? $result['event_data'] : array();
			$attempt['events'] = self::count_events( $event_data, $payload_type );
		}

		return $attempt;
	}

	/**
	 * Whether a given extractor class ships with data-machine-events.
	 *
	 * Public so platform-derived attempts (added by build_attempts_for_platforms)
	 * can use the same source of truth as scraper-derived attempts.
	 *
	 * @param string $class Extractor class basename (no namespace).
	 * @return bool
	 */
	public static function extractor_exists( string $class ): bool {
		return in_array( $class, self::KNOWN_EXTRACTORS, true );
	}

	/**
	 * For each detected platform that we have not already exercised via a
	 * scraper attempt, add a synthetic attempt record marking whether the
	 * matching extractor exists. Lets the resolver flag missing-extractor
	 * platforms via the same code path that handles real attempts.
	 *
	 * @param array $platforms Platforms detected for the page.
	 * @param array $attempts  Existing attempts (from run_extractor_attempt).
	 * @return array Merged attempts list.
	 */
	public static function add_platform_existence_attempts( array $platforms, array $attempts ): array {
		$existing_classes = array();
		foreach ( $attempts as $a ) {
			if ( is_array( $a ) && ! empty( $a['name'] ) ) {
				$existing_classes[] = (string) $a['name'];
			}
		}

		foreach ( $platforms as $platform ) {
			$expected_class = self::PLATFORM_TO_EXTRACTOR[ $platform ] ?? '';
			if ( '' === $expected_class ) {
				// Reservation-only / widget-only platforms intentionally have
				// no extractor — skip synthesizing an attempt.
				continue;
			}
			if ( in_array( $expected_class, $existing_classes, true ) ) {
				continue;
			}
			$attempts[] = array(
				'name'    => $expected_class,
				'exists'  => self::extractor_exists( $expected_class ),
				'matched' => false,
				'ran'     => false,
				'events'  => 0,
			);
		}

		return $attempts;
	}

	/**
	 * URL path prefixes considered event-listing roots. Used by the shape
	 * detector to flag listing-shaped URLs. Kept in sync with the
	 * VenueQualificationAbilities::EVENT_PATHS list, minus the leading slash
	 * — but reproduced here so QualifyFingerprinter has no cross-class
	 * dependency on the abilities layer.
	 *
	 * @var array<int,string>
	 */
	private const LISTING_PATH_SEGMENTS = array(
		'events',
		'calendar',
		'shows',
		'schedule',
		'upcoming',
		'live-music',
		'whats-on',
		'listings',
		'concerts',
		'music',
		'lineup',
		'happenings',
		'event',
		'gigs',
	);

	/**
	 * URL path PREFIXES that, when followed by a slug-shaped segment, signal
	 * a single-event detail page (e.g. `/schedule/<slug>`, `/events/<slug>`).
	 * Used by the shape detector's URL regex.
	 *
	 * @var array<int,string>
	 */
	private const DETAIL_PATH_PREFIXES = array(
		'event',
		'events',
		'show',
		'shows',
		'schedule',
		'gig',
		'gigs',
		'calendar',
	);

	/**
	 * Detect the shape of an event page (detail vs listing vs unknown).
	 *
	 * Decision logic — conservative on declaring "detail" because the
	 * detail-page threshold relaxation (1 event qualifies) creates risk of
	 * false positives on noisy listing pages. Resolution order matters:
	 *
	 *  1. Multiple Events in JSON-LD              → LISTING (unambiguous)
	 *  2. ItemList / CollectionPage / listing
	 *     container markers in HTML body
	 *     (.event-list, .event-listing, repeated
	 *     data-event-id, etc.)                    → LISTING
	 *  3. URL path is a known listing root
	 *     (`/`, `/events`, `/calendar`, ...)      → LISTING
	 *  4. URL matches detail pattern
	 *     (`/<prefix>/<slug-with-dash>`) AND
	 *     exactly 1 Event present                 → DETAIL
	 *  5. Exactly 1 Event present, no listing
	 *     markers (covered above), and URL path
	 *     is not a listing root                   → DETAIL
	 *  6. Otherwise                                → UNKNOWN
	 *
	 * The two DETAIL branches mirror the issue's "ANY of" heuristic spec:
	 *  (4) is the URL-driven heuristic, (5) is the JSON-LD count + body
	 *  shape heuristic. Listing-marker / multi-Event checks (1-2) and the
	 *  listing-root check (3) all short-circuit before either can fire, so
	 *  detail is never declared when any explicit listing signal is present.
	 *
	 * Resolver maps UNKNOWN to the listing threshold (≥2 events) so the
	 * default is conservative.
	 *
	 * @param string $url             Final URL (after redirects) being qualified.
	 * @param array  $structured_data Output of PlatformDetector::detect_structured_data().
	 * @param string $html            Homepage HTML body (may be empty).
	 * @return string One of QualifyVerdict::EVENT_PAGE_SHAPE_* constants.
	 *
	 * @since 0.20.1
	 */
	public static function detect_event_page_shape( string $url, array $structured_data, string $html ): string {
		$jsonld_events    = (int) ( $structured_data['jsonld_events'] ?? 0 );
		$microdata_events = (int) ( $structured_data['microdata_events'] ?? 0 );
		$total_events     = $jsonld_events + $microdata_events;

		// 1. Multiple events anywhere → unambiguous listing.
		if ( $total_events >= 2 ) {
			return QualifyVerdict::EVENT_PAGE_SHAPE_LISTING;
		}

		// 2. ItemList / CollectionPage / listing-container markers → listing.
		if ( '' !== $html && self::has_listing_markers( $html ) ) {
			return QualifyVerdict::EVENT_PAGE_SHAPE_LISTING;
		}

		// 3. URL-based listing detection: trailing path segment matches a
		// known listing root, or the URL has no path at all (bare domain).
		$path = self::url_path( $url );
		if ( self::path_is_listing_root( $path ) ) {
			return QualifyVerdict::EVENT_PAGE_SHAPE_LISTING;
		}

		// 4. URL-driven detail heuristic: /<listing-prefix>/<slug>.
		if ( self::path_matches_detail_pattern( $path ) && 1 === $total_events ) {
			return QualifyVerdict::EVENT_PAGE_SHAPE_DETAIL;
		}

		// 5. JSON-LD-count detail heuristic: exactly 1 Event, no listing
		// markers (handled above), path is not a listing root (handled
		// above). Catches detail pages whose URLs don't match the slug regex
		// (e.g. CMSes that serve `/p/event-name` or query-string-driven).
		if ( 1 === $total_events && '' !== $path ) {
			return QualifyVerdict::EVENT_PAGE_SHAPE_DETAIL;
		}

		return QualifyVerdict::EVENT_PAGE_SHAPE_UNKNOWN;
	}

	/**
	 * Whether the HTML body contains markers indicating a listing/calendar
	 * page (multiple event tiles, ItemList schema, etc.).
	 *
	 * Conservative: any single marker is enough to force a listing verdict
	 * and prevent the detail-page threshold from applying.
	 */
	private static function has_listing_markers( string $html ): bool {
		// ItemList / CollectionPage JSON-LD or microdata. We check both
		// schema.org-flavored URLs and bare type tokens.
		if ( preg_match(
			'#"@type"\s*:\s*"(?:ItemList|CollectionPage|EventSeries)"#i',
			$html
		) ) {
			return true;
		}
		if ( preg_match(
			'#itemtype\s*=\s*["\']https?://schema\.org/(?:ItemList|CollectionPage|EventSeries)["\']#i',
			$html
		) ) {
			return true;
		}

		// Common listing-container class / data-attribute markers.
		if ( preg_match(
			'#class\s*=\s*["\'][^"\']*\b(?:event-list|event-listing|events-list|events-listing|event-grid|events-grid|show-list|shows-list|schedule-list)\b#i',
			$html
		) ) {
			return true;
		}

		// Repeated data-event-id attributes (>= 2) → listing of events.
		if ( preg_match_all( '#data-event-id\s*=#i', $html, $m ) && count( $m[0] ) >= 2 ) {
			return true;
		}

		return false;
	}

	/**
	 * Extract the lowercase path component of a URL, with trailing slash
	 * stripped (except for bare "/"). Returns '' when the URL is unparseable.
	 */
	private static function url_path( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$path = isset( $parts['path'] ) ? strtolower( (string) $parts['path'] ) : '';
		if ( '' === $path ) {
			return '';
		}
		if ( '/' === $path ) {
			return '/';
		}
		return rtrim( $path, '/' );
	}

	/**
	 * Whether a URL path is a listing root (bare host, or trailing segment
	 * matches a known listing slug like /events, /calendar).
	 */
	private static function path_is_listing_root( string $path ): bool {
		if ( '' === $path || '/' === $path ) {
			return true;
		}
		// Last segment is one of the known listing slugs?
		$segments = array_values( array_filter( explode( '/', $path ) ) );
		if ( empty( $segments ) ) {
			return true;
		}
		$last = end( $segments );
		return in_array( $last, self::LISTING_PATH_SEGMENTS, true );
	}

	/**
	 * Whether a URL path matches the single-event detail pattern:
	 * `/<listing-prefix>/<slug>` where the slug contains at least one dash
	 * AND at least one alphabetic character. The dash + alpha guard prevents
	 * matching numeric ids (`/events/12345`) and short tokens.
	 */
	private static function path_matches_detail_pattern( string $path ): bool {
		if ( '' === $path || '/' === $path ) {
			return false;
		}
		$prefixes = implode( '|', array_map( 'preg_quote', self::DETAIL_PATH_PREFIXES ) );
		$pattern  = '#^/(?:' . $prefixes . ')/([a-z0-9][a-z0-9-]*[a-z0-9])(?:/|$)#i';
		if ( ! preg_match( $pattern, $path, $m ) ) {
			return false;
		}
		$slug = $m[1];
		// Slug must contain at least one dash AND at least one letter — keeps
		// us from matching numeric ids or short tokens.
		return ( false !== strpos( $slug, '-' ) ) && preg_match( '#[a-z]#i', $slug );
	}

	/**
	 * Heuristic: derive the extractor class name from extraction metadata
	 * the test-event-scraper ability returns. Falls back to a generic label
	 * when nothing useful is available.
	 *
	 * The mapping is intentionally generous — fingerprints are diagnostic,
	 * not authoritative.
	 */
	private static function extractor_class_from_method( string $method, string $payload_type, string $source_type ): string {
		$candidates = array( $method, $source_type, $payload_type );
		foreach ( $candidates as $candidate ) {
			$candidate = strtolower( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			if ( false !== strpos( $candidate, 'jsonld' ) || false !== strpos( $candidate, 'json-ld' ) ) {
				return 'JsonLdExtractor';
			}
			if ( false !== strpos( $candidate, 'microdata' ) ) {
				return 'MicrodataExtractor';
			}
			if ( false !== strpos( $candidate, 'vision' ) || 'vision_flyer' === $candidate ) {
				return 'VisionExtractor';
			}
			if ( false !== strpos( $candidate, 'squarespace' ) ) {
				return 'SquarespaceExtractor';
			}
			if ( false !== strpos( $candidate, 'bandzoogle' ) ) {
				return 'BandzoogleExtractor';
			}
			if ( false !== strpos( $candidate, 'webflow' ) ) {
				return 'WebflowExtractor';
			}
			if ( false !== strpos( $candidate, 'wix' ) ) {
				return 'WixEventsExtractor';
			}
			if ( false !== strpos( $candidate, 'tribe' ) || false !== strpos( $candidate, 'wordpress' ) ) {
				return 'WordPressExtractor';
			}
			if ( false !== strpos( $candidate, 'ics' ) ) {
				return 'IcsExtractor';
			}
		}
		return 'TestEventScraper';
	}

	/**
	 * Count events returned by a test-event-scraper result.
	 */
	private static function count_events( array $event_data, string $payload_type ): int {
		if ( 'vision_flyer' === $payload_type ) {
			return 1; // vision contributes one candidate
		}
		if ( isset( $event_data['items'] ) && is_array( $event_data['items'] ) ) {
			return count( $event_data['items'] );
		}
		if ( ! empty( $event_data['title'] ) || ! empty( $event_data['raw_html'] ) ) {
			return 1;
		}
		return 0;
	}
}
