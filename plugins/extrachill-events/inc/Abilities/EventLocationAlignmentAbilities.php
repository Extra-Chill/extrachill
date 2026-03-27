<?php
/**
 * Event Location Alignment Abilities
 *
 * Audits and repairs event location taxonomy assignments so they match the
 * venue city term. Includes source flow diagnostics to help investigate
 * historical import corruption from older Data Machine race conditions.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use DataMachine\Core\Database\Flows\Flows;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventLocationAlignmentAbilities {

	private static bool $registered = false;

	/**
	 * Cached map of lowercase city name => matching location terms.
	 *
	 * @var array<string, array<int, \WP_Term>>|null
	 */
	private ?array $location_terms_by_name = null;

	/**
	 * Cached flow location terms by flow ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $flow_location_cache = array();

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/reconcile-event-locations',
			array(
				'label'               => __( 'Reconcile Event Locations', 'extrachill-events' ),
				'description'         => __( 'Audit or repair event location taxonomy assignments so they align with the venue city.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'apply'           => array(
							'type'        => 'boolean',
							'description' => __( 'Whether to apply fixes. Defaults to false for audit mode.', 'extrachill-events' ),
							'default'     => false,
						),
						'post_ids'        => array(
							'type'        => 'array',
							'description' => __( 'Optional specific event post IDs to audit.', 'extrachill-events' ),
							'items'       => array(
								'type' => 'integer',
							),
						),
						'limit'           => array(
							'type'        => 'integer',
							'description' => __( 'Maximum events to scan. Use 0 for all.', 'extrachill-events' ),
							'default'     => 500,
						),
						'offset'          => array(
							'type'        => 'integer',
							'description' => __( 'Offset for batched audits.', 'extrachill-events' ),
							'default'     => 0,
						),
						'include_matches' => array(
							'type'        => 'boolean',
							'description' => __( 'Include already-correct events in the returned results.', 'extrachill-events' ),
							'default'     => false,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'checked_count'    => array( 'type' => 'integer' ),
						'mismatch_count'   => array( 'type' => 'integer' ),
						'fixed_count'      => array( 'type' => 'integer' ),
						'unresolved_count' => array( 'type' => 'integer' ),
						'results'          => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'post_id'                 => array( 'type' => 'integer' ),
									'title'                   => array( 'type' => 'string' ),
									'venue'                   => array( 'type' => 'string' ),
									'venue_city'              => array( 'type' => 'string' ),
									'assigned_location'       => array( 'type' => 'string' ),
									'expected_location'       => array( 'type' => 'string' ),
									'flow_id'                 => array( 'type' => 'integer' ),
									'flow_config_location'    => array( 'type' => 'string' ),
									'status'                  => array( 'type' => 'string' ),
									'reason'                  => array( 'type' => 'string' ),
								),
							),
						),
						'message'          => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeReconcileEventLocations' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'     => false,
						'idempotent'   => true,
						'destructive'  => false,
						'instructions' => __( 'Audit or repair event location terms using venue city as the canonical source. Includes source flow diagnostics for historical mismatch debugging.', 'extrachill-events' ),
					),
				),
			)
		);
	}

	/**
	 * Execute the event location reconciliation ability.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function executeReconcileEventLocations( array $input ) {
		if ( ! class_exists( 'DataMachine\Core\Database\Flows\Flows' ) ) {
			return new \WP_Error(
				'missing_data_machine',
				__( 'Data Machine Flows repository is not available.', 'extrachill-events' ),
				array( 'status' => 500 )
			);
		}

		$apply           = ! empty( $input['apply'] );
		$include_matches = ! empty( $input['include_matches'] );
		$limit           = isset( $input['limit'] ) ? max( 0, (int) $input['limit'] ) : 500;
		$offset          = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
		$post_ids        = array_values(
			array_filter(
				array_map( 'absint', $input['post_ids'] ?? array() )
			)
		);

		$event_ids = $this->getEventIdsToScan( $post_ids, $limit, $offset );
		$db_flows  = new Flows();

		$checked_count    = 0;
		$mismatch_count   = 0;
		$fixed_count      = 0;
		$unresolved_count = 0;
		$results          = array();

		foreach ( $event_ids as $post_id ) {
			$checked_count++;
			$event_result = $this->inspectEventLocation( $post_id, $db_flows, $apply );

			if ( 'mismatch' === $event_result['status'] ) {
				$mismatch_count++;
			} elseif ( 'fixed' === $event_result['status'] ) {
				$mismatch_count++;
				$fixed_count++;
			} elseif ( 'unresolved' === $event_result['status'] ) {
				$unresolved_count++;
			}

			if ( $include_matches || 'match' !== $event_result['status'] ) {
				$results[] = $event_result;
			}
		}

		$mode = $apply ? __( 'fix', 'extrachill-events' ) : __( 'audit', 'extrachill-events' );

		return array(
			'checked_count'    => $checked_count,
			'mismatch_count'   => $mismatch_count,
			'fixed_count'      => $fixed_count,
			'unresolved_count' => $unresolved_count,
			'results'          => $results,
			'message'          => sprintf(
				/* translators: 1: mode, 2: checked count, 3: mismatch count, 4: fixed count, 5: unresolved count */
				__( 'Event location %1$s complete. Checked %2$d events, found %3$d mismatches, fixed %4$d, unresolved %5$d.', 'extrachill-events' ),
				$mode,
				$checked_count,
				$mismatch_count,
				$fixed_count,
				$unresolved_count
			),
		);
	}

	/**
	 * Get the event IDs to scan.
	 *
	 * @param array $post_ids Explicit event IDs.
	 * @param int   $limit    Maximum number of posts. 0 means all.
	 * @param int   $offset   Result offset.
	 * @return array<int>
	 */
	private function getEventIdsToScan( array $post_ids, int $limit, int $offset ): array {
		if ( ! empty( $post_ids ) ) {
			return $post_ids;
		}

		$query_args = array(
			'post_type'      => 'data_machine_events',
			'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'offset'         => $offset,
			'posts_per_page' => 0 === $limit ? -1 : $limit,
			'no_found_rows'  => true,
		);

		return get_posts( $query_args );
	}

	/**
	 * Inspect and optionally repair one event location assignment.
	 *
	 * @param int   $post_id  Event post ID.
	 * @param Flows $db_flows Data Machine flows repository.
	 * @param bool  $apply    Whether to apply the fix.
	 * @return array<string, mixed>
	 */
	private function inspectEventLocation( int $post_id, Flows $db_flows, bool $apply ): array {
		$post = get_post( $post_id );
		if ( ! $post || 'data_machine_events' !== $post->post_type ) {
			return array(
				'post_id'              => $post_id,
				'title'                => '',
				'venue'                => '',
				'venue_city'           => '',
				'assigned_location'    => '',
				'expected_location'    => '',
				'flow_id'              => 0,
				'flow_config_location' => '',
				'status'               => 'unresolved',
				'reason'               => 'event_not_found',
			);
		}

		$venue_terms        = wp_get_object_terms( $post_id, 'venue' );
		$current_locations  = wp_get_object_terms( $post_id, 'location' );
		$flow_id            = absint( get_post_meta( $post_id, '_datamachine_post_flow_id', true ) );
		$flow_location      = $this->getFlowLocationTerm( $flow_id, $db_flows );
		$current_location_ids = array();
		$current_location_names = array();

		if ( ! is_wp_error( $current_locations ) ) {
			$current_location_ids   = wp_list_pluck( $current_locations, 'term_id' );
			$current_location_names = wp_list_pluck( $current_locations, 'name' );
		}

		if ( empty( $venue_terms ) || is_wp_error( $venue_terms ) ) {
			return array(
				'post_id'              => $post_id,
				'title'                => $post->post_title,
				'venue'                => '',
				'venue_city'           => '',
				'assigned_location'    => implode( ', ', $current_location_names ),
				'expected_location'    => '',
				'flow_id'              => $flow_id,
				'flow_config_location' => $flow_location['name'],
				'status'               => 'unresolved',
				'reason'               => 'missing_venue_term',
			);
		}

		$venue       = $venue_terms[0];
		$venue_city  = trim( (string) get_term_meta( $venue->term_id, '_venue_city', true ) );
		$venue_state = trim( (string) get_term_meta( $venue->term_id, '_venue_state', true ) );
		$venue_zip   = trim( (string) get_term_meta( $venue->term_id, '_venue_zip', true ) );
		$expected    = $this->resolveExpectedLocationTerm( $venue_city, $venue_state, $venue_zip, $flow_location['term'] );

		if ( ! $expected['term'] ) {
			return array(
				'post_id'              => $post_id,
				'title'                => $post->post_title,
				'venue'                => $venue->name,
				'venue_city'           => $venue_city,
				'assigned_location'    => implode( ', ', $current_location_names ),
				'expected_location'    => '',
				'flow_id'              => $flow_id,
				'flow_config_location' => $flow_location['name'],
				'status'               => 'unresolved',
				'reason'               => $expected['reason'],
			);
		}

		$expected_term = $expected['term'];
		if ( in_array( $expected_term->term_id, $current_location_ids, true ) ) {
			return array(
				'post_id'              => $post_id,
				'title'                => $post->post_title,
				'venue'                => $venue->name,
				'venue_city'           => $venue_city,
				'assigned_location'    => implode( ', ', $current_location_names ),
				'expected_location'    => $expected_term->name,
				'flow_id'              => $flow_id,
				'flow_config_location' => $flow_location['name'],
				'status'               => 'match',
				'reason'               => 'already_aligned',
			);
		}

		$status = 'mismatch';
		$reason = 'venue_city_mismatch';

		if ( $apply ) {
			$set_result = wp_set_object_terms( $post_id, array( $expected_term->term_id ), 'location', false );
			if ( ! is_wp_error( $set_result ) ) {
				$status = 'fixed';
				$reason = 'location_updated';
			} else {
				$status = 'unresolved';
				$reason = 'term_update_failed';
			}
		}

		return array(
			'post_id'              => $post_id,
			'title'                => $post->post_title,
			'venue'                => $venue->name,
			'venue_city'           => $venue_city,
			'assigned_location'    => implode( ', ', $current_location_names ),
			'expected_location'    => $expected_term->name,
			'flow_id'              => $flow_id,
			'flow_config_location' => $flow_location['name'],
			'status'               => $status,
			'reason'               => $reason,
		);
	}

	/**
	 * Resolve the canonical market/location term for a venue.
	 *
	 * Resolution order (venue city is ground truth, flow config is fallback):
	 * 1. Market mapping (NYC zip rules + city→market rollup) — e.g. Cambridge → Boston
	 * 2. Exact location term name match — e.g. venue city "Charleston" → Charleston term
	 * 3. Flow-configured location term — only when venue city has no mapping
	 *
	 * This ensures that a Ticketmaster flow with a 50mi radius centered on Worcester
	 * does not override the actual venue location for Boston-area events.
	 *
	 * @param string        $venue_city  Venue city name.
	 * @param string        $venue_state Venue state name.
	 * @param string        $venue_zip   Venue zip code.
	 * @param \WP_Term|null $flow_term   Flow-configured location term.
	 * @return array{term: \WP_Term|null, reason: string}
	 */
	private function resolveExpectedLocationTerm( string $venue_city, string $venue_state, string $venue_zip, ?\WP_Term $flow_term ): array {
		if ( '' === $venue_city ) {
			// No venue city — fall back to flow config if available.
			if ( $flow_term ) {
				return array(
					'term'   => $flow_term,
					'reason' => 'fallback_flow_location_no_venue_city',
				);
			}
			return array(
				'term'   => null,
				'reason' => 'missing_venue_city',
			);
		}

		// 1. Market mapping — venue city maps to a canonical market.
		$market_slug = extrachill_events_get_market_slug_for_venue( $venue_city, $venue_state, $venue_zip );
		if ( $market_slug ) {
			$market_term = get_term_by( 'slug', $market_slug, 'location' );
			if ( $market_term && ! is_wp_error( $market_term ) ) {
				return array(
					'term'   => $market_term,
					'reason' => 'matched_market_mapping',
				);
			}
		}

		// 2. Exact location term name match — venue city directly matches a location term.
		$city_key = strtolower( $venue_city );
		$matches  = $this->getLocationTermsByName()[ $city_key ] ?? array();

		if ( 1 === count( $matches ) ) {
			return array(
				'term'   => reset( $matches ),
				'reason' => 'matched_venue_city',
			);
		}

		if ( count( $matches ) > 1 ) {
			// Disambiguate using venue state — match against parent term (state level).
			$resolved = $this->disambiguateByState( $matches, $venue_state );
			if ( $resolved ) {
				return array(
					'term'   => $resolved,
					'reason' => 'matched_venue_city_state',
				);
			}

			return array(
				'term'   => null,
				'reason' => 'ambiguous_location_term',
			);
		}

		// 3. No venue-based resolution — fall back to flow config.
		if ( $flow_term ) {
			return array(
				'term'   => $flow_term,
				'reason' => 'fallback_flow_location',
			);
		}

		return array(
			'term'   => null,
			'reason' => 'location_term_not_found',
		);
	}

	/**
	 * Disambiguate multiple location terms with the same city name using venue state.
	 *
	 * Each location term has a parent hierarchy: Country > State > City.
	 * Compares the venue's state (abbreviation or full name) against the parent
	 * term name to find the correct match.
	 *
	 * @param array<int, \WP_Term> $matches     Location terms sharing the same city name.
	 * @param string               $venue_state Venue state (abbreviation like "SC" or full like "South Carolina").
	 * @return \WP_Term|null Matched term, or null if state doesn't resolve ambiguity.
	 */
	private function disambiguateByState( array $matches, string $venue_state ): ?\WP_Term {
		$venue_state = trim( $venue_state );
		if ( '' === $venue_state ) {
			return null;
		}

		$state_lower = strtolower( $venue_state );

		// Normalize abbreviation to full name for comparison.
		$abbrev_map = self::getStateAbbreviationMap();
		$full_name  = $abbrev_map[ strtoupper( $venue_state ) ] ?? null;

		foreach ( $matches as $match ) {
			if ( $match->parent <= 0 ) {
				continue;
			}

			$parent = get_term( $match->parent, 'location' );
			if ( ! $parent || is_wp_error( $parent ) ) {
				continue;
			}

			$parent_lower = strtolower( $parent->name );

			// Match full state name directly.
			if ( $parent_lower === $state_lower ) {
				return $match;
			}

			// Match abbreviation expanded to full name.
			if ( $full_name && strtolower( $full_name ) === $parent_lower ) {
				return $match;
			}
		}

		return null;
	}

	/**
	 * US state abbreviation → full name map.
	 *
	 * @return array<string, string>
	 */
	private static function getStateAbbreviationMap(): array {
		return array(
			'AL' => 'Alabama',        'AK' => 'Alaska',          'AZ' => 'Arizona',
			'AR' => 'Arkansas',        'CA' => 'California',      'CO' => 'Colorado',
			'CT' => 'Connecticut',     'DE' => 'Delaware',        'DC' => 'District of Columbia',
			'FL' => 'Florida',         'GA' => 'Georgia',         'HI' => 'Hawaii',
			'ID' => 'Idaho',           'IL' => 'Illinois',        'IN' => 'Indiana',
			'IA' => 'Iowa',            'KS' => 'Kansas',          'KY' => 'Kentucky',
			'LA' => 'Louisiana',       'ME' => 'Maine',           'MD' => 'Maryland',
			'MA' => 'Massachusetts',   'MI' => 'Michigan',        'MN' => 'Minnesota',
			'MS' => 'Mississippi',     'MO' => 'Missouri',        'MT' => 'Montana',
			'NE' => 'Nebraska',        'NV' => 'Nevada',          'NH' => 'New Hampshire',
			'NJ' => 'New Jersey',      'NM' => 'New Mexico',      'NY' => 'New York',
			'NC' => 'North Carolina',  'ND' => 'North Dakota',    'OH' => 'Ohio',
			'OK' => 'Oklahoma',        'OR' => 'Oregon',          'PA' => 'Pennsylvania',
			'RI' => 'Rhode Island',    'SC' => 'South Carolina',  'SD' => 'South Dakota',
			'TN' => 'Tennessee',       'TX' => 'Texas',           'UT' => 'Utah',
			'VT' => 'Vermont',         'VA' => 'Virginia',        'WA' => 'Washington',
			'WV' => 'West Virginia',   'WI' => 'Wisconsin',       'WY' => 'Wyoming',
		);
	}

	/**
	 * Get location terms grouped by lowercase name.
	 *
	 * @return array<string, array<int, \WP_Term>>
	 */
	private function getLocationTermsByName(): array {
		if ( null !== $this->location_terms_by_name ) {
			return $this->location_terms_by_name;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'location',
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		$this->location_terms_by_name = array();

		if ( is_wp_error( $terms ) ) {
			return $this->location_terms_by_name;
		}

		foreach ( $terms as $term ) {
			$key = strtolower( $term->name );
			if ( ! isset( $this->location_terms_by_name[ $key ] ) ) {
				$this->location_terms_by_name[ $key ] = array();
			}

			$this->location_terms_by_name[ $key ][] = $term;
		}

		return $this->location_terms_by_name;
	}

	/**
	 * Get the configured flow location term for a flow.
	 *
	 * @param int   $flow_id  Flow ID.
	 * @param Flows $db_flows Flows repository.
	 * @return array{name: string, term: \WP_Term|null}
	 */
	private function getFlowLocationTerm( int $flow_id, Flows $db_flows ): array {
		if ( $flow_id <= 0 ) {
			return array(
				'name' => '',
				'term' => null,
			);
		}

		if ( isset( $this->flow_location_cache[ $flow_id ] ) ) {
			return $this->flow_location_cache[ $flow_id ];
		}

		$flow = $db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			$this->flow_location_cache[ $flow_id ] = array(
				'name' => '',
				'term' => null,
			);

			return $this->flow_location_cache[ $flow_id ];
		}

		$location_value = null;

		foreach ( $flow['flow_config'] as $step ) {
			$handler_slugs = $step['handler_slugs'] ?? array();
			if ( ! in_array( 'upsert_event', $handler_slugs, true ) ) {
				continue;
			}

			$handler_config = $step['handler_configs']['upsert_event'] ?? array();
			if ( isset( $handler_config['taxonomy_location_selection'] ) ) {
				$location_value = $handler_config['taxonomy_location_selection'];
				break;
			}
		}

		$term = null;

		if ( is_numeric( $location_value ) ) {
			$term = get_term( (int) $location_value, 'location' );
		} elseif ( is_string( $location_value ) && '' !== $location_value && 'skip' !== $location_value && 'ai_decides' !== $location_value ) {
			$term = get_term_by( 'slug', $location_value, 'location' );
			if ( ! $term ) {
				$term = get_term_by( 'name', $location_value, 'location' );
			}
		}

		if ( is_wp_error( $term ) ) {
			$term = null;
		}

		$this->flow_location_cache[ $flow_id ] = array(
			'name' => $term ? $term->name : '',
			'term' => $term,
		);

		return $this->flow_location_cache[ $flow_id ];
	}
}
