<?php
/**
 * Flow location guard.
 *
 * Centralizes the rule: `taxonomy_location_selection` on an `upsert_event`
 * flow MUST be a concrete location term ID (as a string) or term-name
 * fallback. Values of `ai_decides`, empty string, or `skip` are not allowed
 * because `location` is a derived taxonomy — the market is determined by
 * the venue's city/state/zip, never by the AI.
 *
 * See Extra-Chill/extrachill-events#98 for full context. This guard is
 * defensive code in extrachill-events because data-machine does not (yet)
 * expose a `datamachine_flow_config_pre_save` filter. A follow-up issue
 * tracks adding that hook upstream; until then, every EC writer that
 * touches flow_config on an `upsert_event` flow must route through this
 * guard.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FlowLocationGuard {

	/**
	 * Values that are NOT acceptable for `taxonomy_location_selection` on
	 * an `upsert_event` flow. `ai_decides` is the main offender — see
	 * issue #98. Empty string and `skip` would also defeat the purpose
	 * (no location at all), so we coerce those too.
	 */
	private const REJECTED_VALUES = array( 'ai_decides', '', 'skip' );

	/**
	 * Walk a flow_config array and coerce any forbidden
	 * `taxonomy_location_selection` value on an `upsert_event` step to the
	 * caller-supplied pipeline location term.
	 *
	 * Returns a new config (does not mutate $config) plus a list of step
	 * IDs that were coerced. Caller decides whether to write the result
	 * back and how to surface the warning.
	 *
	 * If $pipeline_location_term is empty (i.e. the pipeline doesn't
	 * resolve to a market term), no coercion happens — the caller gets
	 * the original config and an empty coerced list. That avoids
	 * stamping a bogus value when there's nothing better to substitute.
	 *
	 * @param array  $config                  Flow config (associative,
	 *                                        step_id => step_data).
	 * @param string $pipeline_location_term  Term ID string (preferred)
	 *                                        or term name, sourced from
	 *                                        the pipeline this flow
	 *                                        belongs to.
	 * @param callable|null $should_replace         Optional predicate
	 *                                             `fn( string $current ): bool`
	 *                                             deciding which values get
	 *                                             replaced. Defaults to
	 *                                             `isRejectedValue()`; repair
	 *                                             passes a wider predicate so
	 *                                             stale/ambiguous names are
	 *                                             rewritten too.
	 * @return array{config:array,coerced:array<int,array{step_id:string,old:string,new:string}>}
	 */
	public static function coerceUpsertEventLocation( array $config, string $pipeline_location_term, ?callable $should_replace = null ): array {
		$coerced = array();

		if ( '' === $pipeline_location_term ) {
			return array(
				'config'  => $config,
				'coerced' => $coerced,
			);
		}

		foreach ( $config as $step_id => &$step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$handler_configs = $step['handler_configs'] ?? array();
			if ( ! is_array( $handler_configs ) || ! isset( $handler_configs['upsert_event'] ) ) {
				continue;
			}

			$upsert = $handler_configs['upsert_event'];
			if ( ! is_array( $upsert ) ) {
				continue;
			}

			$current = $upsert['taxonomy_location_selection'] ?? '';
			if ( ! is_string( $current ) ) {
				continue;
			}

			$replace = null === $should_replace ? self::isRejectedValue( $current ) : (bool) $should_replace( $current );
			if ( ! $replace || $current === $pipeline_location_term ) {
				continue;
			}

			$step['handler_configs']['upsert_event']['taxonomy_location_selection'] = $pipeline_location_term;

			$coerced[] = array(
				'step_id' => (string) $step_id,
				'old'     => $current,
				'new'     => $pipeline_location_term,
			);
		}
		unset( $step );

		return array(
			'config'  => $config,
			'coerced' => $coerced,
		);
	}

	/**
	 * True if $value would make `location` AI-decided / unset on an
	 * upsert_event flow and therefore must be rejected.
	 */
	public static function isRejectedValue( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), self::REJECTED_VALUES, true );
	}

	/**
	 * Resolve a pipeline's canonical location term, returned as a
	 * stringified term ID suitable for stamping into
	 * `taxonomy_location_selection`.
	 *
	 * Strategy:
	 * 1. Strip a trailing " Events" from the pipeline name. This matches
	 *    the convention every EC city pipeline uses ("Charleston Events",
	 *    "Pittsburgh Events", …).
	 * 2. Use a state-aware lookup (parent matches state abbreviation in
	 *    the name like "Wilmington NC Events") to handle disambiguated
	 *    pipeline names — see CityAbilities::ensureLocationTerm which
	 *    creates those.
	 * 3. Plain name lookup fallback.
	 *
	 * 3. When several terms share the name and the pipeline name carries
	 *    no state, fall back to the pipeline's own coordinates (e.g. the
	 *    Ticketmaster flow's `location`) and pick the single candidate
	 *    whose `_location_coordinates` sit within range.
	 *
	 * Returns '' if no term can be resolved — caller treats that as
	 * "skip this flow, the operator must fix it manually".
	 *
	 * @param string     $pipeline_name Pipeline name ("Northampton MA Events").
	 * @param array|null $coordinates   Optional pipeline coordinates
	 *                                  `array{lat:float,lon:float}`.
	 */
	public static function resolvePipelineLocationTermId( string $pipeline_name, ?array $coordinates = null ): string {
		if ( ! taxonomy_exists( 'location' ) ) {
			return '';
		}

		$pipeline_name = trim( $pipeline_name );
		if ( '' === $pipeline_name ) {
			return '';
		}

		// Strip trailing " Events" suffix (case-insensitive).
		$base = preg_replace( '/\s+Events\s*$/i', '', $pipeline_name );
		$base = is_string( $base ) ? trim( $base ) : '';
		if ( '' === $base ) {
			return '';
		}

		// Detect a disambiguated pipeline name like "Wilmington NC" —
		// the last token is a US state abbreviation. We use that to
		// pick the right Wilmington (NC vs DE).
		$state_abbr = '';
		$city       = $base;
		if ( preg_match( '/^(.+)\s+([A-Z]{2})$/', $base, $m ) ) {
			$city       = trim( $m[1] );
			$state_abbr = strtoupper( $m[2] );
		}

		// Build a list of candidate location terms with this name.
		$candidates = get_terms(
			array(
				'taxonomy'   => 'location',
				'name'       => $city,
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( is_wp_error( $candidates ) || empty( $candidates ) ) {
			return '';
		}

		$picked = self::pickLocationCandidate(
			$candidates,
			$state_abbr,
			$coordinates,
			static function ( $candidate ): string {
				if ( (int) $candidate->parent <= 0 ) {
					return '';
				}
				$parent = get_term( (int) $candidate->parent, 'location' );
				return ( $parent && ! is_wp_error( $parent ) ) ? (string) $parent->name : '';
			},
			static function ( $candidate ): ?array {
				return self::parseCoordinates( (string) get_term_meta( (int) $candidate->term_id, '_location_coordinates', true ) );
			}
		);

		return $picked instanceof \WP_Term ? (string) $picked->term_id : '';
	}

	/**
	 * Maximum distance (km) between pipeline coordinates and a location
	 * term's coordinates for the coordinate fallback to accept it.
	 */
	private const COORDINATE_MATCH_KM = 50.0;

	/**
	 * Pick one location term from same-named candidates.
	 *
	 * Pure decision logic so it can be unit tested without WordPress:
	 * a single candidate wins outright; otherwise a state abbreviation
	 * matching the candidate's parent (abbreviation or full name) wins;
	 * otherwise the unique candidate within COORDINATE_MATCH_KM of the
	 * supplied coordinates wins. Anything else is ambiguous → null.
	 *
	 * @param array<int,object> $candidates   Term-like objects (term_id, parent).
	 * @param string            $state_abbr   Two-letter state or ''.
	 * @param array|null        $coordinates  array{lat:float,lon:float} or null.
	 * @param callable          $parent_name  fn( object $candidate ): string.
	 * @param callable          $coords_of    fn( object $candidate ): ?array.
	 * @return object|null
	 */
	public static function pickLocationCandidate( array $candidates, string $state_abbr, ?array $coordinates, callable $parent_name, callable $coords_of ) {
		$candidates = array_values( $candidates );
		if ( 1 === count( $candidates ) ) {
			return $candidates[0];
		}
		if ( empty( $candidates ) ) {
			return null;
		}

		if ( '' !== $state_abbr ) {
			$state_full = self::stateAbbreviationToName( $state_abbr );
			foreach ( $candidates as $candidate ) {
				$parent = trim( (string) $parent_name( $candidate ) );
				if ( '' === $parent ) {
					continue;
				}
				if ( ( '' !== $state_full && 0 === strcasecmp( $parent, $state_full ) ) || 0 === strcasecmp( $parent, $state_abbr ) ) {
					return $candidate;
				}
			}
		}

		if ( null !== $coordinates && isset( $coordinates['lat'], $coordinates['lon'] ) ) {
			$within = array();
			foreach ( $candidates as $candidate ) {
				$point = $coords_of( $candidate );
				if ( ! is_array( $point ) || ! isset( $point['lat'], $point['lon'] ) ) {
					continue;
				}
				if ( self::distanceKm( (float) $coordinates['lat'], (float) $coordinates['lon'], (float) $point['lat'], (float) $point['lon'] ) <= self::COORDINATE_MATCH_KM ) {
					$within[] = $candidate;
				}
			}
			if ( 1 === count( $within ) ) {
				return $within[0];
			}
		}

		return null;
	}

	/**
	 * Strictly resolve a `taxonomy_location_selection` value to one term.
	 *
	 * A positive numeric string resolves by ID. A name resolves only when
	 * exactly one location term carries it — a name shared by several
	 * terms (Portland OR / Portland ME) is ambiguous and returns null.
	 * This is the single resolver shared by venue onboarding and flow
	 * repair so the two can never disagree about what "resolved" means.
	 *
	 * @param mixed $value Stored selection value.
	 * @return \WP_Term|null
	 */
	public static function resolveLocationValue( $value ): ?\WP_Term {
		$value = is_int( $value ) || is_string( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return null;
		}

		if ( ctype_digit( $value ) && (int) $value > 0 ) {
			$term = get_term( (int) $value, 'location' );
			return $term instanceof \WP_Term ? $term : null;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'location',
				'name'       => $value,
				'hide_empty' => false,
				'number'     => 2,
			)
		);

		if ( is_wp_error( $terms ) || 1 !== count( $terms ) ) {
			return null;
		}

		return $terms[0];
	}

	/**
	 * Classify a stored selection value for repair.
	 *
	 * Pure: lookups are injected. Returns '' when the value is clean
	 * (a real term ID, or a name matching exactly one term) or a reason
	 * code when it must be rewritten.
	 *
	 * @param string   $current           Stored value.
	 * @param callable $term_id_exists    fn( int $term_id ): bool.
	 * @param callable $name_match_count  fn( string $name ): int.
	 * @return string '' | 'rejected_value' | 'missing_term' | 'stale_name' | 'ambiguous_name'
	 */
	public static function classifySelectionValue( string $current, callable $term_id_exists, callable $name_match_count ): string {
		if ( self::isRejectedValue( $current ) ) {
			return 'rejected_value';
		}

		$current = trim( $current );
		if ( ctype_digit( $current ) ) {
			return $term_id_exists( (int) $current ) ? '' : 'missing_term';
		}

		$count = (int) $name_match_count( $current );
		if ( 0 === $count ) {
			return 'stale_name';
		}

		return $count > 1 ? 'ambiguous_name' : '';
	}

	/**
	 * Read Ticketmaster `location` coordinates ("lat,lon") from a flow
	 * config. City pipelines stamp their market center there, so it is
	 * the pipeline's own record of where it is.
	 *
	 * @param array $flow_config Decoded flow config.
	 * @return array|null array{lat:float,lon:float}
	 */
	public static function extractPipelineCoordinates( array $flow_config ): ?array {
		foreach ( $flow_config as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$location = $step['handler_configs']['ticketmaster']['location'] ?? '';
			$parsed   = is_string( $location ) ? self::parseCoordinates( $location ) : null;
			if ( null !== $parsed ) {
				return $parsed;
			}
		}
		return null;
	}

	/**
	 * Parse a "lat,lon" string.
	 *
	 * @param string $value Coordinate string.
	 * @return array|null array{lat:float,lon:float}
	 */
	public static function parseCoordinates( string $value ): ?array {
		if ( ! preg_match( '/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $value, $m ) ) {
			return null;
		}
		$lat = (float) $m[1];
		$lon = (float) $m[2];
		if ( 0.0 === $lat && 0.0 === $lon ) {
			return null;
		}
		return array(
			'lat' => $lat,
			'lon' => $lon,
		);
	}

	/**
	 * Great-circle distance in kilometres.
	 *
	 * @param float $lat1 First latitude.
	 * @param float $lon1 First longitude.
	 * @param float $lat2 Second latitude.
	 * @param float $lon2 Second longitude.
	 */
	private static function distanceKm( float $lat1, float $lon1, float $lat2, float $lon2 ): float {
		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lon = deg2rad( $lon2 - $lon1 );
		$a     = sin( $d_lat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lon / 2 ) ** 2;
		return 6371.0 * 2 * asin( min( 1.0, sqrt( $a ) ) );
	}

	/**
	 * Minimal US state abbreviation → full name map, intentionally
	 * duplicated from EventLocationAlignmentAbilities so this guard
	 * doesn't depend on that ability's load order.
	 */
	private static function stateAbbreviationToName( string $abbr ): string {
		static $map = array(
			'AL' => 'Alabama',
			'AK' => 'Alaska',
			'AZ' => 'Arizona',
			'AR' => 'Arkansas',
			'CA' => 'California',
			'CO' => 'Colorado',
			'CT' => 'Connecticut',
			'DE' => 'Delaware',
			'DC' => 'District of Columbia',
			'FL' => 'Florida',
			'GA' => 'Georgia',
			'HI' => 'Hawaii',
			'ID' => 'Idaho',
			'IL' => 'Illinois',
			'IN' => 'Indiana',
			'IA' => 'Iowa',
			'KS' => 'Kansas',
			'KY' => 'Kentucky',
			'LA' => 'Louisiana',
			'ME' => 'Maine',
			'MD' => 'Maryland',
			'MA' => 'Massachusetts',
			'MI' => 'Michigan',
			'MN' => 'Minnesota',
			'MS' => 'Mississippi',
			'MO' => 'Missouri',
			'MT' => 'Montana',
			'NE' => 'Nebraska',
			'NV' => 'Nevada',
			'NH' => 'New Hampshire',
			'NJ' => 'New Jersey',
			'NM' => 'New Mexico',
			'NY' => 'New York',
			'NC' => 'North Carolina',
			'ND' => 'North Dakota',
			'OH' => 'Ohio',
			'OK' => 'Oklahoma',
			'OR' => 'Oregon',
			'PA' => 'Pennsylvania',
			'RI' => 'Rhode Island',
			'SC' => 'South Carolina',
			'SD' => 'South Dakota',
			'TN' => 'Tennessee',
			'TX' => 'Texas',
			'UT' => 'Utah',
			'VT' => 'Vermont',
			'VA' => 'Virginia',
			'WA' => 'Washington',
			'WV' => 'West Virginia',
			'WI' => 'Wisconsin',
			'WY' => 'Wyoming',
		);

		return $map[ strtoupper( $abbr ) ] ?? '';
	}
}
