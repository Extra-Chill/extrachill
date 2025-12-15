<?php
/**
 * Weekly Roundup Handler
 *
 * Queries local events by date range and location, generates Instagram carousel images.
 * Outputs image paths and text summary to engine data for downstream steps.
 *
 * @package ExtraChillEvents\Handlers\WeeklyRoundup
 */

namespace ExtraChillEvents\Handlers\WeeklyRoundup;

use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachine\Core\DataPacket;
use DataMachineEvents\Blocks\Calendar\Calendar_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WeeklyRoundupHandler extends FetchHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'weekly_roundup' );

		self::registerHandler(
			'weekly_roundup',
			'fetch',
			self::class,
			__( 'Weekly Event Roundup', 'extrachill-events' ),
			__( 'Generate Instagram carousel images from local events by date range and location', 'extrachill-events' ),
			false,
			null,
			WeeklyRoundupSettings::class,
			null
		);
	}

	protected function executeFetch( int $pipeline_id, array $config, ?string $flow_step_id, int $flow_id, ?string $job_id ): array {
		$this->log(
			'info',
			'Starting Weekly Roundup generation',
			array(
				'pipeline_id' => $pipeline_id,
				'job_id'      => $job_id,
				'config'      => $config,
			)
		);

		$date_start       = $config['date_range_start'] ?? '';
		$date_end         = $config['date_range_end'] ?? '';
		$location_term_id = $config['location_term_id'] ?? 0;

		if ( empty( $date_start ) || empty( $date_end ) ) {
			$this->log( 'error', 'Weekly Roundup requires date_range_start and date_range_end' );
			return $this->emptyResponse();
		}

		$day_groups = $this->query_events( $date_start, $date_end, $location_term_id );

		if ( empty( $day_groups ) ) {
			$this->log(
				'info',
				'No events found for date range',
				array(
					'date_start'       => $date_start,
					'date_end'         => $date_end,
					'location_term_id' => $location_term_id,
				)
			);
			return $this->emptyResponse();
		}

		$total_events = $this->count_events( $day_groups );
		$this->log(
			'info',
			'Found events for roundup',
			array(
				'total_events' => $total_events,
				'day_count'    => count( $day_groups ),
			)
		);

		$generator       = new \ExtraChillEvents\Handlers\WeeklyRoundup\SlideGenerator();
		$storage_context = array(
			'pipeline_id' => $pipeline_id,
			'flow_id'     => $flow_id,
		);
		$image_paths     = $generator->generate_slides( $day_groups, $storage_context );

		if ( empty( $image_paths ) ) {
			$this->log( 'error', 'Failed to generate carousel images' );
			return $this->emptyResponse();
		}

		$event_summary = $generator->build_event_summary( $day_groups );
		$location_name = $this->get_location_name( $location_term_id );

		$this->store_engine_data( $job_id, $image_paths, $event_summary, $location_name, $date_start, $date_end, $total_events, count( $image_paths ) );

		$this->log(
			'info',
			'Weekly Roundup complete',
			array(
				'slides_generated' => count( $image_paths ),
				'total_events'     => $total_events,
			)
		);

		$data_packet = new DataPacket(
			array(
				'title' => sprintf( '%s Events: %s', $location_name, $this->format_date_range( $date_start, $date_end ) ),
				'body'  => $event_summary,
			),
			array(
				'source_type' => 'weekly_roundup',
				'location'    => $location_name,
				'date_start'  => $date_start,
				'date_end'    => $date_end,
				'event_count' => $total_events,
				'slide_count' => count( $image_paths ),
			),
			'weekly_roundup'
		);

		return $this->successResponse( array( $data_packet ) );
	}

	/**
	 * Query events using Calendar_Query with location filter.
	 */
	private function query_events( string $date_start, string $date_end, int $location_term_id ): array {
		$params = array(
			'date_start' => $date_start,
			'date_end'   => $date_end,
			'show_past'  => false,
		);

		if ( $location_term_id > 0 ) {
			$params['tax_filters'] = array(
				'location' => array( $location_term_id ),
			);
		}

		$query_args   = Calendar_Query::build_query_args( $params );
		$query        = new WP_Query( $query_args );
		$paged_events = Calendar_Query::build_paged_events( $query );

		return Calendar_Query::group_events_by_date( $paged_events );
	}

	/**
	 * Count total events across all day groups.
	 */
	private function count_events( array $day_groups ): int {
		$count = 0;
		foreach ( $day_groups as $day_group ) {
			$count += count( $day_group['events'] ?? array() );
		}
		return $count;
	}

	/**
	 * Get location term name.
	 */
	private function get_location_name( int $term_id ): string {
		if ( $term_id <= 0 ) {
			return 'All Locations';
		}

		$term = \get_term( $term_id, 'location' );
		if ( ! $term || \is_wp_error( $term ) ) {
			return 'Unknown Location';
		}

		return $term->name;
	}

	/**
	 * Format date range for display.
	 */
	private function format_date_range( string $start, string $end ): string {
		$start_obj = \DateTime::createFromFormat( 'Y-m-d', $start );
		$end_obj   = \DateTime::createFromFormat( 'Y-m-d', $end );

		if ( ! $start_obj || ! $end_obj ) {
			return "{$start} - {$end}";
		}

		return $start_obj->format( 'M j' ) . ' - ' . $end_obj->format( 'M j, Y' );
	}

	/**
	 * Store roundup data to engine data for downstream steps.
	 */
	private function store_engine_data(
		string $job_id,
		array $image_paths,
		string $event_summary,
		string $location_name,
		string $date_start,
		string $date_end,
		int $total_events,
		int $total_slides
	): void {
		\datamachine_merge_engine_data(
			$job_id,
			array(
				'image_file_paths' => $image_paths,
				'event_summary'    => $event_summary,
				'location_name'    => $location_name,
				'date_range'       => $this->format_date_range( $date_start, $date_end ),
				'date_start'       => $date_start,
				'date_end'         => $date_end,
				'total_events'     => $total_events,
				'total_slides'     => $total_slides,
				'roundup_context'  => array(
					'location'    => $location_name,
					'start_date'  => $date_start,
					'end_date'    => $date_end,
					'day_count'   => ( new \DateTime( $date_end ) )->diff( new \DateTime( $date_start ) )->days + 1,
					'event_count' => $total_events,
				),
			)
		);
	}
}
