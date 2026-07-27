<?php
/** Venue expansion planning, scheduling, and reporting abilities. */

namespace ExtraChillEvents\Abilities;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachine\Engine\Tasks\TaskScheduler;
use ExtraChillEvents\Core\VenueExpansionRunner;
use ExtraChillEvents\Steps\VenueExpansion\VenueExpansionSystemTask;

class VenueExpansionAbilities {
	private static bool $registered = false;

	/** @var callable|null */
	private $scheduler;

	public function __construct( ?callable $scheduler = null ) {
		$this->scheduler = $scheduler;
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'registerAbilities' ) );
			self::$registered = true;
		}
	}

	public function registerAbilities(): void {
		wp_register_ability(
			'extrachill/expand-venues',
			array(
				'label'               => __( 'Expand Venues', 'extrachill-events' ),
				'description'         => __( 'Plan or schedule bounded, resumable venue expansion for one or more cities.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => self::inputSchema(),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'executeExpand' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
		wp_register_ability(
			'extrachill/venue-expansion-report',
			array(
				'label'               => __( 'Venue Expansion Report', 'extrachill-events' ),
				'description'         => __( 'Read per-city and aggregate results for venue expansion jobs.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'batch_job_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'job_ids'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'executeReport' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	public function executeExpand( array $input ): array|\WP_Error {
		$plan = self::buildPlan( $input );
		if ( empty( $plan['cities'] ) ) {
			return new \WP_Error( 'missing_cities', 'At least one city is required.', array( 'status' => 400 ) );
		}
		if ( empty( $input['apply'] ) ) {
			return array(
				'mode' => 'plan',
				'plan' => $plan,
			);
		}
		if ( null === $this->scheduler && ! class_exists( TaskScheduler::class ) ) {
			return new \WP_Error( 'batch_unavailable', 'Data Machine batch scheduling is unavailable.', array( 'status' => 503 ) );
		}
		if ( null === $this->scheduler && ! self::ensureExpansionTaskAvailable() ) {
			return new \WP_Error( 'expansion_task_unavailable', 'Venue expansion task registration is unavailable.', array( 'status' => 503 ) );
		}
		$agent_context = array_filter(
			array(
				'agent_id'   => (int) ( $input['agent_id'] ?? 0 ),
				'agent_slug' => sanitize_text_field( (string) ( $input['agent_slug'] ?? '' ) ),
			)
		);
		if ( empty( $agent_context ) ) {
			return new \WP_Error( 'agent_context_required', 'Apply requires agent_id or agent_slug for queued ability permissions.', array( 'status' => 400 ) );
		}
		$context   = array_merge( array( 'origin' => 'extrachill_venue_expansion' ), $agent_context );
		$scheduler = $this->scheduler ?? static function ( array $items ) use ( $context ) {
			return TaskScheduler::scheduleBatch( VenueExpansionSystemTask::TASK_TYPE, $items, $context );
		};
		$batch     = $scheduler( $plan['items'] );
		if ( false === $batch ) {
			return new \WP_Error( 'batch_schedule_failed', 'Data Machine could not schedule the venue expansion batch.', array( 'status' => 500 ) );
		}
		$plan['dry_run'] = false;
		return array(
			'mode'  => 'apply',
			'plan'  => $plan,
			'batch' => $batch,
		);
	}

	/** Load the task lazily for CLI requests that execute before task registry hydration. */
	private static function ensureExpansionTaskAvailable(): bool {
		if ( class_exists( VenueExpansionSystemTask::class ) ) {
			return true;
		}
		if ( ! class_exists( SystemTask::class ) ) {
			return false;
		}
		require_once dirname( __DIR__ ) . '/Core/VenueExpansionRunner.php';
		require_once dirname( __DIR__ ) . '/Steps/VenueExpansion/VenueExpansionSystemTask.php';
		return class_exists( VenueExpansionSystemTask::class );
	}

	/** Normalize bounds and allocate a global qualification-operation budget. */
	public static function buildPlan( array $input ): array {
		$cities = array();
		foreach ( (array) ( $input['cities'] ?? array( $input['city'] ?? '' ) ) as $city ) {
			$city = sanitize_text_field( (string) $city );
			if ( '' !== $city && ! in_array( $city, $cities, true ) ) {
				$cities[] = $city;
			}
		}
		$max_cities      = max( 1, min( 100, (int) ( $input['max_cities'] ?? 10 ) ) );
		$discovery_limit = max( 1, min( 100, (int) ( $input['discovery_budget'] ?? $max_cities ) ) );
		$cities          = array_slice( $cities, 0, min( $max_cities, $discovery_limit ) );
		$max_venues      = max( 1, min( 20, (int) ( $input['max_venues_per_city'] ?? 10 ) ) );
		$remaining       = max( 0, min( 2000, (int) ( $input['qualification_budget'] ?? count( $cities ) * $max_venues ) ) );
		$city_delay      = max( 1, min( 3600, (int) ( $input['city_delay_seconds'] ?? 60 ) ) );
		$start_time      = time();
		$items           = array();
		$city_plans      = array();
		foreach ( $cities as $index => $city ) {
			$allocated    = min( $max_venues, $remaining );
			$remaining   -= $allocated;
			$scheduled_at = $start_time + $index * $city_delay;
			$item         = array(
				'city'                 => $city,
				'country'              => strtoupper( sanitize_text_field( (string) ( $input['country'] ?? '' ) ) ),
				'max_venues'           => $max_venues,
				'qualification_budget' => $allocated,
				'skip_existing'        => ! array_key_exists( 'skip_existing', $input ) || (bool) $input['skip_existing'],
				'radius'               => max( 1, (int) ( $input['radius'] ?? 50 ) ),
				'interval'             => sanitize_text_field( (string) ( $input['interval'] ?? 'daily' ) ),
				'request_delay_ms'     => max( 0, min( 10000, (int) ( $input['request_delay_ms'] ?? 1000 ) ) ),
				'max_verdict_age_days' => max( 1, min( 365, (int) ( $input['max_verdict_age_days'] ?? 30 ) ) ),
				'scheduled_at'         => $scheduled_at,
			);
			$items[]      = $item;
			$city_plans[] = array(
				'city'                     => $city,
				'discovery_requests'       => 1,
				'qualification_operations' => $allocated,
				'max_venues'               => $max_venues,
				'scheduled_at'             => $scheduled_at,
			);
		}
		return array(
			'schema'      => VenueExpansionRunner::REPORT_SCHEMA,
			'dry_run'     => true,
			'bounds'      => array(
				'max_cities'          => $max_cities,
				'max_venues_per_city' => $max_venues,
			),
			'rate_budget' => array(
				'discovery_operations'     => count( $cities ),
				'qualification_operations' => array_sum( array_column( $city_plans, 'qualification_operations' ) ),
				'city_start_interval'      => $city_delay,
			),
			'cities'      => $city_plans,
			'items'       => $items,
		);
	}

	public function executeReport( array $input ): array|\WP_Error {
		$jobs_db      = new Jobs();
		$roots        = array();
		$scheduled    = 0;
		$batch_status = '';
		if ( ! empty( $input['batch_job_id'] ) ) {
			$batch_job = $jobs_db->get_job( (int) $input['batch_job_id'] );
			if ( ! $batch_job || empty( $batch_job['engine_data']['batch'] ) ) {
				return new \WP_Error( 'jobs_not_found', 'Venue expansion batch was not found.', array( 'status' => 404 ) );
			}
			$scheduled    = (int) ( $batch_job['engine_data']['batch_total'] ?? 0 );
			$batch_status = (string) ( $batch_job['status'] ?? '' );
			$roots        = $jobs_db->get_children( (int) $input['batch_job_id'] );
		} else {
			foreach ( (array) ( $input['job_ids'] ?? array() ) as $job_id ) {
				$job = $jobs_db->get_job( (int) $job_id );
				if ( $job ) {
					$roots[] = $job;
				}
			}
		}
		if ( empty( $roots ) && $scheduled <= 0 ) {
			return new \WP_Error( 'jobs_not_found', 'No venue expansion jobs were found.', array( 'status' => 404 ) );
		}
		$rows = array();
		foreach ( $roots as $root ) {
			$found_report = false;
			foreach ( $jobs_db->get_children( (int) $root['job_id'] ) as $child ) {
				$report = $child['engine_data']['venue_expansion_report'] ?? null;
				if ( is_array( $report ) ) {
					$rows[]       = $report;
					$found_report = true;
				}
			}
			if ( ! $found_report ) {
				$engine = (array) ( $root['engine_data'] ?? array() );
				$params = (array) ( $engine['task_params'] ?? array() );
				$row    = VenueExpansionRunner::emptyReport(
					(string) ( $params['city'] ?? '' ),
					(int) ( $params['qualification_budget'] ?? 0 )
				);
				$status = (string) ( $root['status'] ?? 'pending' );
				if ( 0 === strpos( $status, 'failed' ) ) {
					$row['status'] = 'failed';
					$row['error']  = array(
						'code'    => 'task_failed',
						'message' => (string) ( $engine['error'] ?? $status ),
					);
				} else {
					$row['status'] = 'processing';
				}
				$rows[] = $row;
			}
		}
		$result = self::aggregateReports( $rows, $scheduled > 0 ? $scheduled : count( $roots ) );
		if ( '' !== $batch_status ) {
			$result['batch_status'] = $batch_status;
			if ( count( $roots ) < $scheduled && ! in_array( $batch_status, array( 'pending', 'processing' ), true ) ) {
				$result['status']           = 'completed_with_failures';
				$result['unstarted_cities'] = $scheduled - count( $roots );
			}
		}
		return $result;
	}

	/** Aggregate stable per-city report envelopes without losing evidence. */
	public static function aggregateReports( array $reports, int $scheduled = 0 ): array {
		$aggregate = array(
			'discovered' => 0,
			'qualified'  => 0,
			'added'      => 0,
			'rejected'   => 0,
			'skipped'    => 0,
		);
		$reasons   = array();
		$statuses  = array(
			'completed'  => 0,
			'failed'     => 0,
			'processing' => 0,
		);
		foreach ( $reports as $report ) {
			$city_status = (string) ( $report['status'] ?? 'processing' );
			if ( ! isset( $statuses[ $city_status ] ) ) {
				$city_status = 'processing';
			}
			++$statuses[ $city_status ];
			foreach ( $aggregate as $key => $value ) {
				$aggregate[ $key ] += (int) ( $report['counts'][ $key ] ?? 0 );
			}
			foreach ( (array) ( $report['rejection_reasons'] ?? array() ) as $reason => $count ) {
				$reasons[ $reason ] = (int) ( $reasons[ $reason ] ?? 0 ) + (int) $count;
			}
		}
		$status = $statuses['processing'] > 0 || count( $reports ) < $scheduled ? 'processing' : ( $statuses['failed'] > 0 ? 'completed_with_failures' : 'completed' );
		return array(
			'schema'            => VenueExpansionRunner::REPORT_SCHEMA,
			'status'            => $status,
			'cities_scheduled'  => $scheduled,
			'cities_reported'   => $statuses['completed'] + $statuses['failed'],
			'city_statuses'     => $statuses,
			'counts'            => $aggregate,
			'rejection_reasons' => $reasons,
			'cities'            => array_values( $reports ),
		);
	}

	private static function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'city'                 => array( 'type' => 'string' ),
				'cities'               => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'country'              => array( 'type' => 'string' ),
				'max_cities'           => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				),
				'max_venues_per_city'  => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 20,
				),
				'discovery_budget'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				),
				'qualification_budget' => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 2000,
				),
				'skip_existing'        => array( 'type' => 'boolean' ),
				'radius'               => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'interval'             => array( 'type' => 'string' ),
				'request_delay_ms'     => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 10000,
				),
				'max_verdict_age_days' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 365,
				),
				'city_delay_seconds'   => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 3600,
				),
				'agent_id'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'agent_slug'           => array( 'type' => 'string' ),
				'apply'                => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		);
	}
}
