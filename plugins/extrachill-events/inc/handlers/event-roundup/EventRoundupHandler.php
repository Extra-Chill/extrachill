<?php
/**
 * Event Roundup Handler
 *
 * Pipeline fetch handler for event roundup carousel generation. A thin
 * adapter over EventRoundupAbilities: handler config (weekday window,
 * location, title) becomes ability input, the query + render abilities do
 * the work, and the result is stored on engine data for downstream publish
 * steps.
 *
 * The abilities are invoked through their execute callbacks rather than the
 * Abilities API so pipeline runs (cron, no current user) are not gated by
 * the roundup capability check.
 *
 * @package ExtraChillEvents\Handlers\EventRoundup
 */

namespace ExtraChillEvents\Handlers\EventRoundup;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use ExtraChillEvents\Abilities\EventRoundupAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventRoundupHandler extends FetchHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'event_roundup' );

		self::registerHandler(
			'event_roundup',
			'fetch',
			self::class,
			\__( 'Event Roundup', 'extrachill-events' ),
			\__( 'Generate Instagram carousel images from local events by weekday window and location', 'extrachill-events' ),
			false,
			null,
			EventRoundupSettings::class,
			null
		);
	}

	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$context->log(
			'info',
			'Starting Event Roundup generation',
			array(
				'pipeline_id' => $context->getPipelineId(),
				'job_id'      => $context->getJobId(),
				'config'      => $config,
			)
		);

		if ( empty( $config['week_start_day'] ) || empty( $config['week_end_day'] ) ) {
			$context->log( 'error', 'Event Roundup requires week_start_day and week_end_day' );
			return array();
		}

		$abilities = self::abilities();
		$query     = $abilities->executeQuery(
			array(
				'week_start_day'   => (string) $config['week_start_day'],
				'week_end_day'     => (string) $config['week_end_day'],
				'location_term_id' => (int) ( $config['location_term_id'] ?? 0 ),
			)
		);

		if ( \is_wp_error( $query ) ) {
			$context->log( 'error', 'Event Roundup query failed', array( 'message' => $query->get_error_message() ) );
			return array();
		}

		$date_start = $query['date_start'];
		$date_end   = $query['date_end'];

		if ( empty( $query['day_groups'] ) ) {
			$context->log(
				'info',
				'No events found for date range',
				array(
					'date_start'       => $date_start,
					'date_end'         => $date_end,
					'location_term_id' => (int) ( $config['location_term_id'] ?? 0 ),
				)
			);
			return array();
		}

		$render = $abilities->executeRender(
			array(
				'day_groups'      => $query['day_groups'],
				'title'           => (string) ( $config['title'] ?? '' ),
				'storage_context' => $context->getFileContext(),
			)
		);

		if ( \is_wp_error( $render ) ) {
			$context->log( 'error', 'Failed to generate carousel images', array( 'message' => $render->get_error_message() ) );
			return array();
		}

		$image_paths   = $render['image_paths'];
		$total_events  = $query['total_events'];
		$location_name = $query['location_name'];
		$date_range    = self::format_date_range( $date_start, $date_end );

		$context->storeEngineData(
			array(
				'image_file_paths' => $image_paths,
				'event_summary'    => $query['event_summary'],
				'location_name'    => $location_name,
				'date_range'       => $date_range,
				'date_start'       => $date_start,
				'date_end'         => $date_end,
				'total_events'     => $total_events,
				'total_slides'     => count( $image_paths ),
				'roundup_context'  => array(
					'location'    => $location_name,
					'start_date'  => $date_start,
					'end_date'    => $date_end,
					'day_count'   => ( new \DateTime( $date_end ) )->diff( new \DateTime( $date_start ) )->days + 1,
					'event_count' => $total_events,
				),
			)
		);

		$context->log(
			'info',
			'Event Roundup complete',
			array(
				'slides_generated' => count( $image_paths ),
				'total_events'     => $total_events,
			)
		);

		return array(
			'title'    => sprintf( '%s Events: %s', $location_name, $date_range ),
			'content'  => $query['event_summary'],
			'metadata' => array(
				'source_type' => 'event_roundup',
				'location'    => $location_name,
				'date_start'  => $date_start,
				'date_end'    => $date_end,
				'event_count' => $total_events,
				'slide_count' => count( $image_paths ),
			),
		);
	}

	/**
	 * Roundup abilities instance. Construction registers the abilities once.
	 */
	private static function abilities(): EventRoundupAbilities {
		if ( ! class_exists( EventRoundupAbilities::class ) ) {
			require_once \dirname( __DIR__, 2 ) . '/Abilities/EventRoundupAbilities.php';
		}

		return new EventRoundupAbilities();
	}

	/**
	 * Format date range for display, e.g. "Mar 6 - Mar 8, 2026".
	 */
	private static function format_date_range( string $start, string $end ): string {
		$start_obj = \DateTime::createFromFormat( 'Y-m-d', $start );
		$end_obj   = \DateTime::createFromFormat( 'Y-m-d', $end );

		if ( ! $start_obj || ! $end_obj ) {
			return "{$start} - {$end}";
		}

		return $start_obj->format( 'M j' ) . ' - ' . $end_obj->format( 'M j, Y' );
	}
}
