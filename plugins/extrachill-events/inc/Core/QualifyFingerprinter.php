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
