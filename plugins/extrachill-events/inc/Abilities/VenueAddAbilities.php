<?php
/**
 * Venue Add Abilities
 *
 * Creates a universal_web_scraper flow for a venue within an existing city
 * pipeline. Handles venue taxonomy term creation, flow configuration,
 * and geocoding.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\DataMachineFlowGateway;
use ExtraChillEvents\Core\FlowLocationGuard;
use ExtraChillEvents\Core\TeamAuthorization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/Core/TeamAuthorization.php';
require_once dirname( __DIR__ ) . '/Core/DataMachineFlowGateway.php';
require_once dirname( __DIR__ ) . '/Core/EventSourceHandler.php';

class VenueAddAbilities {

	/**
	 * Default scheduling interval for venue scraper flows.
	 */
	private const DEFAULT_INTERVAL = 'daily';

	/**
	 * Default post author.
	 */
	private const DEFAULT_POST_AUTHOR = 32;

	private static bool $registered = false;

	/**
	 * Data Machine gateway. Runs flow writes as the system once this
	 * ability's own events-staff permission check has passed.
	 *
	 * @var DataMachineFlowGateway|null
	 */
	private ?DataMachineFlowGateway $flow_gateway = null;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'extrachill/add-venue',
				array(
					'label'               => __( 'Add Venue', 'extrachill-events' ),
					'description'         => __( 'Add a venue scraper flow to an existing city pipeline. Pass the city as location (term ID, slug, or name) or pass pipeline_id. Creates the venue taxonomy term and configures the universal web scraper.', 'extrachill-events' ),
					'category'            => 'extrachill-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'name', 'url' ),
						'properties' => array(
							'handler_slug' => array( 'type' => 'string' ),
							'handler_config' => array( 'type' => 'object' ),
							'pipeline_id'   => array(
								'type'        => 'integer',
								'description' => 'Pipeline ID for the city this venue belongs to. Optional when location is given; disambiguates a city with more than one pipeline. One of pipeline_id or location is required.',
							),
							'location'      => array(
								'type'        => array( 'integer', 'string' ),
								'description' => 'City this venue belongs to: location term ID, slug, or exact name (e.g. "Charleston"). Resolves the city event pipeline when pipeline_id is omitted. One of pipeline_id or location is required.',
							),
							'name'          => array(
								'type'        => 'string',
								'description' => 'Venue name, e.g. "Exit/In" or "The Station Inn".',
							),
							'venue_term_id' => array(
								'type'        => 'integer',
								'description' => 'Existing canonical venue term ID. Optional; prevents name-based duplication for known venues.',
							),
							'url'           => array(
								'type'        => 'string',
								'description' => 'Venue events page URL (the page the scraper will hit).',
							),
							'address'       => array(
								'type'        => 'string',
								'description' => 'Venue street address. Optional — used for geocoding.',
							),
							'city'          => array(
								'type'        => 'string',
								'description' => 'Venue city name. Optional — derived from pipeline if not provided.',
							),
							'state'         => array(
								'type'        => 'string',
								'description' => 'Venue state. Optional.',
							),
							'zip'           => array(
								'type'        => 'string',
								'description' => 'Venue zip code. Optional.',
							),
							'website'       => array(
								'type'        => 'string',
								'description' => 'Venue homepage URL (may differ from events page URL). Optional.',
							),
							'interval'      => array(
								'type'        => 'string',
								'description' => 'Scheduling interval. Defaults to "daily".',
							),
							'dry_run'       => array(
								'type'        => 'boolean',
								'description' => 'Preview what would be created without making changes.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'message'       => array( 'type' => 'string' ),
							'flow_id'       => array( 'type' => 'integer' ),
							'venue_term_id' => array( 'type' => 'integer' ),
							'pipeline_id'   => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => array( $this, 'executeAddVenue' ),
					'permission_callback' => array( TeamAuthorization::class, 'current_user_is_events_staff' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Execute add-venue.
	 *
	 * @param array $input Venue parameters.
	 * @return array|\WP_Error Result.
	 */
	public function executeAddVenue( array $input ) {
		$pipeline_id   = (int) ( $input['pipeline_id'] ?? 0 );
		$location_in   = $input['location'] ?? '';
		$location_in   = is_int( $location_in ) || is_string( $location_in ) ? sanitize_text_field( (string) $location_in ) : '';
		$name          = sanitize_text_field( $input['name'] ?? '' );
		$url           = esc_url_raw( $input['url'] ?? '' );
		$address       = sanitize_text_field( $input['address'] ?? '' );
		$city          = sanitize_text_field( $input['city'] ?? '' );
		$state         = sanitize_text_field( $input['state'] ?? '' );
		$zip           = sanitize_text_field( $input['zip'] ?? '' );
		$website       = esc_url_raw( $input['website'] ?? '' );
		$venue_term_id = (int) ( $input['venue_term_id'] ?? 0 );
		$interval      = sanitize_text_field( $input['interval'] ?? self::DEFAULT_INTERVAL );
		$dry_run       = ! empty( $input['dry_run'] );

		if ( $pipeline_id <= 0 && '' === $location_in ) {
			return new \WP_Error( 'missing_pipeline', 'Either pipeline_id or location is required.', array( 'status' => 400 ) );
		}
		if ( empty( $name ) ) {
			return new \WP_Error( 'missing_name', 'Venue name is required.', array( 'status' => 400 ) );
		}
		if ( empty( $url ) ) {
			return new \WP_Error( 'missing_url', 'Events page URL is required.', array( 'status' => 400 ) );
		}
		if ( $venue_term_id > 0 ) {
			$known_term = get_term( $venue_term_id, 'venue' );
			if ( ! $known_term || is_wp_error( $known_term ) ) {
				return new \WP_Error( 'venue_not_found', 'The supplied canonical venue term was not found.', array( 'status' => 404 ) );
			}
		}

		$source = ! empty( $input['handler_slug'] )
			? array( 'handler_slug' => sanitize_key( $input['handler_slug'] ), 'handler_config' => (array) ( $input['handler_config'] ?? array() ) )
			: \ExtraChillEvents\Core\EventSourceHandler::resolve( $url );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( \ExtraChillEvents\Core\EventSourceHandler::is_social( $source ) ) {
			$interval = 'daily';
		}

		$requested_location = null;
		if ( '' !== $location_in ) {
			$requested_location = $this->resolveLocationInput( $location_in );
			if ( is_wp_error( $requested_location ) ) {
				return $requested_location;
			}
		}

		if ( $pipeline_id <= 0 ) {
			$resolved = $this->findCityPipelineForLocation( $requested_location );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$pipeline_id = (int) $resolved['pipeline_id'];
		}

		$pipeline_context = $this->validateCityPipeline( $pipeline_id );
		if ( is_wp_error( $pipeline_context ) ) {
			return $pipeline_context;
		}
		if ( $requested_location && (int) $pipeline_context['location_term_id'] !== (int) $requested_location->term_id ) {
			return new \WP_Error(
				'pipeline_location_mismatch',
				sprintf( 'Pipeline %d imports events for %s, not %s.', $pipeline_id, $pipeline_context['location']->name, $requested_location->name ),
				array( 'status' => 400 )
			);
		}
		$pipeline = $pipeline_context['pipeline'];

		$pipeline_name = $pipeline['pipeline_name'] ?? '';
		$location_term = (string) $pipeline_context['location_term_id'];
		$location      = $pipeline_context['location'];

		// The canonical location binding owns city identity; pipeline names are display only.
		if ( empty( $city ) ) {
			$city = (string) $location->name;
		}

		// Check for idempotency — does a flow with this venue already exist in this pipeline?
		$existing_flow = $this->findExistingVenueFlow( $pipeline_id, $name );
		if ( $existing_flow ) {
			return new \WP_Error(
				'venue_exists',
				sprintf( 'Venue "%s" already has a flow in pipeline %d (flow ID: %d).', $name, $pipeline_id, $existing_flow ),
				array(
					'status'  => 409,
					'flow_id' => $existing_flow,
				)
			);
		}

		if ( $dry_run ) {
			return array(
				'message'       => 'Dry run — no changes made.',
				'pipeline_id'   => $pipeline_id,
				'pipeline_name' => $pipeline_name,
				'venue_name'    => $name,
				'events_url'    => $url,
				'location_term' => $location_term,
				'interval'      => $interval,
			);
		}

		// Step 1: Create or find venue taxonomy term.
		$venue_term_id = $venue_term_id > 0 ? $venue_term_id : $this->ensureVenueTerm(
			$name,
			array(
				'address' => $address,
				'city'    => $city,
				'state'   => $state,
				'zip'     => $zip,
				'website' => $website,
			)
		);
		if ( ! $venue_term_id ) {
			return new \WP_Error( 'venue_term_failed', sprintf( 'Failed to create venue term for "%s".', $name ), array( 'status' => 500 ) );
		}

		if ( \ExtraChillEvents\Core\EventSourceHandler::is_social( $source ) && ! get_term_meta( $venue_term_id, '_venue_instagram', true ) ) {
			update_term_meta( $venue_term_id, '_venue_instagram', $source['handler_config']['username'] ?? '' );
		}

		// Step 2: Create the flow. The gateway runs it as the system: this
		// ability's permission callback is the authorization boundary.
		$gateway = $this->flowGateway();
		if ( ! $gateway->is_available( 'datamachine/create-flow' ) ) {
			return new \WP_Error( 'missing_ability', 'Data Machine create-flow ability not available.', array( 'status' => 500 ) );
		}

		$flow_result = $gateway->create_flow(
			array(
				'pipeline_id'       => $pipeline_id,
				'flow_name'         => $name,
				'scheduling_config' => array(
					'interval' => $interval,
				),
			)
		);

		if ( is_wp_error( $flow_result ) ) {
			return new \WP_Error( 'flow_creation_failed', 'Failed to create flow: ' . $flow_result->get_error_message(), array( 'status' => 500 ) );
		}

		$flow_id = is_array( $flow_result ) ? (int) ( $flow_result['flow_id'] ?? 0 ) : 0;
		if ( ! $flow_id ) {
			return new \WP_Error( 'flow_no_id', 'Flow was created but no flow_id returned.', array( 'status' => 500 ) );
		}

		// Step 3: Patch flow steps with handler configs.
		$patched = $this->patchFlowSteps(
			$flow_id,
			$name,
			$url,
			$venue_term_id,
			$address,
			$city,
			$state,
			$zip,
			$website,
			$location_term,
			$source
		);
		if ( is_wp_error( $patched ) ) {
			$patched->add_data(
				array(
					'status'        => 500,
					'flow_id'       => $flow_id,
					'venue_term_id' => $venue_term_id,
				)
			);
			return $patched;
		}

		return array(
			'message'       => sprintf( 'Venue "%s" added to pipeline "%s".', $name, $pipeline_name ),
			'flow_id'       => $flow_id,
			'venue_term_id' => $venue_term_id,
			'pipeline_id'   => $pipeline_id,
			'pipeline_name' => $pipeline_name,
			'events_url'    => $url,
			'interval'      => $interval,
		);
	}

	/**
	 * Get pipeline by ID.
	 */
	private function getPipeline( int $pipeline_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_pipelines';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE pipeline_id = %d", $pipeline_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted internal identifier built from $wpdb->prefix.
			ARRAY_A
		);
	}

	/**
	 * Find the city pipeline a venue's events belong to (#988).
	 *
	 * The venue's city is the location term most of its events carry. The
	 * pipeline is the one validateCityPipeline() binds to exactly that
	 * location; pipelines named "{City} Events" are checked first.
	 *
	 * @param int $venue_term_id Venue term ID.
	 * @return array{pipeline_id:int,pipeline_name:string,location_term_id:int}|\WP_Error
	 */
	public function findCityPipelineForVenue( int $venue_term_id ) {
		$location_id = $this->venueLocationTermId( $venue_term_id );
		if ( $location_id <= 0 ) {
			return new \WP_Error( 'venue_city_unknown', __( 'This venue has no events with a city yet, so its city pipeline cannot be found. Pick one under Change details.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		$location = get_term( $location_id, 'location' );
		$name     = $location instanceof \WP_Term ? (string) $location->name : '';

		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_pipelines';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( "SELECT pipeline_id, pipeline_name FROM {$table} WHERE pipeline_name LIKE '%Events'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.

		foreach ( self::rankCityPipelineCandidates( $rows, $name ) as $row ) {
			$check = $this->validateCityPipeline( (int) $row['pipeline_id'] );
			if ( ! is_wp_error( $check ) && (int) $check['location_term_id'] === $location_id ) {
				return array(
					'pipeline_id'      => (int) $row['pipeline_id'],
					'pipeline_name'    => (string) $row['pipeline_name'],
					'location_term_id' => $location_id,
				);
			}
		}

		return new \WP_Error(
			'no_city_pipeline',
			/* translators: %s: city name. */
			sprintf( __( 'No city pipeline imports events for %s yet. Create one, or pick a pipeline under Change details.', 'extrachill-events' ), '' !== $name ? $name : '#' . $location_id ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Resolve a location input (term ID, slug, or exact name) to one location term.
	 *
	 * @param string $value Location term ID, slug, or name.
	 * @return \WP_Term|\WP_Error
	 */
	private function resolveLocationInput( string $value ) {
		$value = trim( $value );
		if ( ctype_digit( $value ) ) {
			$term = (int) $value > 0 ? get_term( (int) $value, 'location' ) : null;
			if ( $term instanceof \WP_Term ) {
				return $term;
			}
			return new \WP_Error( 'location_not_found', sprintf( 'Location term %s was not found.', $value ), array( 'status' => 404 ) );
		}

		$term = get_term_by( 'slug', $value, 'location' );
		if ( $term instanceof \WP_Term ) {
			return $term;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'location',
				'name'       => $value,
				'hide_empty' => false,
				'number'     => 2,
			)
		);
		$terms = is_wp_error( $terms ) ? array() : array_values( (array) $terms );
		if ( 1 === count( $terms ) ) {
			return $terms[0];
		}
		if ( count( $terms ) > 1 ) {
			return new \WP_Error( 'ambiguous_location', sprintf( 'More than one location is named "%s". Pass the location term ID or slug instead.', $value ), array( 'status' => 400 ) );
		}

		return new \WP_Error( 'location_not_found', sprintf( 'No location matches "%s". Pass a location term ID, slug, or exact name.', $value ), array( 'status' => 404 ) );
	}

	/**
	 * Find the one event city pipeline bound to a location.
	 *
	 * Identity comes from validateCityPipeline()'s canonical binding (the
	 * flows' upsert location term), never from pipeline names. Flow configs
	 * are prefiltered in SQL by the stored location value, then every
	 * candidate is confirmed through validateCityPipeline().
	 *
	 * @param \WP_Term $location Location term.
	 * @return array{pipeline_id:int,location_term_id:int}|\WP_Error
	 */
	public function findCityPipelineForLocation( \WP_Term $location ) {
		global $wpdb;
		$table    = $wpdb->prefix . 'datamachine_flows';
		$key      = '"taxonomy_location_selection":';
		$id       = (string) (int) $location->term_id;
		$patterns = array(
			'%' . $wpdb->esc_like( $key . $id ) . '%',
			'%' . $wpdb->esc_like( $key . '"' . $id . '"' ) . '%',
			'%' . $wpdb->esc_like( $key . wp_json_encode( (string) $location->name ) ) . '%',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$candidates = (array) $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				"SELECT DISTINCT pipeline_id FROM {$table} WHERE flow_config LIKE %s OR flow_config LIKE %s OR flow_config LIKE %s ORDER BY pipeline_id ASC",
				$patterns[0],
				$patterns[1],
				$patterns[2]
			)
		);

		$matches = array();
		foreach ( $candidates as $candidate ) {
			$check = $this->validateCityPipeline( (int) $candidate );
			if ( ! is_wp_error( $check ) && (int) $check['location_term_id'] === (int) $location->term_id ) {
				$matches[] = (int) $candidate;
			}
		}

		if ( empty( $matches ) ) {
			return new \WP_Error(
				'no_city_pipeline',
				sprintf( 'No event city pipeline imports events for %s (location %d). Ask an administrator to add the city first.', $location->name, (int) $location->term_id ),
				array( 'status' => 404 )
			);
		}
		if ( count( $matches ) > 1 ) {
			return new \WP_Error(
				'ambiguous_city_pipeline',
				sprintf( 'More than one event city pipeline imports events for %s (pipelines %s). Pass pipeline_id to choose one.', $location->name, implode( ', ', $matches ) ),
				array(
					'status'       => 409,
					'pipeline_ids' => $matches,
				)
			);
		}

		return array(
			'pipeline_id'      => $matches[0],
			'location_term_id' => (int) $location->term_id,
		);
	}

	/**
	 * Order pipeline rows so "{City} Events" (and "{City} XX Events") come first.
	 *
	 * @param array  $rows Rows with pipeline_id and pipeline_name.
	 * @param string $city City (location term) name.
	 * @return array Rows, best candidates first.
	 */
	public static function rankCityPipelineCandidates( array $rows, string $city ): array {
		$city  = strtolower( trim( $city ) );
		$score = static function ( array $row ) use ( $city ): int {
			$base = strtolower( trim( (string) preg_replace( '/\s+Events\s*$/i', '', (string) ( $row['pipeline_name'] ?? '' ) ) ) );
			if ( '' === $city ) {
				return 2;
			}
			if ( $base === $city ) {
				return 0;
			}
			return 0 === strpos( $base, $city . ' ' ) ? 1 : 2;
		};

		usort(
			$rows,
			static function ( $a, $b ) use ( $score ): int {
				return array( $score( $a ), (int) $a['pipeline_id'] ) <=> array( $score( $b ), (int) $b['pipeline_id'] );
			}
		);

		return $rows;
	}

	/** Most common location term across a venue's events, or 0. */
	private function venueLocationTermId( int $venue_term_id ): int {
		if ( $venue_term_id <= 0 ) {
			return 0;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT tl.term_id FROM {$wpdb->term_relationships} rv
				INNER JOIN {$wpdb->term_taxonomy} tv ON tv.term_taxonomy_id = rv.term_taxonomy_id AND tv.taxonomy = 'venue' AND tv.term_id = %d
				INNER JOIN {$wpdb->term_relationships} rl ON rl.object_id = rv.object_id
				INNER JOIN {$wpdb->term_taxonomy} tl ON tl.term_taxonomy_id = rl.term_taxonomy_id AND tl.taxonomy = 'location'
				GROUP BY tl.term_id ORDER BY COUNT(*) DESC, tl.term_id ASC LIMIT 1",
				$venue_term_id
			)
		);
	}

	/**
	 * Validate that a pipeline is an event-import city pipeline with one
	 * concrete canonical location binding.
	 */
	public function validateCityPipeline( int $pipeline_id ) {
		$pipeline = $this->getPipeline( $pipeline_id );
		if ( ! $pipeline ) {
			return new \WP_Error( 'pipeline_not_found', sprintf( 'Pipeline %d not found.', $pipeline_id ), array( 'status' => 404 ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_flows';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				"SELECT flow_config FROM {$table} WHERE pipeline_id = %d",
				$pipeline_id
			),
			ARRAY_A
		);

		$location_ids      = array();
		$coherent_flows    = 0;
		$invalid_structure = false;
		foreach ( (array) $rows as $row ) {
			$config           = json_decode( (string) ( $row['flow_config'] ?? '' ), true );
			$has_event_import = false;
			$has_event_upsert = false;
			$flow_locations   = array();
			foreach ( (array) $config as $step ) {
				if ( 'event_import' === ( $step['step_type'] ?? '' ) ) {
					$has_event_import = true;
				}
				if ( 'upsert' !== ( $step['step_type'] ?? '' ) ) {
					continue;
				}
				$upsert = $step['handler_config'] ?? $step['handler_configs']['upsert_event'] ?? array();
				if ( ! is_array( $upsert ) ) {
					continue;
				}
				$has_event_upsert = true;
				$value            = $upsert['taxonomy_location_selection'] ?? '';
				$location         = $this->resolveCanonicalLocationTerm( $value );
				if ( $location ) {
					$flow_locations[ (int) $location->term_id ] = true;
				} else {
					$invalid_structure = true;
				}
			}
			if ( $has_event_import !== $has_event_upsert ) {
				$invalid_structure = true;
				continue;
			}
			if ( $has_event_import && $has_event_upsert ) {
				if ( 1 !== count( $flow_locations ) ) {
					$invalid_structure = true;
					continue;
				}
				++$coherent_flows;
				$location_ids[ (int) array_key_first( $flow_locations ) ] = true;
			}
		}

		if ( $invalid_structure || $coherent_flows < 1 || 1 !== count( $location_ids ) ) {
			return new \WP_Error( 'invalid_city_pipeline', 'The selected pipeline is not an event city pipeline with one canonical location.', array( 'status' => 400 ) );
		}
		$location_id = (int) array_key_first( $location_ids );
		$location    = get_term( $location_id, 'location' );
		if ( ! $location instanceof \WP_Term ) {
			return new \WP_Error( 'invalid_pipeline_location', 'The selected pipeline location is not a canonical location term.', array( 'status' => 400 ) );
		}

		return array(
			'pipeline'         => $pipeline,
			'location'         => $location,
			'location_term_id' => $location_id,
		);
	}

	/**
	 * Resolve a supported flow location selection to one canonical term.
	 *
	 * @param mixed $value Location term ID or exact canonical name.
	 */
	private function resolveCanonicalLocationTerm( $value ): ?\WP_Term {
		return FlowLocationGuard::resolveLocationValue( $value );
	}


	/**
	 * Find an existing flow for this venue in a pipeline.
	 */
	private function findExistingVenueFlow( int $pipeline_id, string $venue_name ): ?int {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_flows';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$flow_id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted internal identifier built from $wpdb->prefix.
				"SELECT flow_id FROM {$table} WHERE pipeline_id = %d AND flow_name = %s LIMIT 1",
				$pipeline_id,
				$venue_name
			)
		);

		return $flow_id ? (int) $flow_id : null;
	}

	/**
	 * Find or create the canonical venue term.
	 *
	 * Delegates to DME's canonical venue dedup, which matches on name plus
	 * address/city evidence, so adding a venue reuses an existing same-name,
	 * same-place term instead of minting a near-duplicate. A bare name lookup
	 * would attach the wrong term when two venues share a name. DME is a hard
	 * dependency (Requires Plugins), so there is no name-only fallback: an
	 * unavailable resolver or an ambiguous match yields null and the caller
	 * reports venue_term_failed.
	 *
	 * @param string $name       Venue name.
	 * @param array  $venue_data Venue evidence: address, city, state, zip, website.
	 * @return int|null Term ID, or null when no single venue could be resolved.
	 */
	private function ensureVenueTerm( string $name, array $venue_data = array() ): ?int {
		if ( ! class_exists( \DataMachineEvents\Core\Venue_Taxonomy::class ) ) {
			return null;
		}

		$venue_data = array_filter(
			array_map( 'strval', $venue_data ),
			static function ( $value ) {
				return '' !== $value;
			}
		);

		$result  = \DataMachineEvents\Core\Venue_Taxonomy::find_or_create_venue( $name, $venue_data );
		$term_id = (int) ( $result['term_id'] ?? 0 );

		return $term_id > 0 ? $term_id : null;
	}

	/**
	 * Active Data Machine gateway (lazily built so reflection-built test
	 * instances work).
	 */
	private function flowGateway(): DataMachineFlowGateway {
		if ( null === $this->flow_gateway ) {
			$this->flow_gateway = new DataMachineFlowGateway();
		}

		return $this->flow_gateway;
	}

	/**
	 * Swap the Data Machine gateway (tests only).
	 *
	 * @param DataMachineFlowGateway|null $gateway Gateway instance, or null for the default.
	 */
	public function setFlowGateway( ?DataMachineFlowGateway $gateway ): void {
		$this->flow_gateway = $gateway;
	}

	/**
	 * Patch flow steps with venue-specific handler configs.
	 *
	 * Reads and writes go through Data Machine's flow abilities
	 * (`datamachine/get-flows`, `datamachine/update-flow-step`) via the
	 * gateway; nothing writes the flows table directly.
	 *
	 * @return true|\WP_Error
	 */
	private function patchFlowSteps( int $flow_id, string $venue_name, string $source_url, int $venue_term_id, string $address, string $city, string $state, string $zip, string $website, string $location_term, array $source ) {
		$gateway = $this->flowGateway();
		$flow    = $gateway->get_flow( $flow_id );
		if ( ! $flow ) {
			return new \WP_Error( 'flow_not_found', sprintf( 'Flow %d was created but could not be read back for configuration.', $flow_id ) );
		}

		$config = $flow['flow_config'];

		foreach ( $config as $step_id => &$step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$step_type = $step['step_type'] ?? '';

			if ( 'event_import' === $step_type ) {
				$handler_config = array(
					'source_url' => $source_url,
					'venue'      => $venue_term_id,
					'venue_name' => $venue_name,
				);

				// Add address fields if provided.
				if ( ! empty( $address ) ) {
					$handler_config['venue_address'] = $address;
				}
				if ( ! empty( $city ) ) {
					$handler_config['venue_city'] = $city;
				}
				if ( ! empty( $state ) ) {
					$handler_config['venue_state'] = $state;
				}
				if ( ! empty( $zip ) ) {
					$handler_config['venue_zip'] = $zip;
				}
				if ( ! empty( $website ) ) {
					$handler_config['venue_website'] = $website;
				}

				$handler_config = \ExtraChillEvents\Core\EventSourceHandler::is_web( $source ) ? array_merge( $handler_config, $source['handler_config'] ) : $source['handler_config'];
				$step['handler_slugs']   = array( $source['handler_slug'] );
				$step['handler_configs'] = array( $source['handler_slug'] => $handler_config );
			}

			if ( 'upsert' === $step_type ) {
				// Only fields upsert_event declares: Data Machine rejects
				// undeclared keys (venue/category/post_tag selections). The
				// venue term is carried by the scraper's `venue` config.
				$step['handler_slugs']   = array( 'upsert_event' );
				$step['handler_configs'] = array(
					'upsert_event' => array(
						'post_status'                 => \ExtraChillEvents\Core\EventSourceHandler::is_social( $source ) ? 'draft' : 'publish',
						'include_images'              => false,
						'post_author'                 => self::DEFAULT_POST_AUTHOR,
						'taxonomy_location_selection' => $location_term,
						'taxonomy_festival_selection' => 'ai_decides',
						'taxonomy_artist_selection'   => 'ai_decides',
						'taxonomy_promoter_selection' => 'skip',
					),
				);
			}

			if ( 'ai' === $step_type ) {
				// Prompt-layer purity: venue flows start with an empty AI prompt.
				// The venue identity is carried structurally (flow name + location
				// taxonomy + scraper handler config); universal processing policy
				// lives in the agent SOUL. A human only adds a prompt here when the
				// venue has a genuine quirk worth a per-flow delta.
				$step['user_message'] = \ExtraChillEvents\Core\EventSourceHandler::is_social( $source ) ? \ExtraChillEvents\Core\EventSourceHandler::EVENT_POLICY : '';
			}
		}
		unset( $step );

		// Defensive guard against extrachill-events#98 — never let an
		// upsert_event step ship with ai_decides / empty / skip for
		// taxonomy_location_selection. The block above writes
		// $location_term directly, so this is belt-and-braces against
		// future refactors that might forget to set it.
		$guard = FlowLocationGuard::coerceUpsertEventLocation( $config, $location_term );
		if ( ! empty( $guard['coerced'] ) ) {
			$config = $guard['config'];
			foreach ( $guard['coerced'] as $row ) {
				do_action(
					'datamachine_log',
					'warning',
					'Coerced taxonomy_location_selection in VenueAdd flow (extrachill-events#98 guard)',
					array(
						'flow_id' => $flow_id,
						'step_id' => $row['step_id'],
						'old'     => $row['old'],
						'new'     => $row['new'],
					)
				);
			}
		}

		foreach ( $config as $step_id => $step ) {
			$update = self::flowStepUpdate( (string) $step_id, is_array( $step ) ? $step : array() );
			if ( null === $update ) {
				continue;
			}

			$result = $gateway->update_flow_step( $update );
			if ( is_wp_error( $result ) ) {
				return new \WP_Error( 'flow_step_update_failed', sprintf( 'Failed to configure flow step %s: %s', $step_id, $result->get_error_message() ) );
			}
			if ( ! is_array( $result ) || empty( $result['success'] ) ) {
				$error = is_array( $result ) ? (string) ( $result['error'] ?? '' ) : '';
				return new \WP_Error( 'flow_step_update_failed', sprintf( 'Failed to configure flow step %s.%s', $step_id, '' !== $error ? ' ' . $error : '' ) );
			}
		}

		return true;
	}

	/**
	 * Build the `datamachine/update-flow-step` input for one configured step.
	 *
	 * @param string $flow_step_id Flow step ID.
	 * @param array  $step         Desired step config.
	 * @return array|null Update input, or null when the step needs no write.
	 */
	private static function flowStepUpdate( string $flow_step_id, array $step ): ?array {
		$step_type = $step['step_type'] ?? '';

		if ( 'ai' === $step_type ) {
			return array(
				'flow_step_id' => $flow_step_id,
				'user_message' => (string) ( $step['user_message'] ?? '' ),
			);
		}

		if ( 'event_import' !== $step_type && 'upsert' !== $step_type ) {
			return null;
		}

		$slug = (string) ( $step['handler_slugs'][0] ?? '' );
		if ( '' === $slug ) {
			return null;
		}

		return array(
			'flow_step_id'   => $flow_step_id,
			'handler_slug'   => $slug,
			'handler_config' => is_array( $step['handler_configs'][ $slug ] ?? null ) ? $step['handler_configs'][ $slug ] : array(),
		);
	}
}
