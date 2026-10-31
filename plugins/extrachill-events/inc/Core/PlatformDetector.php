<?php
/**
 * Platform Detector
 *
 * Inspects raw HTML and returns:
 *  - `platforms_detected` — the array of platforms the page is running on
 *  - `structured_data`    — counts of JSON-LD Event entries, microdata
 *                            Event entries, vision image candidates, and a
 *                            jsonld_event_graph_present flag
 *
 * Used by VenueQualificationAbilities to assemble the fingerprint that the
 * QualifyVerdictResolver consumes.
 *
 * Multiple platforms can co-detect (e.g. Squarespace + OpenTable); the result
 * reflects reality and the resolver handles the combination logic.
 *
 * Regexes are deliberately tight — strings appear in real-world HTML for these
 * platforms. They are kept here so the verdict resolver stays purely about
 * "what does the fingerprint mean", not "how is the fingerprint assembled".
 *
 * @package ExtraChillEvents\Core
 * @since   0.20.0
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PlatformDetector {

	/**
	 * Detect platforms from raw HTML.
	 *
	 * @param string $html Raw page HTML.
	 * @return array<int,string> Sorted, deduped list of detected platform slugs.
	 */
	public static function detect_platforms( string $html ): array {
		if ( '' === $html ) {
			return array();
		}

		$detected = array();

		// bandzoogle: HTML contains bandzoogle.com OR class="gig-info" OR class="gigs".
		if ( false !== stripos( $html, 'bandzoogle.com' )
			|| preg_match( '/class\s*=\s*["\'][^"\']*\bgig-info\b/i', $html )
			|| preg_match( '/class\s*=\s*["\'][^"\']*\bgigs\b/i', $html ) ) {
			$detected[] = 'bandzoogle';
		}

		// squarespace: HTML contains Static.SQUARESPACE_CONTEXT.
		if ( false !== strpos( $html, 'Static.SQUARESPACE_CONTEXT' ) ) {
			$detected[] = 'squarespace';
		}

		// wordpress_generic vs wordpress_tribe: requires a separate probe for
		// the /wp-json/tribe endpoint (see detect_tribe_api()). Here we only
		// flag generic WordPress; the caller adds 'wordpress_tribe' afterward
		// when the API probe succeeds.
		if ( ( false !== stripos( $html, 'wp-content/themes/' )
				|| false !== stripos( $html, 'wp-content/plugins/' ) )
			&& false === stripos( $html, '/wp-json/tribe/events/v1/events' ) ) {
			$detected[] = 'wordpress_generic';
		}

		// webflow: HTML contains webflow.com OR data-wf-page=.
		if ( false !== stripos( $html, 'webflow.com' )
			|| false !== stripos( $html, 'data-wf-page=' ) ) {
			$detected[] = 'webflow';
		}

		// wix: HTML contains wix.com OR X-Wix-Application-Instance-Id (which
		// can also appear in the rendered HTML when Wix bootstraps).
		if ( false !== stripos( $html, 'wix.com' )
			|| false !== stripos( $html, 'X-Wix-Application-Instance-Id' ) ) {
			$detected[] = 'wix';
		}

		// opentable: HTML contains opentable.com/widget OR cdn.opentable.com.
		if ( false !== stripos( $html, 'opentable.com/widget' )
			|| false !== stripos( $html, 'cdn.opentable.com' ) ) {
			$detected[] = 'opentable';
		}

		// resy: HTML contains resy.com/widget OR widgets.resy.com.
		if ( false !== stripos( $html, 'resy.com/widget' )
			|| false !== stripos( $html, 'widgets.resy.com' ) ) {
			$detected[] = 'resy';
		}

		// tock: HTML contains tock.com/widget OR exploretock.com.
		if ( false !== stripos( $html, 'tock.com/widget' )
			|| false !== stripos( $html, 'exploretock.com' ) ) {
			$detected[] = 'tock';
		}

		// eventbrite: embedded widgets or organizer pages.
		if ( false !== stripos( $html, 'eventbrite.com/o/' )
			|| preg_match( '/eventbrite\.com\/(?:e|d|widget|static)/i', $html ) ) {
			$detected[] = 'eventbrite';
		}

		// dice_fm: HTML contains dice.fm/event/ widgets.
		if ( false !== stripos( $html, 'dice.fm/event/' )
			|| false !== stripos( $html, 'dice.fm/widget' ) ) {
			$detected[] = 'dice_fm';
		}

		// ticketmaster_widget: embedded widgets only — actual TM-hosted venue
		// pages are caught by the dedicated TM precheck in
		// VenueQualificationAbilities and do NOT flow through this detector.
		if ( false !== stripos( $html, 'ticketmaster.com/event/' ) ) {
			$detected[] = 'ticketmaster_widget';
		}

		return array_values( array_unique( $detected ) );
	}

	/**
	 * Probe a Tribe Events API endpoint to confirm WordPress + Tribe.
	 *
	 * Called by VenueQualificationAbilities; kept separate from
	 * detect_platforms() so the pure HTML inspection stays I/O-free.
	 *
	 * @param string $origin Site origin (scheme + host, no trailing slash).
	 * @return bool True if the endpoint responds with a 2xx that looks like a
	 *              Tribe Events REST collection.
	 */
	public static function probe_tribe_api( string $origin ): bool {
		$origin = rtrim( $origin, '/' );
		if ( '' === $origin ) {
			return false;
		}

		$endpoint = $origin . '/wp-json/tribe/events/v1/events';

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout'    => 8,
				'user-agent' => 'Mozilla/5.0 (compatible; ExtraChillBot/1.0; +https://extrachill.com)',
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return false;
		}

		$decoded = json_decode( $body, true );
		// Tribe collection responses have an `events` array, even when empty.
		return is_array( $decoded ) && array_key_exists( 'events', $decoded );
	}

	/**
	 * Inspect HTML for structured event signals.
	 *
	 * @param string $html Raw page HTML.
	 * @return array{
	 *     jsonld_events: int,
	 *     jsonld_event_graph_present: bool,
	 *     microdata_events: int,
	 *     tribe_api: bool,
	 *     vision_image_candidates: int
	 * }
	 */
	public static function detect_structured_data( string $html ): array {
		$out = array(
			'jsonld_events'              => 0,
			'jsonld_event_graph_present' => false,
			'microdata_events'           => 0,
			'tribe_api'                  => false,
			'vision_image_candidates'    => 0,
		);

		if ( '' === $html ) {
			return $out;
		}

		// JSON-LD blocks: count Event entries inside <script type="application/ld+json">.
		if ( preg_match_all(
			'#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
			$html,
			$matches
		) ) {
			foreach ( $matches[1] as $block ) {
				$decoded = json_decode( trim( $block ), true );
				if ( null === $decoded ) {
					continue;
				}
				$count                             = self::count_event_entries( $decoded );
				$out['jsonld_events']             += $count;
				$out['jsonld_event_graph_present'] = $out['jsonld_event_graph_present'] || $count > 0 || self::has_event_type_anywhere( $decoded );
			}
		}

		// Microdata: itemtype Event variants on any tag.
		if ( preg_match_all(
			'#itemtype\s*=\s*["\']https?://schema\.org/(?:Event|MusicEvent|TheaterEvent|SocialEvent|Festival|ComedyEvent|DanceEvent)["\']#i',
			$html,
			$mmatches
		) ) {
			$out['microdata_events'] = count( $mmatches[0] );
		}

		// Vision candidates: <img> tags within plausible event-flyer contexts.
		// Conservative heuristic — counts <img> tags whose alt/class hints at
		// flyer/poster/event imagery. The actual vision_flyer extractor in
		// data-machine-events does much richer filtering; this just gives the
		// fingerprint a hint about how many candidates exist.
		if ( preg_match_all(
			'#<img\b[^>]*(?:alt|class|src)\s*=\s*["\'][^"\']*(?:flyer|poster|event|show|gig|lineup)[^"\']*["\'][^>]*>#i',
			$html,
			$imatches
		) ) {
			$out['vision_image_candidates'] = count( $imatches[0] );
		}

		return $out;
	}

	/**
	 * Recursively count "@type": Event-ish entries in a decoded JSON-LD blob.
	 */
	private static function count_event_entries( $data ): int {
		if ( ! is_array( $data ) ) {
			return 0;
		}

		$count = 0;

		if ( self::is_event_type( $data['@type'] ?? null ) ) {
			++$count;
		}

		// @graph is the standard container for multi-entity JSON-LD.
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $node ) {
				if ( is_array( $node ) && self::is_event_type( $node['@type'] ?? null ) ) {
					++$count;
				}
			}
		}

		// Bare array of entities at the top level.
		if ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			foreach ( $data as $node ) {
				$count += self::count_event_entries( $node );
			}
		}

		return $count;
	}

	/**
	 * Whether any Event-type token appears anywhere in a decoded blob — used
	 * to set jsonld_event_graph_present even when our counter could not find
	 * a parseable entity (e.g. malformed JSON-LD where the type string is
	 * still recognizable).
	 */
	private static function has_event_type_anywhere( $data ): bool {
		if ( is_string( $data ) ) {
			return self::is_event_type( $data );
		}
		if ( ! is_array( $data ) ) {
			return false;
		}
		foreach ( $data as $value ) {
			if ( self::has_event_type_anywhere( $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a JSON-LD @type value represents an event of any kind.
	 *
	 * @param mixed $type @type value (string or array of strings).
	 */
	private static function is_event_type( $type ): bool {
		if ( is_string( $type ) ) {
			return self::matches_event_type( $type );
		}
		if ( is_array( $type ) ) {
			foreach ( $type as $t ) {
				if ( is_string( $t ) && self::matches_event_type( $t ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function matches_event_type( string $type ): bool {
		$normalized = strtolower( ltrim( $type, '@' ) );
		return in_array(
			$normalized,
			array(
				'event',
				'musicevent',
				'theaterevent',
				'socialevent',
				'festival',
				'comedyevent',
				'danceevent',
				'sportsevent',
				'businessevent',
				'foodevent',
				'literaryevent',
				'visualartsevent',
				'screeningevent',
				'educationevent',
			),
			true
		);
	}
}
