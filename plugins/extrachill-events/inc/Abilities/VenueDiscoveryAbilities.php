<?php
/**
 * Venue Discovery Abilities
 *
 * Discovers music venues in a city using Google Places API (New), cross-references
 * against existing venue taxonomy to identify new venues, and provides structured
 * results for qualification and flow creation.
 *
 * Uses the same GCP service account as Data Machine analytics (GA/GSC).
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueDiscoveryAbilities {

	/**
	 * Google Places API endpoint.
	 */
	private const PLACES_API_URL = 'https://places.googleapis.com/v1/places:searchText';

	/**
	 * Google OAuth2 token endpoint.
	 */
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * OAuth2 scope for Places API.
	 */
	private const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

	/**
	 * Max results per Places API request (API maximum is 20).
	 */
	private const MAX_RESULTS = 20;

	/**
	 * DM config option that stores the Google service account JSON.
	 */
	private const CONFIG_OPTION = 'datamachine_ga_config';

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
				'extrachill/discover-venues',
				array(
					'label'               => __( 'Discover Venues', 'extrachill-events' ),
					'description'         => __( 'Discover music venues in a city using Google Places API. Returns venues not already in the calendar, with website URLs for qualification.', 'extrachill-events' ),
					'category'            => 'extrachill-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'city' ),
						'properties' => array(
							'city'          => array(
								'type'        => 'string',
								'description' => 'City with state, e.g. "Nashville, TN" or "Austin, Texas"',
							),
							'query'         => array(
								'type'        => 'string',
								'description' => 'Custom search query. Defaults to "music venues in {city}". Override for specific searches like "jazz clubs in {city}" or "dive bars with live music in {city}".',
							),
							'include_known' => array(
								'type'        => 'boolean',
								'description' => 'Include venues that already exist in our taxonomy. Default: false.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'city'           => array( 'type' => 'string' ),
							'total_found'    => array( 'type' => 'integer' ),
							'new_venues'     => array( 'type' => 'integer' ),
							'known_venues'   => array( 'type' => 'integer' ),
							'venues'         => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( $this, 'executeDiscoverVenues' ),
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
	 * Execute venue discovery.
	 *
	 * @param array $input Discovery parameters.
	 * @return array Results with venue list.
	 */
	public function executeDiscoverVenues( array $input ): array {
		$city          = sanitize_text_field( $input['city'] ?? '' );
		$custom_query  = sanitize_text_field( $input['query'] ?? '' );
		$include_known = ! empty( $input['include_known'] );

		if ( empty( $city ) ) {
			return array( 'error' => 'City is required.' );
		}

		$query = ! empty( $custom_query ) ? $custom_query : "music venues in {$city}";

		// Get access token.
		$token = $this->getAccessToken();
		if ( is_wp_error( $token ) ) {
			return array( 'error' => $token->get_error_message() );
		}

		// Query Google Places.
		$places = $this->searchPlaces( $token, $query );
		if ( is_wp_error( $places ) ) {
			return array( 'error' => $places->get_error_message() );
		}

		// Get existing venue terms for cross-reference.
		$existing_venues = $this->getExistingVenueNames();

		// Classify results.
		$venues       = array();
		$new_count    = 0;
		$known_count  = 0;

		foreach ( $places as $place ) {
			$name    = $place['displayName']['text'] ?? '';
			$address = $place['formattedAddress'] ?? '';
			$website = $place['websiteUri'] ?? '';
			$lat     = $place['location']['latitude'] ?? null;
			$lng     = $place['location']['longitude'] ?? null;
			$types   = $place['types'] ?? array();
			$maps    = $place['googleMapsUri'] ?? '';

			if ( empty( $name ) ) {
				continue;
			}

			// Clean up Google's UTM-heavy website URLs.
			$website = $this->cleanWebsiteUrl( $website );

			// Check if venue already exists in taxonomy.
			$is_known = $this->isKnownVenue( $name, $existing_venues );

			if ( $is_known ) {
				++$known_count;
				if ( ! $include_known ) {
					continue;
				}
			} else {
				++$new_count;
			}

			$venues[] = array(
				'name'       => $name,
				'address'    => $address,
				'website'    => $website,
				'latitude'   => $lat,
				'longitude'  => $lng,
				'types'      => $types,
				'maps_url'   => $maps,
				'is_known'   => $is_known,
			);
		}

		return array(
			'city'         => $city,
			'query'        => $query,
			'total_found'  => count( $places ),
			'new_venues'   => $new_count,
			'known_venues' => $known_count,
			'venues'       => $venues,
		);
	}

	/**
	 * Get OAuth2 access token using service account JWT.
	 *
	 * @return string|\WP_Error Access token or error.
	 */
	private function getAccessToken() {
		$config = get_site_option( self::CONFIG_OPTION, array() );

		if ( empty( $config['service_account_json'] ) ) {
			return new \WP_Error(
				'no_service_account',
				'Google service account not configured. Set it in Data Machine Analytics settings.'
			);
		}

		$sa = json_decode( $config['service_account_json'], true );
		if ( empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
			return new \WP_Error( 'invalid_service_account', 'Service account JSON missing client_email or private_key.' );
		}

		$now    = time();
		$header = base64_encode( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = base64_encode( wp_json_encode( array(
			'iss'   => $sa['client_email'],
			'scope' => self::SCOPE,
			'aud'   => self::TOKEN_URL,
			'exp'   => $now + 3600,
			'iat'   => $now,
		) ) );

		$unsigned  = $header . '.' . $claims;
		$signature = '';

		if ( ! openssl_sign( $unsigned, $signature, $sa['private_key'], 'SHA256' ) ) {
			return new \WP_Error( 'jwt_sign_failed', 'Failed to sign JWT.' );
		}

		$jwt = $unsigned . '.' . str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), base64_encode( $signature ) );

		$response = wp_remote_post( self::TOKEN_URL, array(
			'body' => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			return new \WP_Error( 'token_error', 'Failed to get access token: ' . wp_json_encode( $body ) );
		}

		return $body['access_token'];
	}

	/**
	 * Search Google Places API for venues.
	 *
	 * @param string $token  Access token.
	 * @param string $query  Search query.
	 * @return array|\WP_Error Array of place results or error.
	 */
	private function searchPlaces( string $token, string $query ) {
		$response = wp_remote_post( self::PLACES_API_URL, array(
			'headers' => array(
				'Authorization'   => 'Bearer ' . $token,
				'Content-Type'    => 'application/json',
				'X-Goog-FieldMask' => implode( ',', array(
					'places.displayName',
					'places.formattedAddress',
					'places.websiteUri',
					'places.types',
					'places.location',
					'places.googleMapsUri',
				) ),
			),
			'body'    => wp_json_encode( array(
				'textQuery'      => $query,
				'maxResultCount' => self::MAX_RESULTS,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			$msg = $body['error']['message'] ?? "Google Places API returned status {$status}";
			return new \WP_Error( 'places_api_error', $msg );
		}

		return $body['places'] ?? array();
	}

	/**
	 * Get all existing venue taxonomy term names (lowercased for matching).
	 *
	 * @return array Map of lowercase name => term_id.
	 */
	private function getExistingVenueNames(): array {
		$terms = get_terms( array(
			'taxonomy'   => 'venue',
			'hide_empty' => false,
			'fields'     => 'id=>name',
		) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$map = array();
		foreach ( $terms as $id => $name ) {
			$map[ strtolower( trim( $name ) ) ] = $id;
		}

		return $map;
	}

	/**
	 * Check if a venue name matches an existing taxonomy term.
	 *
	 * Uses normalized comparison with common prefix/suffix stripping
	 * (e.g. "The" prefix, "Nashville" suffix).
	 *
	 * @param string $name            Venue name from Places API.
	 * @param array  $existing_venues Map of lowercase name => term_id.
	 * @return bool True if venue is already known.
	 */
	private function isKnownVenue( string $name, array $existing_venues ): bool {
		$normalized = strtolower( trim( $name ) );

		// Exact match.
		if ( isset( $existing_venues[ $normalized ] ) ) {
			return true;
		}

		// Without "The" prefix.
		$without_the = preg_replace( '/^the\s+/i', '', $normalized );
		if ( $without_the !== $normalized && isset( $existing_venues[ $without_the ] ) ) {
			return true;
		}

		// Check if any existing venue starts with the same name (handle "Exit/In" vs "EXIT/IN Nashville").
		foreach ( $existing_venues as $existing_name => $term_id ) {
			$existing_without_the = preg_replace( '/^the\s+/', '', $existing_name );

			// One contains the other.
			if ( str_contains( $normalized, $existing_without_the ) || str_contains( $existing_without_the, $without_the ) ) {
				// Only match if the shorter string is at least 4 chars (avoid false positives).
				$shorter = min( strlen( $without_the ), strlen( $existing_without_the ) );
				if ( $shorter >= 4 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Clean up Google's UTM-heavy website URLs.
	 *
	 * @param string $url Raw URL from Places API.
	 * @return string Cleaned URL.
	 */
	private function cleanWebsiteUrl( string $url ): string {
		if ( empty( $url ) ) {
			return '';
		}

		// Parse and rebuild without query params (Google often adds UTM tracking).
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return $url;
		}

		$clean = ( $parsed['scheme'] ?? 'https' ) . '://' . $parsed['host'];
		if ( ! empty( $parsed['path'] ) && '/' !== $parsed['path'] ) {
			$clean .= $parsed['path'];
		}

		return $clean;
	}
}
