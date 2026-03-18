<?php
/**
 * Venue Qualification Abilities
 *
 * Qualifies a venue website by finding its events/calendar page and testing
 * whether it contains scrapable event listings. Crawls the homepage for
 * common event page links, tries known URL patterns, and reports results.
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

	/**
	 * Indicators that a page contains event listings.
	 */
	private const EVENT_INDICATORS = array(
		// Structured data.
		'application/ld+json',
		'MusicEvent',
		'EventScheduled',
		// Common event page patterns.
		'event-list',
		'event-item',
		'event-card',
		'eventlist',
		'upcoming-events',
		'show-listing',
		// Tribe Events (WordPress).
		'tribe-events',
		'tribe/events/v1',
		// Squarespace.
		'collection-type-events',
		'eventlist-event',
		// Etix / See Tickets / Eventbrite.
		'etix.com',
		'seetickets',
		'eventbrite',
		// Dice.fm embeds.
		'dice.fm',
		// Date patterns (multiple dates = probably event listings).
		'datetime',
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
					'description'         => __( 'Check if a venue website has a scrapable events page. Finds the events URL and tests for event listings.', 'extrachill-events' ),
					'category'            => 'extrachill-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'url' ),
						'properties' => array(
							'url' => array(
								'type'        => 'string',
								'description' => 'Venue website URL (homepage). The ability will crawl to find the events page.',
							),
							'name' => array(
								'type'        => 'string',
								'description' => 'Venue name (optional, for logging).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'qualified'  => array( 'type' => 'boolean' ),
							'events_url' => array( 'type' => 'string' ),
							'method'     => array( 'type' => 'string' ),
							'signals'    => array( 'type' => 'array' ),
							'score'      => array( 'type' => 'integer' ),
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

		// Normalize URL.
		$url = rtrim( $url, '/' );
		if ( ! preg_match( '#^https?://#', $url ) ) {
			$url = 'https://' . $url;
		}

		$parsed = wp_parse_url( $url );
		$origin = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' );

		// Strategy 1: Check if the given URL itself has events.
		$homepage_result = $this->checkPage( $url );
		if ( $homepage_result['score'] >= 3 ) {
			return array(
				'qualified'  => true,
				'events_url' => $url,
				'method'     => 'homepage_direct',
				'signals'    => $homepage_result['signals'],
				'score'      => $homepage_result['score'],
				'name'       => $name,
			);
		}

		// Strategy 2: Look for event page links in homepage HTML.
		$found_links = $this->findEventLinks( $homepage_result['html'], $origin );
		foreach ( $found_links as $link ) {
			$link_result = $this->checkPage( $link['url'] );
			if ( $link_result['score'] >= 2 ) {
				return array(
					'qualified'  => true,
					'events_url' => $link['url'],
					'method'     => 'nav_link',
					'link_text'  => $link['text'],
					'signals'    => $link_result['signals'],
					'score'      => $link_result['score'],
					'name'       => $name,
				);
			}
		}

		// Strategy 3: Try common URL patterns.
		foreach ( self::EVENT_PATHS as $path ) {
			$test_url    = $origin . $path;
			$path_result = $this->checkPage( $test_url );
			if ( $path_result['score'] >= 2 ) {
				return array(
					'qualified'  => true,
					'events_url' => $test_url,
					'method'     => 'path_probe',
					'signals'    => $path_result['signals'],
					'score'      => $path_result['score'],
					'name'       => $name,
				);
			}
		}

		// Strategy 4: Check for WordPress REST API (Tribe Events).
		$wp_api_url  = $origin . '/wp-json/tribe/events/v1/events';
		$api_result  = $this->checkPage( $wp_api_url );
		if ( $api_result['score'] >= 1 && strpos( $api_result['html'], '"events"' ) !== false ) {
			return array(
				'qualified'  => true,
				'events_url' => $wp_api_url,
				'method'     => 'tribe_api',
				'signals'    => array( 'WordPress Tribe Events API detected' ),
				'score'      => 5,
				'name'       => $name,
			);
		}

		// Not qualified.
		return array(
			'qualified'       => false,
			'events_url'      => '',
			'method'          => 'none',
			'signals'         => $homepage_result['signals'] ?: array( 'No event indicators found on homepage or common paths' ),
			'score'           => 0,
			'name'            => $name,
			'checked_urls'    => array_merge(
				array( $url ),
				array_column( $found_links, 'url' ),
				array_map( fn( $p ) => $origin . $p, array_slice( self::EVENT_PATHS, 0, 5 ) )
			),
		);
	}

	/**
	 * Fetch a page and check for event listing indicators.
	 *
	 * @param string $url URL to check.
	 * @return array With 'score', 'signals', and 'html' keys.
	 */
	private function checkPage( string $url ): array {
		$response = wp_remote_get( $url, array(
			'timeout'    => 10,
			'user-agent' => 'Mozilla/5.0 (compatible; ExtraChillBot/1.0; +https://extrachill.com)',
			'headers'    => array( 'Accept' => 'text/html,application/json' ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'score' => 0, 'signals' => array(), 'html' => '' );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 400 ) {
			return array( 'score' => 0, 'signals' => array(), 'html' => '' );
		}

		$html    = wp_remote_retrieve_body( $response );
		$signals = array();
		$score   = 0;

		foreach ( self::EVENT_INDICATORS as $indicator ) {
			if ( stripos( $html, $indicator ) !== false ) {
				$signals[] = $indicator;
				++$score;
			}
		}

		return array( 'score' => $score, 'signals' => $signals, 'html' => $html );
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

		// Extract all <a> tags.
		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si', $html, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		foreach ( $matches as $match ) {
			$href = $match[1];
			$text = strtolower( strip_tags( trim( $match[2] ) ) );

			// Check if link text matches event keywords.
			$is_event_link = false;
			foreach ( self::LINK_KEYWORDS as $keyword ) {
				if ( str_contains( $text, $keyword ) ) {
					$is_event_link = true;
					break;
				}
			}

			// Also check href for event path patterns.
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

			// Resolve relative URLs.
			if ( strpos( $href, '/' ) === 0 ) {
				$href = $origin . $href;
			} elseif ( ! preg_match( '#^https?://#', $href ) ) {
				$href = $origin . '/' . $href;
			}

			// Skip external links (different domain).
			$href_host = wp_parse_url( $href, PHP_URL_HOST );
			$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
			if ( $href_host && $origin_host && $href_host !== $origin_host ) {
				continue;
			}

			// Deduplicate.
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
