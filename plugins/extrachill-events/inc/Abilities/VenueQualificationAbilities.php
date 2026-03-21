<?php
/**
 * Venue Qualification Abilities
 *
 * Qualifies a venue website by finding its events/calendar page and testing
 * whether the universal web scraper can extract events from it. Uses the
 * datamachine/test-event-scraper ability for real scraper validation.
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
							'qualified'       => array( 'type' => 'boolean' ),
							'events_url'      => array( 'type' => 'string' ),
							'method'          => array( 'type' => 'string' ),
							'extraction_info' => array( 'type' => 'object' ),
							'event_count'     => array( 'type' => 'integer' ),
							'warnings'        => array( 'type' => 'array' ),
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
	 * Execute venue qualification.
	 *
	 * @param array $input Qualification parameters.
	 * @return array Results.
	 */
	public function executeQualifyVenue( array $input ): array {
		$url  = esc_url_raw( $input['url'] ?? '' );
		$name = sanitize_text_field( $input['name'] ?? '' );

		if ( empty( $url ) ) {
			return array( 'error' => 'URL is required.' );
		}

		$url = rtrim( $url, '/' );
		if ( ! preg_match( '#^https?://#', $url ) ) {
			$url = 'https://' . $url;
		}

		$parsed = wp_parse_url( $url );
		$origin = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' );

		// Pre-check: disqualify Ticketmaster / Live Nation venues.
		$tm_check = $this->checkTicketmasterVenue( $url );
		if ( $tm_check['disqualified'] ) {
			return array(
				'qualified'  => false,
				'events_url' => '',
				'method'     => 'ticketmaster_precheck',
				'name'       => $name,
				'reason'     => $tm_check['reason'],
				'warnings'   => array( $tm_check['reason'] ),
			);
		}

		$urls_tested = array();

		// Strategy 1: Test the given URL directly with the real scraper.
		$result = $this->testWithScraper( $url );
		$urls_tested[] = $url;

		if ( $result['qualified'] ) {
			$result['name']   = $name;
			$result['method'] = 'direct';
			return $result;
		}

		// Strategy 2: Find event page links in homepage HTML and test each.
		$homepage_html = $this->fetchPage( $url );
		if ( ! empty( $homepage_html ) ) {
			$event_links = $this->findEventLinks( $homepage_html, $origin );

			foreach ( $event_links as $link ) {
				if ( in_array( $link['url'], $urls_tested, true ) ) {
					continue;
				}

				$result = $this->testWithScraper( $link['url'] );
				$urls_tested[] = $link['url'];

				if ( $result['qualified'] ) {
					$result['name']      = $name;
					$result['method']    = 'nav_link';
					$result['link_text'] = $link['text'];
					return $result;
				}
			}
		}

		// Strategy 3: Probe common URL patterns.
		foreach ( self::EVENT_PATHS as $path ) {
			$test_url = $origin . $path;

			if ( in_array( $test_url, $urls_tested, true ) ) {
				continue;
			}

			$result = $this->testWithScraper( $test_url );
			$urls_tested[] = $test_url;

			if ( $result['qualified'] ) {
				$result['name']   = $name;
				$result['method'] = 'path_probe';
				return $result;
			}
		}

		// Strategy 4: Check for WordPress Tribe Events API.
		$wp_api_url = $origin . '/wp-json/tribe/events/v1/events';
		if ( ! in_array( $wp_api_url, $urls_tested, true ) ) {
			$result = $this->testWithScraper( $wp_api_url );
			$urls_tested[] = $wp_api_url;

			if ( $result['qualified'] ) {
				$result['name']   = $name;
				$result['method'] = 'tribe_api';
				return $result;
			}
		}

		return array(
			'qualified'    => false,
			'events_url'   => '',
			'method'       => 'none',
			'name'         => $name,
			'urls_tested'  => $urls_tested,
			'warnings'     => array( 'Scraper could not extract events from any discovered URL.' ),
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
	 * Test a URL with the actual universal web scraper.
	 *
	 * @param string $url URL to test.
	 * @return array Qualification result.
	 */
	private function testWithScraper( string $url ): array {
		$ability = wp_get_ability( 'datamachine/test-event-scraper' );

		if ( ! $ability ) {
			// Fallback: if ability not available, use lightweight HTTP check.
			return $this->fallbackCheck( $url );
		}

		$result = $ability->execute( array( 'target_url' => $url ) );

		if ( is_wp_error( $result ) ) {
			return array( 'qualified' => false );
		}

		$success         = ! empty( $result['success'] );
		$extraction_info = $result['extraction_info'] ?? array();
		$event_data      = $result['event_data'] ?? array();

		if ( ! $success ) {
			return array(
				'qualified' => false,
				'warnings'  => $result['warnings'] ?? array(),
			);
		}

		return array(
			'qualified'       => true,
			'events_url'      => $url,
			'extraction_info' => $extraction_info,
			'event_count'     => $this->countEvents( $event_data ),
			'warnings'        => $result['warnings'] ?? array(),
			'coverage_issues' => $result['coverage_issues'] ?? array(),
		);
	}

	/**
	 * Count events in scraper result data.
	 *
	 * @param array $event_data Event data from scraper test.
	 * @return int Number of events found.
	 */
	private function countEvents( array $event_data ): int {
		if ( isset( $event_data['items'] ) && is_array( $event_data['items'] ) ) {
			return count( $event_data['items'] );
		}

		// Single event result.
		if ( ! empty( $event_data['title'] ) || ! empty( $event_data['raw_html'] ) ) {
			return 1;
		}

		return 0;
	}

	/**
	 * Lightweight fallback check if scraper ability is unavailable.
	 *
	 * @param string $url URL to check.
	 * @return array Result.
	 */
	private function fallbackCheck( string $url ): array {
		$response = wp_remote_get( $url, array(
			'timeout'    => 10,
			'user-agent' => 'Mozilla/5.0 (compatible; ExtraChillBot/1.0; +https://extrachill.com)',
		) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return array( 'qualified' => false );
		}

		$html     = wp_remote_retrieve_body( $response );
		$signals  = array( 'application/ld+json', 'tribe-events', 'event-list', 'eventlist', 'etix.com', 'seetickets', 'dice.fm' );
		$found    = 0;

		foreach ( $signals as $signal ) {
			if ( stripos( $html, $signal ) !== false ) {
				++$found;
			}
		}

		return array(
			'qualified'  => $found >= 2,
			'events_url' => $found >= 2 ? $url : '',
			'method'     => 'fallback_pattern_match',
		);
	}

	/**
	 * Fetch a page's HTML.
	 *
	 * @param string $url URL to fetch.
	 * @return string HTML content or empty string.
	 */
	private function fetchPage( string $url ): string {
		$response = wp_remote_get( $url, array(
			'timeout'    => 10,
			'user-agent' => 'Mozilla/5.0 (compatible; ExtraChillBot/1.0; +https://extrachill.com)',
			'headers'    => array( 'Accept' => 'text/html' ),
		) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return '';
		}

		return wp_remote_retrieve_body( $response );
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
