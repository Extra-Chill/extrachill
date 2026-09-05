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
							'city'         => array( 'type' => 'string' ),
							'total_found'  => array( 'type' => 'integer' ),
							'new_venues'   => array( 'type' => 'integer' ),
							'known_venues' => array( 'type' => 'integer' ),
							'venues'       => array( 'type' => 'array' ),
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

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Execute venue discovery.
	 *
	 * @param array $input Discovery parameters.
	 * @return array Results with venue list.
	 */
	public function executeDiscoverVenues( array $input ): array|\WP_Error {
		$city          = sanitize_text_field( $input['city'] ?? '' );
		$custom_query  = sanitize_text_field( $input['query'] ?? '' );
		$include_known = ! empty( $input['include_known'] );

		if ( empty( $city ) ) {
			return new \WP_Error( 'missing_city', 'City is required.', array( 'status' => 400 ) );
		}

		$query = $this->buildSearchQuery( $city, $custom_query );

		// Get access token.
		$token = $this->accessToken();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// Query Google Places.
		$places = $this->searchPlaces( $token, $query );
		if ( is_wp_error( $places ) ) {
			return $places;
		}

		// Get existing venue terms for cross-reference.
		$existing_venues = $this->getExistingVenueNames();

		// Classify results.
		$venues      = array();
		$new_count   = 0;
		$known_count = 0;
		$total_found = 0;

		foreach ( $places as $place ) {
			$name               = $place['displayName']['text'] ?? '';
			$address_components = $this->extractAddressComponents( $place );
			$address            = ! empty( $address_components['address'] ) ? $address_components['address'] : ( $place['formattedAddress'] ?? '' );
			$website            = $place['websiteUri'] ?? '';
			$lat                = $place['location']['latitude'] ?? null;
			$lng                = $place['location']['longitude'] ?? null;
			$types              = $place['types'] ?? array();
			$maps               = $place['googleMapsUri'] ?? '';

			if ( empty( $name ) || ! $this->matchesRequestedLocation( $city, $address_components ) ) {
				continue;
			}

			++$total_found;

			// Clean up Google's UTM-heavy website URLs.
			$website = $this->cleanWebsiteUrl( $website );

			// Check if venue already exists in taxonomy.
			$known_term_id = $this->findKnownVenueId( $name, $existing_venues );
			$is_known      = $known_term_id > 0;

			if ( $is_known ) {
				++$known_count;
				if ( ! $include_known ) {
					continue;
				}
			} else {
				++$new_count;
			}

			$venues[] = array(
				'name'          => $name,
				'address'       => $address,
				'city'          => $address_components['city'] ?? '',
				'state'         => $address_components['state'] ?? '',
				'zip'           => $address_components['zip'] ?? '',
				'country'       => $address_components['country'] ?? '',
				'website'       => $website,
				'latitude'      => $lat,
				'longitude'     => $lng,
				'types'         => $types,
				'maps_url'      => $maps,
				'is_known'      => $is_known,
				'known_term_id' => $known_term_id,
			);
		}

		return array(
			'city'         => $city,
			'query'        => $query,
			'total_found'  => $total_found,
			'new_venues'   => $new_count,
			'known_venues' => $known_count,
			'venues'       => $venues,
		);
	}

	/**
	 * Build a Places query that cannot discard the requested city.
	 *
	 * @param string $city         Requested city and optional state.
	 * @param string $custom_query Optional caller-provided search terms.
	 * @return string Location-aware Places query.
	 */
	private function buildSearchQuery( string $city, string $custom_query ): string {
		if ( '' === $custom_query ) {
			return "music venues in {$city}";
		}

		$requested_city = trim( explode( ',', $city, 2 )[0] );
		if ( '' !== $requested_city && false !== stripos( $custom_query, $requested_city ) ) {
			return $custom_query;
		}

		return "{$custom_query} in {$city}";
	}

	/**
	 * Check a Places result against the requested city and optional state.
	 *
	 * @param string $requested_location Requested city and optional state.
	 * @param array  $address_components Canonical Places address fields.
	 * @return bool Whether the result belongs to the requested location.
	 */
	private function matchesRequestedLocation( string $requested_location, array $address_components ): bool {
		$requested_parts = array_map( 'trim', explode( ',', $requested_location, 2 ) );
		$requested_city  = $requested_parts[0] ?? '';
		$requested_state = $requested_parts[1] ?? '';
		$result_city     = (string) ( $address_components['city'] ?? '' );

		if ( '' === $requested_city || '' === $result_city || $this->normalizeLocationPart( $requested_city ) !== $this->normalizeLocationPart( $result_city ) ) {
			return false;
		}

		if ( '' === $requested_state ) {
			return true;
		}

		return $this->normalizeState( $requested_state ) === $this->normalizeState( (string) ( $address_components['state'] ?? '' ) );
	}

	/**
	 * Normalize state abbreviations and full names for comparison.
	 *
	 * @param string $state State abbreviation or full name.
	 * @return string Normalized state identifier.
	 */
	private function normalizeState( string $state ): string {
		$state = trim( $state );
		if ( function_exists( 'extrachill_events_get_state_abbreviation_map' ) ) {
			$state = extrachill_events_get_state_abbreviation_map()[ strtoupper( $state ) ] ?? $state;
		}

		return $this->normalizeLocationPart( $state );
	}

	/**
	 * Obtain a Places access token.
	 *
	 * Delegates to the shared service account provider, which owns JWT
	 * assembly, RS256 signing, the token exchange, and network-wide caching.
	 * The credential is read from the Analytics option this ability has always
	 * used, so existing installs keep working.
	 *
	 * @return string|\WP_Error Access token, or a failure.
	 */
	private function accessToken() {
		if ( ! class_exists( '\DataMachineBusiness\OAuth\Providers\GoogleServiceAccountAuth' ) ) {
			return new \WP_Error(
				'venue_discovery_provider_unavailable',
				'Google service account authentication is unavailable. Activate Data Machine Business.'
			);
		}

		$provider = new \DataMachineBusiness\OAuth\Providers\GoogleServiceAccountAuth( self::CONFIG_OPTION );

		return $provider->get_access_token( self::SCOPE );
	}

	/**
	 * Normalize a location component without requiring WordPress formatting APIs.
	 *
	 * @param string $value Location component.
	 * @return string Lowercase alphanumeric identifier.
	 */
	private function normalizeLocationPart( string $value ): string {
		return preg_replace( '/[^a-z0-9]+/', '', strtolower( $value ) ) ?? '';
	}


	/**
	 * Search Google Places API for venues.
	 *
	 * @param string $token  Access token.
	 * @param string $query  Search query.
	 * @return array|\WP_Error Array of place results or error.
	 */
	private function searchPlaces( string $token, string $query ) {
		$response = wp_remote_post(
			self::PLACES_API_URL,
			array(
				'headers' => array(
					'Authorization'    => 'Bearer ' . $token,
					'Content-Type'     => 'application/json',
					'X-Goog-FieldMask' => implode(
						',',
						array(
							'places.displayName',
							'places.formattedAddress',
							'places.addressComponents',
							'places.websiteUri',
							'places.types',
							'places.location',
							'places.googleMapsUri',
						)
					),
				),
				'body'    => wp_json_encode(
					array(
						'textQuery'      => $query,
						'maxResultCount' => self::MAX_RESULTS,
					)
				),
			)
		);

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
		$terms = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
				'fields'     => 'id=>name',
			)
		);

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
	 * @return int Matched venue term ID, or zero when unknown.
	 */
	private function findKnownVenueId( string $name, array $existing_venues ): int {
		$normalized = strtolower( trim( $name ) );

		// Exact match.
		if ( isset( $existing_venues[ $normalized ] ) ) {
			return (int) $existing_venues[ $normalized ];
		}

		// Without "The" prefix.
		$without_the = preg_replace( '/^the\s+/i', '', $normalized );
		if ( $without_the !== $normalized && isset( $existing_venues[ $without_the ] ) ) {
			return (int) $existing_venues[ $without_the ];
		}

		// Check if any existing venue starts with the same name (handle "Exit/In" vs "EXIT/IN Nashville").
		foreach ( $existing_venues as $existing_name => $term_id ) {
			$existing_without_the = preg_replace( '/^the\s+/', '', $existing_name );

			// One contains the other.
			if ( str_contains( $normalized, $existing_without_the ) || str_contains( $existing_without_the, $without_the ) ) {
				$shorter_name = strlen( $without_the ) <= strlen( $existing_without_the ) ? $without_the : $existing_without_the;
				if ( $this->isDistinctiveVenueName( $shorter_name ) ) {
					return (int) $term_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Reject generic contained names that commonly occur in unrelated venues.
	 *
	 * @param string $name Shorter normalized venue name.
	 * @return bool Whether the name is distinctive enough for containment matching.
	 */
	private function isDistinctiveVenueName( string $name ): bool {
		$split  = preg_split( '/[^a-z0-9]+/', $name );
		$tokens = array_values( array_filter( false === $split ? array() : $split ) );
		if ( count( $tokens ) > 1 ) {
			return true;
		}

		$token = $tokens[0] ?? '';
		return strlen( $token ) >= 4 && ! in_array( $token, array( 'bar', 'club', 'hall', 'live', 'music', 'rock', 'room', 'stage', 'theater', 'theatre', 'venue' ), true );
	}

	/** Extract canonical venue location fields from Places address components. */
	private function extractAddressComponents( array $place ): array {
		$parts = array();
		foreach ( (array) ( $place['addressComponents'] ?? array() ) as $component ) {
			foreach ( (array) ( $component['types'] ?? array() ) as $type ) {
				$parts[ $type ] = $component;
			}
		}

		$long   = static fn( string $type ): string => sanitize_text_field( (string) ( $parts[ $type ]['longText'] ?? '' ) );
		$short  = static fn( string $type ): string => sanitize_text_field( (string) ( $parts[ $type ]['shortText'] ?? $parts[ $type ]['longText'] ?? '' ) );
		$street = trim( $long( 'street_number' ) . ' ' . $long( 'route' ) );
		$city   = $long( 'locality' );
		if ( '' === $city ) {
			$city = $long( 'postal_town' );
		}

		return array(
			'address' => $street,
			'city'    => $city,
			'state'   => $short( 'administrative_area_level_1' ),
			'zip'     => $long( 'postal_code' ),
			'country' => $short( 'country' ),
		);
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
