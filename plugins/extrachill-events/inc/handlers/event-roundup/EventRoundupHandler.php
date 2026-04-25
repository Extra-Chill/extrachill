<?php
/**
 * Event Roundup Handler
 *
 * Pipeline fetch handler for event roundup carousel generation. Queries
 * local events by weekday window and location, renders Instagram carousel
 * images via the event_roundup template, and stores image paths + summary
 * text on engine data for downstream publish steps.
 *
 * The pipeline UI uses weekday-name inputs (e.g. "thursday" → "sunday") for
 * scheduling convenience. For arbitrary date ranges (e.g. "tonight",
 * "this Friday only", "April 28-30"), call the EventRoundupAbilities
 * directly — the underlying template handles any range.
 *
 * @package ExtraChillEvents\Handlers\EventRoundup
 */

namespace ExtraChillEvents\Handlers\EventRoundup;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
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

		$week_start_day   = $config['week_start_day'] ?? '';
		$week_end_day     = $config['week_end_day'] ?? '';
		$location_term_id = $config['location_term_id'] ?? 0;

		if ( empty( $week_start_day ) || empty( $week_end_day ) ) {
			$context->log( 'error', 'Event Roundup requires week_start_day and week_end_day' );
			return array();
		}

		$date_range = $this->resolve_next_weekday_range( $week_start_day, $week_end_day );
		$date_start = $date_range['date_start'];
		$date_end   = $date_range['date_end'];

		$day_groups = $this->query_events( $date_start, $date_end, $location_term_id );

		if ( empty( $day_groups ) ) {
			$context->log(
				'info',
				'No events found for date range',
				array(
					'date_start'       => $date_start,
					'date_end'         => $date_end,
					'location_term_id' => $location_term_id,
				)
			);
			return array();
		}

		$total_events = $this->count_events( $day_groups );
		$context->log(
			'info',
			'Found events for roundup',
			array(
				'total_events' => $total_events,
				'day_count'    => count( $day_groups ),
			)
		);

		$storage_context = $context->getFileContext();
		$title           = (string) ( $config['title'] ?? '' );

		$image_paths = $this->render_slides( $day_groups, $title, $storage_context, $context );

		if ( empty( $image_paths ) ) {
			$context->log( 'error', 'Failed to generate carousel images' );
			return array();
		}

		$event_summary = $this->build_event_summary( $day_groups );
		$location_name = $this->get_location_name( $location_term_id );

		$context->storeEngineData(
			array(
				'image_file_paths' => $image_paths,
				'event_summary'    => $event_summary,
				'location_name'    => $location_name,
				'date_range'       => $this->format_date_range( $date_start, $date_end ),
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
			'title'    => sprintf( '%s Events: %s', $location_name, $this->format_date_range( $date_start, $date_end ) ),
			'content'  => $event_summary,
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
	 * Query events using the query-events ability with location filter.
	 *
	 * Uses EventHydrator + DateGrouper directly (the previous Calendar_Query
	 * static facade was renamed during the data-machine-events 0.29.x cleanup).
	 */
	private function query_events( string $date_start, string $date_end, int $location_term_id ): array {
		$input = array(
			'date_start' => $date_start,
			'date_end'   => $date_end,
			'per_page'   => -1,
			'order'      => 'ASC',
		);

		if ( $location_term_id > 0 ) {
			$input['tax_filters'] = array(
				'location' => array( $location_term_id ),
			);
		}

		$ability = new \DataMachineEvents\Abilities\EventDateQueryAbilities();
		$result  = $ability->executeQueryEvents( $input );

		if ( ! class_exists( '\DataMachineEvents\Blocks\Calendar\Data\EventHydrator' )
			|| ! class_exists( '\DataMachineEvents\Blocks\Calendar\Grouping\DateGrouper' ) ) {
			return array();
		}

		// Build paged_events from WP_Post objects and group by date.
		$paged_events = array();
		foreach ( $result['posts'] as $post ) {
			$event_data = \DataMachineEvents\Blocks\Calendar\Data\EventHydrator::parse_event_data( $post );
			if ( ! $event_data ) {
				continue;
			}
			$paged_events[] = array(
				'post'       => $post,
				'event_data' => $event_data,
			);
		}

		return \DataMachineEvents\Blocks\Calendar\Grouping\DateGrouper::group_events_by_date( $paged_events );
	}

	private function resolve_next_weekday_range( string $week_start_day, string $week_end_day ): array {
		$now       = new \DateTime( 'now', \wp_timezone() );
		$start_obj = ( clone $now )->modify( 'next ' . $week_start_day )->setTime( 0, 0, 0 );
		$end_obj   = ( clone $start_obj )->modify( 'next ' . $week_end_day )->setTime( 0, 0, 0 );

		if ( $end_obj <= $start_obj ) {
			$end_obj = $end_obj->modify( '+7 days' );
		}

		return array(
			'date_start' => $start_obj->format( 'Y-m-d' ),
			'date_end'   => $end_obj->format( 'Y-m-d' ),
		);
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
	 * Render slide images via the Data Machine render-image-template ability.
	 *
	 * The actual GD work lives in EventRoundupTemplate, which reads brand
	 * identity (colors, fonts, day-of-week palette) from the BrandTokens
	 * primitive. This handler just hands off the data and collects the
	 * resulting file paths.
	 *
	 * @param array            $day_groups      Day-grouped events.
	 * @param string           $title           Optional carousel title (first slide only).
	 * @param array            $storage_context Pipeline/flow context for FilesRepository storage.
	 * @param ExecutionContext $context         Pipeline execution context (for logging).
	 * @return string[] Array of generated slide file paths.
	 */
	private function render_slides( array $day_groups, string $title, array $storage_context, ExecutionContext $context ): array {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$context->log( 'error', 'Abilities API not available — cannot render roundup slides' );
			return array();
		}

		$ability = \wp_get_ability( 'datamachine/render-image-template' );
		if ( ! $ability ) {
			$context->log( 'error', 'datamachine/render-image-template ability not registered' );
			return array();
		}

		$result = $ability->execute(
			array(
				'template_id' => 'event_roundup',
				'data'        => array(
					'day_groups' => $day_groups,
					'title'      => $title,
				),
				'preset'      => 'instagram_feed_portrait',
				'format'      => 'png',
				'context'     => $storage_context,
			)
		);

		if ( \is_wp_error( $result ) ) {
			$context->log(
				'error',
				'render-image-template ability returned WP_Error',
				array( 'message' => $result->get_error_message() )
			);
			return array();
		}

		if ( empty( $result['success'] ) ) {
			$context->log(
				'error',
				'render-image-template ability reported failure',
				array( 'message' => $result['message'] ?? '(no message)' )
			);
			return array();
		}

		return (array) ( $result['file_paths'] ?? array() );
	}

	/**
	 * Build a plain-text summary of events for downstream AI caption generation.
	 *
	 * Lives here (not in the slide template) because it's plain text output
	 * for AI captioning, not GD image work — the template only owns rendering.
	 *
	 * @param array $day_groups Day-grouped events.
	 * @return string Plain text summary.
	 */
	public function build_event_summary( array $day_groups ): string {
		$lines = array();

		foreach ( $day_groups as $day_group ) {
			$date_obj = $day_group['date_obj'] ?? null;
			$events   = $day_group['events'] ?? array();

			$day_label = $date_obj ? $date_obj->format( 'l, M j' ) : 'Unknown Date';
			$lines[]   = $day_label . ':';

			foreach ( $events as $event_item ) {
				$post       = $event_item['post'] ?? null;
				$event_data = $event_item['event_data'] ?? array();

				$title      = $post ? (string) $post->post_title : 'Untitled';
				$venue      = (string) ( $event_data['venue'] ?? '' );
				$start_time = (string) ( $event_data['startTime'] ?? '' );

				$formatted_time = '';
				if ( '' !== $start_time ) {
					$time_obj = \DateTime::createFromFormat( 'H:i:s', $start_time );
					if ( $time_obj ) {
						$formatted_time = $time_obj->format( 'g:i A' );
					}
				}

				$event_line = "- {$title}";
				if ( '' !== $venue ) {
					$event_line .= " @ {$venue}";
				}
				if ( '' !== $formatted_time ) {
					$event_line .= " ({$formatted_time})";
				}

				$lines[] = $event_line;
			}

			$lines[] = '';
		}

		return implode( "\n", $lines );
	}
}
