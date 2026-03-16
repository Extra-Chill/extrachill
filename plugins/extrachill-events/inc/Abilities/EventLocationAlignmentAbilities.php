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

		$venue      = $venue_terms[0];
		$venue_city = trim( (string) get_term_meta( $venue->term_id, '_venue_city', true ) );
		$expected   = $this->resolveExpectedLocationTerm( $venue_city, $flow_location['term'] );

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
	 * Resolve the canonical location term for a venue city.
	 *
	 * @param string        $venue_city Venue city name.
	 * @param \WP_Term|null $flow_term  Flow-configured location term for tie-breaking.
	 * @return array{term: \WP_Term|null, reason: string}
	 */
	private function resolveExpectedLocationTerm( string $venue_city, ?\WP_Term $flow_term ): array {
		if ( '' === $venue_city ) {
			return array(
				'term'   => null,
				'reason' => 'missing_venue_city',
			);
		}

		$city_key = strtolower( $venue_city );
		$matches  = $this->getLocationTermsByName()[ $city_key ] ?? array();

		if ( empty( $matches ) ) {
			return array(
				'term'   => null,
				'reason' => 'location_term_not_found',
			);
		}

		if ( 1 === count( $matches ) ) {
			return array(
				'term'   => reset( $matches ),
				'reason' => 'matched_venue_city',
			);
		}

		if ( $flow_term ) {
			foreach ( $matches as $match ) {
				if ( (int) $match->term_id === (int) $flow_term->term_id ) {
					return array(
						'term'   => $match,
						'reason' => 'matched_flow_location',
					);
				}
			}
		}

		return array(
			'term'   => null,
			'reason' => 'ambiguous_location_term',
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
