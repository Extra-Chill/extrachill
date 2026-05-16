<?php
/**
 * Venue Qualification Abilities
 *
 * Qualifies a venue website by finding its events/calendar page and testing
 * whether the universal web scraper can extract events from it. Uses the
 * data-machine-events/test-event-scraper ability for real scraper validation.
 *
 * Strategy:
 * 1. Try the given URL directly with the scraper
 * 2. Crawl the homepage for event page links and test each
 * 3. Probe common URL patterns (/events, /calendar, /shows) and test each
 * 4. Check for WordPress Tribe Events API
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\PlatformDetector;
use ExtraChillEvents\Core\QualifyFingerprinter;
use ExtraChillEvents\Core\QualifyVerdict;
use ExtraChillEvents\Core\QualifyVerdictResolver;
use ExtraChillEvents\Core\QualifyVerdictsTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueQualificationAbilities {

	/**
	 * Common URL paths where venues list their events.
	 */
	private const EVENT_PATHS = array(
		'/events',
		'/calendar',
		'/shows',
		'/schedule',
		'/upcoming',
		'/live-music',
		'/whats-on',
		'/listings',
		'/concerts',
		'/music',
		'/lineup',
		'/happenings',
		'/event',
		'/gigs',
	);

	/**
	 * Patterns indicating Ticketmaster / Live Nation venue.
	 *
	 * If ANY of these are found in the homepage HTML (or a redirect lands on
	 * one of these domains), the venue is disqualified because it is already
	 * covered by the dedicated Ticketmaster pipeline flow.
	 *
	 * NOTE: AEG/AXS venues are NOT disqualified — we have a dedicated
	 * AegAxsExtractor that scrapes their structured JSON feeds.
	 */
	private const TICKETMASTER_PATTERNS = array(
		'ticketmaster.com',
		'livenation.com',
	);

	/**
	 * Keywords that indicate an events page link in navigation.
	 */
	private const LINK_KEYWORDS = array(
		'events',
		'calendar',
		'shows',
		'schedule',
		'upcoming',
		'live music',
		'what\'s on',
		'whats on',
		'concerts',
		'lineup',
		'happenings',
		'tonight',
		'this week',
		'tickets',
	);

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'extrachill/qualify-venue',
				array(
					'label'               => __( 'Qualify Venue', 'extrachill-events' ),
					'description'         => __( 'Test if a venue website has scrapable events by running the actual universal web scraper against it.', 'extrachill-events' ),
					'category'            => 'extrachill-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'url' ),
						'properties' => array(
							'url'  => array(
								'type'        => 'string',
								'description' => 'Venue website URL (homepage). The ability will find the events page and test the scraper.',
							),
							'name' => array(
								'type'        => 'string',
								'description' => 'Venue name (optional, for display).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'qualified'        => array( 'type' => 'boolean' ),
							'verdict'          => array( 'type' => 'string' ),
							'events_url'       => array( 'type' => 'string' ),
							'method'           => array( 'type' => 'string' ),
							'event_count'      => array( 'type' => 'integer' ),
							'fingerprint'      => array( 'type' => 'object' ),
							'improvement_hint' => array( 'type' => 'string' ),
							'agent_guidance'   => array( 'type' => 'string' ),
							'warnings'         => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( $this, 'executeQualifyVenue' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute venue qualification — qualify v2.
	 *
	 * Returns a structured verdict bundle (verdict + fingerprint +
	 * agent_guidance + improvement_hint + event_count) and persists every
	 * verdict to the <prefix>_dme_qualify_verdicts table. Callers that need
	 * only the legacy boolean can read `$result['qualified']`.
	 *
	 * @param array $input Qualification parameters.
	 * @return array Results.
	 */
	public function executeQualifyVenue( array $input ): array|\WP_Error {
		$url  = esc_url_raw( $input['url'] ?? '' );
		$name = sanitize_text_field( $input['name'] ?? '' );

		if ( empty( $url ) ) {
			return new \WP_Error( 'missing_url', 'URL is required.', array( 'status' => 400 ) );
		}

		$url = rtrim( $url, '/' );
		if ( ! preg_match( '#^https?://#', $url ) ) {
			$url = 'https://' . $url;
		}

		$started_at = microtime( true );

		$parsed = wp_parse_url( $url );
		$origin = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' );

		// Build the fingerprint skeleton up front so a TM/short-circuit
		// verdict still persists with full diagnostics.
		$fingerprint = array(
			'http_status'           => 0,
			'final_url'             => $url,
			'redirects'             => array(),
			'timeout'               => false,
			'cloudflare_challenge'  => false,
			'platforms_detected'    => array(),
			'structured_data'       => array(),
			'extractor_attempts'    => array(),
			'ticketmaster_precheck' => array( 'disqualified' => false, 'matched' => '' ),
			'urls_tested'           => array(),
			'elapsed_ms'            => 0,
		);

		// ---- Ticketmaster precheck (short-circuits everything else). ----
		$tm_check = $this->checkTicketmasterVenue( $url );
		if ( $tm_check['disqualified'] ) {
			$fingerprint['ticketmaster_precheck'] = array(
				'disqualified' => true,
				'matched'      => (string) ( $tm_check['matched'] ?? '' ),
			);
			$fingerprint['elapsed_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );
			return $this->finalize_and_persist( $url, $name, 'ticketmaster_precheck', $fingerprint );
		}

		// ---- Fetch the homepage once and capture status/redirects/HTML. ----
		$home = QualifyFingerprinter::fetch_homepage( $url );
		$fingerprint['http_status']          = $home['http_status'];
		$fingerprint['final_url']            = $home['final_url'];
		$fingerprint['redirects']            = $home['redirects'];
		$fingerprint['timeout']              = $home['timeout'];
		$fingerprint['cloudflare_challenge'] = $home['cloudflare_challenge'];

		// ---- Platform + structured data detection (off the homepage HTML). ----
		$html = $home['html'];
		if ( '' !== $html ) {
			$fingerprint['structured_data']    = PlatformDetector::detect_structured_data( $html );
			$fingerprint['platforms_detected'] = QualifyFingerprinter::build_platforms_list( $html, $origin );
		}

		// ---- Stop early if we already have enough signal for a verdict. ----
		//
		// For unreachable / bot-blocked / 5xx, the test-event-scraper round
		// trip is pointless — we already know what the verdict will be and
		// further requests would only burn time and tickle rate-limits.
		$short_circuit_method = '';
		if ( 0 === $fingerprint['http_status'] || $fingerprint['timeout']
			|| 403 === $fingerprint['http_status'] || 429 === $fingerprint['http_status']
			|| $fingerprint['cloudflare_challenge']
			|| $fingerprint['http_status'] >= 500 ) {
			$short_circuit_method = 'fingerprint_short_circuit';
		}

		if ( '' === $short_circuit_method ) {
			// ---- Walk candidate URLs through the scraper. ----
			$urls_tested = array();
			$ran_any     = false;

			$urls_to_try = $this->buildCandidateUrls( $url, $origin, $html );

			foreach ( $urls_to_try as $candidate ) {
				if ( in_array( $candidate['url'], $urls_tested, true ) ) {
					continue;
				}
				$urls_tested[] = $candidate['url'];

				$attempt = QualifyFingerprinter::run_extractor_attempt( $candidate['url'] );
				if ( ! empty( $attempt['ran'] ) ) {
					$ran_any = true;
				}
				$attempt['method']    = $candidate['method'];
				$attempt['link_text'] = $candidate['link_text'] ?? '';

				$fingerprint['extractor_attempts'][] = $attempt;

				// As soon as one extractor returns enough events, stop hammering.
				if ( ( $attempt['events'] ?? 0 ) >= QualifyVerdict::MIN_EVENTS_FOR_STRUCTURED_QUALIFICATION
					&& ! $this->looks_like_vision_attempt( $attempt ) ) {
					break;
				}
			}

			$fingerprint['urls_tested'] = $urls_tested;
			unset( $ran_any );
		}

		// ---- Synthesize platform-existence attempts so the resolver can flag
		//      missing-extractor verdicts via the same code path that handles
		//      real extractor attempts.
		$fingerprint['extractor_attempts'] = QualifyFingerprinter::add_platform_existence_attempts(
			$fingerprint['platforms_detected'],
			$fingerprint['extractor_attempts']
		);

		$fingerprint['elapsed_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );

		$method = '' !== $short_circuit_method ? $short_circuit_method : $this->resolve_method_from_attempts( $fingerprint['extractor_attempts'] );
		return $this->finalize_and_persist( $url, $name, $method, $fingerprint );
	}

	/**
	 * Build the ordered list of candidate URLs to test against the scraper.
	 *
	 * Order: direct URL → nav-link discovery → path probes → Tribe REST API.
	 * Each entry carries a method label that ends up on its extractor_attempt
	 * row so consumers can tell HOW the URL was discovered.
	 *
	 * @param string $url    Original homepage URL.
	 * @param string $origin Scheme + host (no trailing slash).
	 * @param string $html   Homepage HTML for nav-link discovery (may be empty).
	 * @return array<int,array{url:string,method:string,link_text?:string}>
	 */
	private function buildCandidateUrls( string $url, string $origin, string $html ): array {
		$candidates = array(
			array( 'url' => $url, 'method' => 'direct' ),
		);

		if ( '' !== $html ) {
			$links = $this->findEventLinks( $html, $origin );
			foreach ( $links as $link ) {
				$candidates[] = array(
					'url'       => $link['url'],
					'method'    => 'nav_link',
					'link_text' => $link['text'] ?? '',
				);
			}
		}

		foreach ( self::EVENT_PATHS as $path ) {
			$candidates[] = array( 'url' => $origin . $path, 'method' => 'path_probe' );
		}

		$candidates[] = array( 'url' => $origin . '/wp-json/tribe/events/v1/events', 'method' => 'tribe_api' );

		return $candidates;
	}

	/**
	 * Whether an attempt record represents the vision_flyer pathway.
	 */
	private function looks_like_vision_attempt( array $attempt ): bool {
		$name        = strtolower( (string) ( $attempt['name'] ?? '' ) );
		$source_type = strtolower( (string) ( $attempt['source_type'] ?? '' ) );
		return false !== strpos( $name, 'vision' ) || 'vision_flyer' === $source_type;
	}

	/**
	 * Pick the most informative `method` label across the attempts list,
	 * so consumers (CLI, callers) get a sensible "how did we discover the
	 * events URL" string.
	 */
	private function resolve_method_from_attempts( array $attempts ): string {
		foreach ( $attempts as $a ) {
			if ( ( $a['events'] ?? 0 ) >= QualifyVerdict::MIN_EVENTS_FOR_STRUCTURED_QUALIFICATION
				&& ! $this->looks_like_vision_attempt( $a ) ) {
				return (string) ( $a['method'] ?? 'direct' );
			}
		}
		foreach ( $attempts as $a ) {
			if ( $this->looks_like_vision_attempt( $a ) && ( $a['events'] ?? 0 ) > 0 ) {
				return (string) ( $a['method'] ?? 'vision_flyer' );
			}
		}
		return 'none';
	}

	/**
	 * Resolve the verdict, persist the row, and return the ability payload.
	 */
	private function finalize_and_persist( string $url, string $name, string $method, array $fingerprint ): array {
		$resolved = QualifyVerdictResolver::resolve( $fingerprint );

		$qualifier_version = defined( 'EXTRACHILL_EVENTS_VERSION' ) ? (string) EXTRACHILL_EVENTS_VERSION : '';

		// Persist — fire-and-forget. The verdict log is best-effort; a missing
		// table should not break qualify for callers.
		if ( class_exists( '\\ExtraChillEvents\\Core\\QualifyVerdictsTable' ) && QualifyVerdictsTable::table_exists() ) {
			QualifyVerdictsTable::insert(
				array(
					'url'               => $url,
					'verdict'           => $resolved['verdict'],
					'events_url'        => $resolved['events_url'],
					'fingerprint'       => $fingerprint,
					'improvement_hint'  => $resolved['improvement_hint'],
					'agent_guidance'    => $resolved['agent_guidance'],
					'event_count'       => $resolved['event_count'],
					'qualifier_version' => $qualifier_version,
				)
			);
		}

		$warnings = array();
		if ( ! empty( $fingerprint['ticketmaster_precheck']['disqualified'] ) ) {
			$warnings[] = 'Venue uses Ticketmaster/Live Nation — already covered by TM pipeline flow';
		}
		if ( '' !== ( $resolved['improvement_hint'] ?? '' )
			&& ! QualifyVerdict::is_qualified( $resolved['verdict'] ) ) {
			$warnings[] = $resolved['improvement_hint'];
		}

		return array(
			'qualified'        => QualifyVerdict::is_qualified( $resolved['verdict'] ),
			'verdict'          => $resolved['verdict'],
			'events_url'       => $resolved['events_url'],
			'method'           => $method,
			'name'             => $name,
			'event_count'      => $resolved['event_count'],
			'fingerprint'      => $fingerprint,
			'improvement_hint' => $resolved['improvement_hint'],
			'agent_guidance'   => $resolved['agent_guidance'],
			'warnings'         => $warnings,
			'urls_tested'      => isset( $fingerprint['urls_tested'] ) ? $fingerprint['urls_tested'] : array(),
		);
	}

	/**
	 * Check whether a venue URL belongs to Ticketmaster or Live Nation.
	 *
	 * Fetches the homepage and inspects both the final URL (after redirects)
	 * and the HTML body for known TM/LN patterns. If any pattern matches,
	 * the venue is disqualified because it is already covered by the dedicated
	 * Ticketmaster pipeline flow.
	 *
	 * @param string $url Venue homepage URL.
	 * @return array { disqualified: bool, reason: string, matched: string }
	 */
	private function checkTicketmasterVenue( string $url ): array {
		$not_tm = array( 'disqualified' => false, 'reason' => '', 'matched' => '' );

		// Quick domain-level check before even fetching.
		$host = strtolower( wp_parse_url( $url, PHP_URL_HOST ) ?? '' );
		foreach ( self::TICKETMASTER_PATTERNS as $pattern ) {
			if ( str_contains( $host, $pattern ) ) {
				return array(
					'disqualified' => true,
					'reason'       => 'Venue uses Ticketmaster/Live Nation — already covered by TM pipeline flow',
					'matched'      => $pattern . ' (in URL host)',
				);
			}
		}

		// Fetch the page, following redirects, so we can inspect the final URL
		// and the HTML body.
		$response = wp_remote_get( $url, array(
			'timeout'     => 10,
			'redirection' => 5,
			'user-agent'  => 'Mozilla/5.0 (compatible; ExtraChillBot/1.0; +https://extrachill.com)',
			'headers'     => array( 'Accept' => 'text/html' ),
		) );

		if ( is_wp_error( $response ) ) {
			return $not_tm;
		}

		// Check if redirect landed on a TM/LN domain.
		$final_url  = wp_remote_retrieve_header( $response, 'x-redirect-by' );
		$response_url = $response['http_response']->get_response_object()->url ?? '';
		$urls_to_check = array_filter( array( $final_url, $response_url ) );

		foreach ( $urls_to_check as $check_url ) {
			$check_host = strtolower( wp_parse_url( $check_url, PHP_URL_HOST ) ?? '' );
			foreach ( self::TICKETMASTER_PATTERNS as $pattern ) {
				if ( str_contains( $check_host, $pattern ) ) {
					return array(
						'disqualified' => true,
						'reason'       => 'Venue uses Ticketmaster/Live Nation — already covered by TM pipeline flow',
						'matched'      => $pattern . ' (redirect destination)',
					);
				}
			}
		}

		// Inspect the HTML body.
		$html = strtolower( wp_remote_retrieve_body( $response ) );
		if ( empty( $html ) ) {
			return $not_tm;
		}

		foreach ( self::TICKETMASTER_PATTERNS as $pattern ) {
			if ( str_contains( $html, strtolower( $pattern ) ) ) {
				return array(
					'disqualified' => true,
					'reason'       => 'Venue uses Ticketmaster/Live Nation — already covered by TM pipeline flow',
					'matched'      => $pattern . ' (in page HTML)',
				);
			}
		}

		// Extra checks: TM iframes and event/venue links.
		$tm_link_patterns = array(
			'ticketmaster.com/event/',
			'ticketmaster.com/venue/',
		);
		foreach ( $tm_link_patterns as $link_pattern ) {
			if ( str_contains( $html, $link_pattern ) ) {
				return array(
					'disqualified' => true,
					'reason'       => 'Venue uses Ticketmaster/Live Nation — already covered by TM pipeline flow',
					'matched'      => $link_pattern . ' (link in HTML)',
				);
			}
		}

		// Check for TM embedded widgets (iframes with ticketmaster.com in src).
		if ( preg_match( '/<iframe[^>]+src=["\'][^"\']*ticketmaster\.com[^"\']*["\'][^>]*>/i', wp_remote_retrieve_body( $response ) ) ) {
			return array(
				'disqualified' => true,
				'reason'       => 'Venue uses Ticketmaster/Live Nation — already covered by TM pipeline flow',
				'matched'      => 'ticketmaster.com iframe widget',
			);
		}

		return $not_tm;
	}

	/**
	 * Find links in HTML that likely lead to an events page.
	 *
	 * @param string $html   Page HTML.
	 * @param string $origin Site origin (scheme + host).
	 * @return array Array of ['url' => ..., 'text' => ...].
	 */
	private function findEventLinks( string $html, string $origin ): array {
		if ( empty( $html ) ) {
			return array();
		}

		$links = array();
		$seen  = array();

		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si', $html, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		foreach ( $matches as $match ) {
			$href = $match[1];
			$text = strtolower( strip_tags( trim( $match[2] ) ) );

			$is_event_link = false;
			foreach ( self::LINK_KEYWORDS as $keyword ) {
				if ( str_contains( $text, $keyword ) ) {
					$is_event_link = true;
					break;
				}
			}

			if ( ! $is_event_link ) {
				$href_lower = strtolower( $href );
				foreach ( self::EVENT_PATHS as $path ) {
					if ( str_contains( $href_lower, $path ) ) {
						$is_event_link = true;
						break;
					}
				}
			}

			if ( ! $is_event_link ) {
				continue;
			}

			if ( strpos( $href, '/' ) === 0 ) {
				$href = $origin . $href;
			} elseif ( ! preg_match( '#^https?://#', $href ) ) {
				$href = $origin . '/' . $href;
			}

			$href_host   = wp_parse_url( $href, PHP_URL_HOST );
			$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
			if ( $href_host && $origin_host && $href_host !== $origin_host ) {
				continue;
			}

			$key = strtolower( $href );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$links[] = array(
				'url'  => $href,
				'text' => strip_tags( trim( $match[2] ) ),
			);
		}

		return $links;
	}
}
