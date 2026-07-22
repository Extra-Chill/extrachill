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
	 * Hostnames that indicate a Ticketmaster / Live Nation OWNED page.
	 *
	 * A page is "TM/LN-owned" when its (final, post-redirect) URL host is
	 * one of these or a subdomain thereof. Substring-matching the page body
	 * for these hostnames is NOT sufficient — promoter aggregator sites
	 * (Bowery Presents, AEG Presents, etc.) legitimately link out to TM as
	 * a ticket vendor on a per-show basis without being TM properties.
	 *
	 * Compare host with `str_contains(strtolower($host), $pattern)` so
	 * subdomains (`concerts.livenation.com`, `m.livenation.com`,
	 * `www1.ticketmaster.com`, country variants like `livenation.de`) all
	 * match the base brand string.
	 *
	 * NOTE: AEG/AXS venues are NOT disqualified — we have a dedicated
	 * AegAxsExtractor that scrapes their structured JSON feeds.
	 *
	 * @var array<int,string>
	 */
	public const TICKETMASTER_HOSTS = array(
		'ticketmaster.com',
		'ticketmaster.ca',
		'ticketmaster.co.uk',
		'ticketmaster.de',
		'ticketmaster.fr',
		'ticketmaster.es',
		'ticketmaster.it',
		'ticketmaster.com.au',
		'ticketmaster.com.mx',
		'livenation.com',
		'livenation.ca',
		'livenation.co.uk',
		'livenation.de',
		'livenation.fr',
		'livenation.com.au',
		'livenation.it',
		'livenation.es',
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
						'properties' => array(
							'url'  => array(
								'type'        => 'string',
								'description' => 'Venue website URL (homepage). The ability will find the events page and test the scraper.',
							),
							'name' => array(
								'type'        => 'string',
								'description' => 'Venue name (optional, for display).',
							),
							'flow_id' => array(
								'type'        => 'integer',
								'description' => 'Existing flow whose persisted scraper config and lifecycle scope must be diagnosed.',
							),
							'persist_verdict' => array(
								'type'        => 'boolean',
								'description' => 'Whether to persist the qualification verdict. Defaults to true; dry-run callers pass false.',
								'default'     => true,
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
							'production_context' => array( 'type' => 'object' ),
							'repair_proposal'    => array( 'type' => array( 'object', 'null' ) ),
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

		add_action( 'wp_abilities_api_init', $register_callback );
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
		$url             = esc_url_raw( $input['url'] ?? '' );
		$name            = sanitize_text_field( $input['name'] ?? '' );
		$flow_context    = null;
		$persist_verdict = ! array_key_exists( 'persist_verdict', $input ) || ! empty( $input['persist_verdict'] );

		if ( ! empty( $input['flow_id'] ) ) {
			$flow_context = $this->loadPersistedFlowContext( (int) $input['flow_id'] );
			if ( is_wp_error( $flow_context ) ) {
				return $flow_context;
			}
			$url = esc_url_raw( $flow_context['handler_config']['source_url'] ?? '' );
		}

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
			'ticketmaster_precheck' => array(
				'disqualified' => false,
				'matched'      => '',
			),
			'urls_tested'           => array(),
			'elapsed_ms'            => 0,
			'production_context'    => null === $flow_context
				? array(
					'context_supplied' => false,
					'reason'           => 'Ad-hoc URL mode has no persisted production flow context.',
				)
				: array(
					'context_supplied' => true,
					'flow_id'          => (int) $flow_context['flow_id'],
					'flow_step_id'     => (string) $flow_context['flow_step_id'],
					'job_id'           => (string) $flow_context['job_id'],
				),
		);

		// ---- Ticketmaster precheck (short-circuits everything else). ----
		$tm_check = $this->checkTicketmasterVenue( $url );
		if ( $tm_check['disqualified'] ) {
			$fingerprint['ticketmaster_precheck'] = array(
				'disqualified' => true,
				'matched'      => (string) ( $tm_check['matched'] ?? '' ),
			);
			$fingerprint['elapsed_ms']            = (int) round( ( microtime( true ) - $started_at ) * 1000 );
			return $this->finalize_and_persist( $url, $name, 'ticketmaster_precheck', $fingerprint, $persist_verdict );
		}

		// ---- Fetch the homepage once and capture status/redirects/HTML. ----
		$home                                = QualifyFingerprinter::fetch_homepage( $url );
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

		// Event-page shape classification — drives per-shape thresholds in
		// QualifyVerdictResolver. Always populated (even on fetch failure)
		// so downstream code can rely on the key. See QualifyFingerprinter
		// for the decision logic.
		$fingerprint['structured_data']['event_page_shape'] = QualifyFingerprinter::detect_event_page_shape(
			$fingerprint['final_url'],
			is_array( $fingerprint['structured_data'] ) ? $fingerprint['structured_data'] : array(),
			$html
		);

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

				$attempt = QualifyFingerprinter::run_extractor_attempt(
					$candidate['url'],
					null !== $flow_context ? $flow_context['handler_config'] : null
				);
				if ( null !== $flow_context && $candidate['url'] === $url ) {
					$attempt                          = QualifyFingerprinter::add_production_eligibility( $attempt, $flow_context );
					$fingerprint['production_context'] = $attempt['production_context'];
				}
				unset( $attempt['_diagnostic_identifiers'] );
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
		// missing-extractor verdicts via the same code path that handles
		// real extractor attempts.
		$fingerprint['extractor_attempts'] = QualifyFingerprinter::add_platform_existence_attempts(
			$fingerprint['platforms_detected'],
			$fingerprint['extractor_attempts']
		);

		$fingerprint['elapsed_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );

		$method = '' !== $short_circuit_method ? $short_circuit_method : $this->resolve_method_from_attempts( $fingerprint['extractor_attempts'] );
		return $this->finalize_and_persist( $url, $name, $method, $fingerprint, $persist_verdict );
	}

	/**
	 * Load the persisted universal web scraper step and its lifecycle scope.
	 *
	 * @param int $flow_id Flow ID.
	 * @return array|\WP_Error Flow context or an error.
	 */
	protected function loadPersistedFlowContext( int $flow_id ): array|\WP_Error {
		if ( ! class_exists( '\\DataMachine\\Core\\Database\\Flows\\Flows' ) ) {
			return new \WP_Error( 'flow_context_unavailable', 'Data Machine flow storage is unavailable.' );
		}

		$flow = ( new \DataMachine\Core\Database\Flows\Flows() )->get_flow( $flow_id );
		if ( ! is_array( $flow ) ) {
			return new \WP_Error( 'flow_not_found', sprintf( 'Flow %d was not found.', $flow_id ) );
		}

		foreach ( (array) ( $flow['flow_config'] ?? array() ) as $step ) {
			if ( ! is_array( $step ) || 'event_import' !== ( $step['step_type'] ?? '' ) ) {
				continue;
			}

			$handler_config = null;
			if ( 'universal_web_scraper' === ( $step['handler_slug'] ?? '' ) ) {
				$handler_config = $step['handler_config'] ?? null;
			} elseif ( isset( $step['handler_configs']['universal_web_scraper'] ) ) {
				$handler_config = $step['handler_configs']['universal_web_scraper'];
			}
			if ( ! is_array( $handler_config ) || empty( $handler_config['source_url'] ) ) {
				continue;
			}

			global $wpdb;
			$jobs_table = $wpdb->prefix . 'datamachine_jobs';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$job_id = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
					"SELECT job_id FROM {$jobs_table} WHERE flow_id = %s ORDER BY created_at DESC LIMIT 1",
					(string) $flow_id
				)
			);

			return array(
				'flow_id'        => $flow_id,
				'pipeline_id'    => (int) ( $step['pipeline_id'] ?? 0 ),
				'flow_step_id'   => (string) ( $step['flow_step_id'] ?? '' ),
				'job_id'         => (string) $job_id,
				'handler_config' => $handler_config,
			);
		}

		return new \WP_Error( 'flow_context_missing', sprintf( 'Flow %d has no configured universal web scraper step.', $flow_id ) );
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
			array(
				'url'    => $url,
				'method' => 'direct',
			),
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
			$candidates[] = array(
				'url'    => $origin . $path,
				'method' => 'path_probe',
			);
		}

		$candidates[] = array(
			'url'    => $origin . '/wp-json/tribe/events/v1/events',
			'method' => 'tribe_api',
		);

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
	private function finalize_and_persist( string $url, string $name, string $method, array $fingerprint, bool $persist_verdict = true ): array {
		$resolved = QualifyVerdictResolver::resolve( $fingerprint );

		$qualifier_version = defined( 'EXTRACHILL_EVENTS_VERSION' ) ? (string) EXTRACHILL_EVENTS_VERSION : '';

		// Persist — fire-and-forget. The verdict log is best-effort; a missing
		// table should not break qualify for callers.
		if ( $persist_verdict && class_exists( '\\ExtraChillEvents\\Core\\QualifyVerdictsTable' ) && QualifyVerdictsTable::table_exists() ) {
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

		$repair_proposal = null;
		$events_url      = (string) ( $resolved['events_url'] ?? '' );
		if ( ! empty( $fingerprint['production_context']['context_supplied'] )
			&& '' !== $events_url
			&& untrailingslashit( $events_url ) !== untrailingslashit( $url ) ) {
			$repair_proposal = array(
				'type'            => 'source_url',
				'current'         => $url,
				'proposed'        => $events_url,
				'same_host'       => strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( $events_url, PHP_URL_HOST ) ),
				'applied'         => false,
				'confirmation'    => 'A separate explicit apply with confirmation is required.',
			);
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
			'production_context' => $fingerprint['production_context'] ?? array(),
			'repair_proposal'    => $repair_proposal,
		);
	}

	/**
	 * Check whether a venue URL belongs to Ticketmaster or Live Nation.
	 *
	 * Fetches the page (following redirects) and delegates classification to
	 * {@see self::analyzeForTicketmasterMarkers()}, which inspects the FINAL
	 * (post-redirect) URL host and STRUCTURAL ownership markers in the HTML
	 * — never substring-matching outbound buy-ticket links.
	 *
	 * Rationale: promoter aggregator sites (Bowery Presents, AEG Presents,
	 * etc.) legitimately link to ticketmaster.com per-event as a ticket
	 * vendor. Those outbound links are pricing data, not ownership signals,
	 * and must not disqualify the site. See extrachill-events#90.
	 *
	 * @param string $url Venue homepage URL.
	 * @return array{disqualified:bool,reason:string,matched:string}
	 */
	private function checkTicketmasterVenue( string $url ): array {
		$not_tm = array(
			'disqualified' => false,
			'reason'       => '',
			'matched'      => '',
		);

		// Quick host check on the INPUT URL before fetching. Catches the
		// trivial case where someone hands us a ticketmaster.com URL outright.
		$pre_fetch = self::analyzeForTicketmasterMarkers( $url, '' );
		if ( $pre_fetch['disqualified'] ) {
			return array(
				'disqualified' => true,
				'reason'       => 'Venue uses Ticketmaster/Live Nation — already covered by TM pipeline flow',
				'matched'      => $pre_fetch['matched'],
			);
		}

		// Fetch the page, following redirects, so we can inspect the final
		// URL and the HTML body.
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 5,
				'user-agent'  => 'Mozilla/5.0 (compatible; ExtraChillBot/1.0; +https://extrachill.com)',
				'headers'     => array( 'Accept' => 'text/html' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $not_tm;
		}

		// Resolve the final URL after the redirect chain. WP exposes it on
		// the Requests response object hung off the wp_remote_get result.
		$final_url = $url;
		if ( isset( $response['http_response'] ) && is_object( $response['http_response'] )
			&& method_exists( $response['http_response'], 'get_response_object' ) ) {
			$resp_obj = $response['http_response']->get_response_object();
			if ( isset( $resp_obj->url ) && is_string( $resp_obj->url ) && '' !== $resp_obj->url ) {
				$final_url = $resp_obj->url;
			}
		}

		$html = (string) wp_remote_retrieve_body( $response );

		$analysis = self::analyzeForTicketmasterMarkers( $final_url, $html );
		if ( $analysis['disqualified'] ) {
			return array(
				'disqualified' => true,
				'reason'       => 'Venue uses Ticketmaster/Live Nation — already covered by TM pipeline flow',
				'matched'      => $analysis['matched'],
			);
		}

		return $not_tm;
	}

	/**
	 * Pure classifier: does this (final URL, HTML body) pair indicate a
	 * Ticketmaster/Live Nation-OWNED page?
	 *
	 * Distinguishes LN-owned venue pages (which the dedicated TM pipeline
	 * flow already covers) from promoter aggregator pages that merely LINK
	 * to TM as a ticket vendor (which should continue through extraction).
	 *
	 * Signals checked, in order of decisiveness:
	 *   1. Final URL host is on {@see self::TICKETMASTER_HOSTS}.
	 *   2. `<link rel="canonical" href="…">` points to a TM/LN host.
	 *   3. `<meta property="og:site_name" content="Ticketmaster">` (or
	 *       "Live Nation"), or `<meta name="application-name" …>` with the
	 *       same brand value. Must be the SITE_NAME, not arbitrary content.
	 *   4. JSON-LD `Organization` block whose `@id`/`url`/`sameAs` field
	 *       targets a TM/LN host.
	 *   5. Live Nation SDK / tag-manager bootstrap in `<head>` —
	 *       `window.TMP = …` or `window.LN = …` initialisers. These only
	 *       ship on LN-controlled pages.
	 *
	 * Outbound `<a href="https://www.ticketmaster.com/event/…">` buy links
	 * are deliberately NOT a disqualifier — they are normal commerce data.
	 *
	 * @param string $final_url Final URL after redirects (or the input URL
	 *                          when called pre-fetch with an empty body).
	 * @param string $html      Page HTML. May be empty for host-only checks.
	 * @return array{disqualified:bool,matched:string}
	 */
	public static function analyzeForTicketmasterMarkers( string $final_url, string $html ): array {
		$not_tm = array(
			'disqualified' => false,
			'matched'      => '',
		);

		// ---- 1. Final URL host check. ----
		$host = strtolower( (string) ( wp_parse_url( $final_url, PHP_URL_HOST ) ?? '' ) );
		if ( '' !== $host ) {
			$matched_host = self::hostMatchesTicketmaster( $host );
			if ( '' !== $matched_host ) {
				return array(
					'disqualified' => true,
					'matched'      => 'final URL host: ' . $matched_host,
				);
			}
		}

		if ( '' === $html ) {
			return $not_tm;
		}

		// ---- 2. <link rel="canonical" href="…"> pointing to TM/LN. ----
		// Accept either attribute order (rel-first or href-first) by running
		// both patterns through a single matcher.
		$canonical_patterns = array(
			'#<link\s[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\']#i',
			'#<link\s[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\']canonical["\']#i',
		);
		foreach ( $canonical_patterns as $pattern ) {
			if ( ! preg_match( $pattern, $html, $m ) ) {
				continue;
			}
			$canonical_host  = strtolower( (string) ( wp_parse_url( $m[1], PHP_URL_HOST ) ?? '' ) );
			$matched_pattern = self::hostMatchesTicketmaster( $canonical_host );
			if ( '' !== $matched_pattern ) {
				return array(
					'disqualified' => true,
					'matched'      => 'canonical link to ' . $matched_pattern,
				);
			}
		}

		// ---- 3. <meta property="og:site_name" / name="application-name"> ----
		// with a literal TM/LN brand value. The CONTENT must be the brand,
		// not just a page title mentioning the brand. Accept either attribute
		// order (rel/name-first or content-first).
		$brand_re      = '(?:Ticketmaster|Live\s*Nation)';
		$brand_attr    = '(?:property|name)=["\'](?:og:site_name|application-name)["\']';
		$meta_patterns = array(
			'#<meta\s[^>]*' . $brand_attr . '[^>]*content=["\']\s*' . $brand_re . '\s*["\']#i',
			'#<meta\s[^>]*content=["\']\s*' . $brand_re . '\s*["\'][^>]*' . $brand_attr . '#i',
		);
		foreach ( $meta_patterns as $pattern ) {
			if ( preg_match( $pattern, $html ) ) {
				return array(
					'disqualified' => true,
					'matched'      => 'meta site_name brand tag',
				);
			}
		}

		// ---- 4. JSON-LD Organization with @id/url/sameAs on TM/LN host. ----
		if ( preg_match_all(
			'#<script\s[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
			$html,
			$blocks
		) ) {
			foreach ( $blocks[1] as $block ) {
				$decoded = json_decode( trim( $block ), true );
				if ( null === $decoded ) {
					continue;
				}
				$marker = self::scanJsonLdForLnOrganization( $decoded );
				if ( '' !== $marker ) {
					return array(
						'disqualified' => true,
						'matched'      => 'JSON-LD Organization on ' . $marker,
					);
				}
			}
		}

		// ---- 5. Inline LN SDK bootstrap in <head>. ----
		// Scope to head to avoid false positives from third-party content
		// (analytics scripts referenced via src=, comments, etc.).
		$head = '';
		if ( preg_match( '#<head\b[^>]*>(.*?)</head>#is', $html, $m ) ) {
			$head = $m[1];
		}
		if ( '' !== $head ) {
			if ( preg_match( '#window\.TMP\s*=#', $head )
				|| preg_match( '#window\.LN\s*=#', $head )
				|| preg_match( '#window\.LiveNation\s*=#i', $head ) ) {
				return array(
					'disqualified' => true,
					'matched'      => 'LN SDK bootstrap in <head>',
				);
			}
		}

		return $not_tm;
	}

	/**
	 * Return the matching TM/LN host pattern for a hostname, or '' if none.
	 *
	 * Matches when the host equals the pattern or ends with `.$pattern` so
	 * subdomains (`concerts.livenation.com`, `www.ticketmaster.com`) all
	 * resolve to their brand base.
	 *
	 * @param string $host Lowercase hostname.
	 * @return string Matching pattern or empty string.
	 */
	private static function hostMatchesTicketmaster( string $host ): string {
		if ( '' === $host ) {
			return '';
		}
		foreach ( self::TICKETMASTER_HOSTS as $pattern ) {
			if ( $host === $pattern || str_ends_with( $host, '.' . $pattern ) ) {
				return $pattern;
			}
		}
		return '';
	}

	/**
	 * Walk a decoded JSON-LD structure looking for an Organization node
	 * whose `@id`/`url`/`sameAs` field points to a TM/LN host. Returns the
	 * matching host pattern, or '' if none.
	 *
	 * Handles top-level objects, `@graph` arrays, and arrays of nodes.
	 *
	 * @param mixed $node Decoded JSON-LD fragment.
	 * @return string
	 */
	private static function scanJsonLdForLnOrganization( $node ): string {
		if ( is_array( $node ) ) {
			// Sequential array of nodes — recurse into each.
			if ( array_keys( $node ) === range( 0, count( $node ) - 1 ) ) {
				foreach ( $node as $child ) {
					$hit = self::scanJsonLdForLnOrganization( $child );
					if ( '' !== $hit ) {
						return $hit;
					}
				}
				return '';
			}

			// Associative — could be a node OR a wrapper with @graph.
			if ( isset( $node['@graph'] ) ) {
				$hit = self::scanJsonLdForLnOrganization( $node['@graph'] );
				if ( '' !== $hit ) {
					return $hit;
				}
			}

			$type   = $node['@type'] ?? '';
			$types  = is_array( $type ) ? $type : array( $type );
			$is_org = false;
			foreach ( $types as $t ) {
				if ( is_string( $t ) && 'organization' === strtolower( $t ) ) {
					$is_org = true;
					break;
				}
			}

			if ( $is_org ) {
				$candidates = array();
				foreach ( array( '@id', 'url' ) as $key ) {
					if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) ) {
						$candidates[] = $node[ $key ];
					}
				}
				if ( isset( $node['sameAs'] ) ) {
					$same_as = is_array( $node['sameAs'] ) ? $node['sameAs'] : array( $node['sameAs'] );
					foreach ( $same_as as $s ) {
						if ( is_string( $s ) ) {
							$candidates[] = $s;
						}
					}
				}

				foreach ( $candidates as $candidate ) {
					$cand_host = strtolower( (string) ( wp_parse_url( $candidate, PHP_URL_HOST ) ?? '' ) );
					$matched   = self::hostMatchesTicketmaster( $cand_host );
					if ( '' !== $matched ) {
						return $matched;
					}
				}
			}
		}

		return '';
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
			$text = strtolower( wp_strip_all_tags( trim( $match[2] ) ) );

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
				'text' => wp_strip_all_tags( trim( $match[2] ) ),
			);
		}

		return $links;
	}
}
