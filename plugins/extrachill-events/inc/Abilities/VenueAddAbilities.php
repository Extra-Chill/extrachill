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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueAddAbilities {

	/**
	 * Default scheduling interval for venue scraper flows.
	 */
	private const DEFAULT_INTERVAL = 'twicedaily';

	/**
	 * Default AI model.
	 */
	private const DEFAULT_AI_MODEL = 'gpt-5-mini';

	/**
	 * Default AI provider.
	 */
	private const DEFAULT_AI_PROVIDER = 'openai';

	/**
	 * Default post author.
	 */
	private const DEFAULT_POST_AUTHOR = 32;

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
				'extrachill/add-venue',
				array(
					'label'               => __( 'Add Venue', 'extrachill-events' ),
					'description'         => __( 'Add a venue scraper flow to an existing city pipeline. Creates the venue taxonomy term and configures the universal web scraper.', 'extrachill-events' ),
					'category'            => 'extrachill-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'pipeline_id', 'name', 'url' ),
						'properties' => array(
							'pipeline_id' => array(
								'type'        => 'integer',
								'description' => 'Pipeline ID for the city this venue belongs to.',
							),
							'name'        => array(
								'type'        => 'string',
								'description' => 'Venue name, e.g. "Exit/In" or "The Station Inn".',
							),
							'url'         => array(
								'type'        => 'string',
								'description' => 'Venue events page URL (the page the scraper will hit).',
							),
							'address'     => array(
								'type'        => 'string',
								'description' => 'Venue street address. Optional — used for geocoding.',
							),
							'city'        => array(
								'type'        => 'string',
								'description' => 'Venue city name. Optional — derived from pipeline if not provided.',
							),
							'state'       => array(
								'type'        => 'string',
								'description' => 'Venue state. Optional.',
							),
							'zip'         => array(
								'type'        => 'string',
								'description' => 'Venue zip code. Optional.',
							),
							'website'     => array(
								'type'        => 'string',
								'description' => 'Venue homepage URL (may differ from events page URL). Optional.',
							),
							'interval'    => array(
								'type'        => 'string',
								'description' => 'Scheduling interval. Defaults to "twicedaily".',
							),
							'dry_run'     => array(
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
	 * Execute add-venue.
	 *
	 * @param array $input Venue parameters.
	 * @return array Result.
	 */
	public function executeAddVenue( array $input ): array {
		$pipeline_id = (int) ( $input['pipeline_id'] ?? 0 );
		$name        = sanitize_text_field( $input['name'] ?? '' );
		$url         = esc_url_raw( $input['url'] ?? '' );
		$address     = sanitize_text_field( $input['address'] ?? '' );
		$city        = sanitize_text_field( $input['city'] ?? '' );
		$state       = sanitize_text_field( $input['state'] ?? '' );
		$zip         = sanitize_text_field( $input['zip'] ?? '' );
		$website     = esc_url_raw( $input['website'] ?? '' );
		$interval    = sanitize_text_field( $input['interval'] ?? self::DEFAULT_INTERVAL );
		$dry_run     = ! empty( $input['dry_run'] );

		if ( $pipeline_id <= 0 ) {
			return array( 'error' => 'pipeline_id is required.' );
		}
		if ( empty( $name ) ) {
			return array( 'error' => 'Venue name is required.' );
		}
		if ( empty( $url ) ) {
			return array( 'error' => 'Events page URL is required.' );
		}

		// Validate pipeline exists.
		$pipeline = $this->getPipeline( $pipeline_id );
		if ( ! $pipeline ) {
			return array( 'error' => sprintf( 'Pipeline %d not found.', $pipeline_id ) );
		}

		$pipeline_name = $pipeline['pipeline_name'] ?? '';

		// Derive city from pipeline name if not provided (e.g. "Nashville Events" → "Nashville").
		if ( empty( $city ) ) {
			$city = str_ireplace( ' Events', '', $pipeline_name );
		}

		// Derive location term from pipeline name.
		$location_term = str_ireplace( ' Events', '', $pipeline_name );

		// Check for idempotency — does a flow with this venue already exist in this pipeline?
		$existing_flow = $this->findExistingVenueFlow( $pipeline_id, $name );
		if ( $existing_flow ) {
			return array(
				'error'   => sprintf( 'Venue "%s" already has a flow in pipeline %d (flow ID: %d).', $name, $pipeline_id, $existing_flow ),
				'flow_id' => $existing_flow,
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
		$venue_term_id = $this->ensureVenueTerm( $name );
		if ( ! $venue_term_id ) {
			return array( 'error' => sprintf( 'Failed to create venue term for "%s".', $name ) );
		}

		// Step 2: Create the flow.
		$flow_ability = wp_get_ability( 'datamachine/create-flow' );
		if ( ! $flow_ability ) {
			return array( 'error' => 'Data Machine create-flow ability not available.' );
		}

		$flow_result = $flow_ability->execute(
			array(
				'pipeline_id'       => $pipeline_id,
				'flow_name'         => $name,
				'scheduling_config' => array(
					'interval' => $interval,
				),
			)
		);

		if ( isset( $flow_result['error'] ) ) {
			return array( 'error' => 'Failed to create flow: ' . $flow_result['error'] );
		}

		$flow_id = $flow_result['flow_id'] ?? null;
		if ( ! $flow_id ) {
			return array( 'error' => 'Flow was created but no flow_id returned.' );
		}

		// Step 3: Patch flow steps with handler configs.
		$this->patchFlowSteps(
			$flow_id,
			$name,
			$url,
			$venue_term_id,
			$address,
			$city,
			$state,
			$zip,
			$website,
			$location_term
		);

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
			$wpdb->prepare( "SELECT * FROM {$table} WHERE pipeline_id = %d", $pipeline_id ),
			ARRAY_A
		);
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
				"SELECT flow_id FROM {$table} WHERE pipeline_id = %d AND flow_name = %s LIMIT 1",
				$pipeline_id,
				$venue_name
			)
		);

		return $flow_id ? (int) $flow_id : null;
	}

	/**
	 * Ensure a venue taxonomy term exists.
	 */
	private function ensureVenueTerm( string $name ): ?int {
		$existing = get_term_by( 'name', $name, 'venue' );
		if ( $existing ) {
			return (int) $existing->term_id;
		}

		$result = wp_insert_term( $name, 'venue' );
		if ( is_wp_error( $result ) ) {
			// Term may have been created with different casing.
			$existing = get_term_by( 'slug', sanitize_title( $name ), 'venue' );
			return $existing ? (int) $existing->term_id : null;
		}

		return (int) $result['term_id'];
	}

	/**
	 * Patch flow steps with venue-specific handler configs.
	 */
	private function patchFlowSteps( int $flow_id, string $venue_name, string $source_url, int $venue_term_id, string $address, string $city, string $state, string $zip, string $website, string $location_term ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_flows';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$flow = $wpdb->get_row(
			$wpdb->prepare( "SELECT flow_config FROM {$table} WHERE flow_id = %d", $flow_id ),
			ARRAY_A
		);

		if ( ! $flow ) {
			return;
		}

		$config = json_decode( $flow['flow_config'], true );
		if ( ! is_array( $config ) ) {
			return;
		}

		foreach ( $config as $step_id => &$step ) {
			$step_type = $step['step_type'] ?? '';

			if ( 'event_import' === $step_type ) {
				$handler_config = array(
					'source_url'    => $source_url,
					'venue'         => $venue_term_id,
					'venue_name'    => $venue_name,
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

				$step['handler_slugs']   = array( 'universal_web_scraper' );
				$step['handler_configs'] = array( 'universal_web_scraper' => $handler_config );
				$step['enabled']         = true;
			}

			if ( 'update' === $step_type ) {
				$step['handler_slugs']   = array( 'upsert_event' );
				$step['handler_configs'] = array(
					'upsert_event' => array(
						'post_status'                 => 'publish',
						'include_images'              => false,
						'post_author'                 => self::DEFAULT_POST_AUTHOR,
						'taxonomy_category_selection' => 'skip',
						'taxonomy_post_tag_selection' => 'skip',
						'taxonomy_location_selection' => $location_term,
						'taxonomy_festival_selection' => 'ai_decides',
						'taxonomy_artist_selection'   => 'ai_decides',
						'taxonomy_promoter_selection' => 'skip',
					),
				);
				$step['user_message'] = "IMPORTANT SYSTEM-WIDE RULE: Do not assign WordPress Categories or Tags for events.";
				$step['enabled']      = true;
			}

			if ( 'ai' === $step_type ) {
				$step['user_message'] = sprintf(
					'Process this event from %s for the events calendar.',
					$venue_name
				);
			}
		}
		unset( $step );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$table,
			array( 'flow_config' => wp_json_encode( $config ) ),
			array( 'flow_id' => $flow_id )
		);
	}
}
