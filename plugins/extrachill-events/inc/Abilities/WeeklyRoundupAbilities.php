<?php
/**
 * Weekly Roundup Abilities
 *
 * WordPress 6.9 Abilities API integration for weekly event roundup operations.
 * Provides query and generate primitive abilities for Instagram carousel creation.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use DataMachineEvents\Blocks\Calendar\Calendar_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WeeklyRoundupAbilities {

	private static bool $registered = false;

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
		$this->registerQueryAbility();
		$this->registerGenerateAbility();
	}

	private function registerQueryAbility(): void {
		wp_register_ability(
			'extrachill/weekly-roundup-query',
			array(
				'label'        => __( 'Weekly Roundup Query', 'extrachill-events' ),
				'description'  => __( 'Query events for weekly roundup by date range and location.', 'extrachill-events' ),
				'category'     => 'extrachill-events',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'week_start_day' => array(
							'type'        => 'string',
							'description' => __( 'Weekday name to start the range (e.g., "thursday").', 'extrachill-events' ),
						),
						'week_end_day' => array(
							'type'        => 'string',
							'description' => __( 'Weekday name to end the range (e.g., "sunday").', 'extrachill-events' ),
						),
						'location_term_id' => array(
							'type'        => 'integer',
							'description' => __( 'Optional location taxonomy term ID to filter events.', 'extrachill-events' ),
						),
					),
					'required' => array( 'week_start_day', 'week_end_day' ),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'date_start'    => array( 'type' => 'string', 'description' => 'Start date (Y-m-d)' ),
						'date_end'      => array( 'type' => 'string', 'description' => 'End date (Y-m-d)' ),
						'location_name' => array( 'type' => 'string', 'description' => 'Location term name' ),
						'total_events'  => array( 'type' => 'integer', 'description' => 'Total event count' ),
						'day_groups'    => array(
							'type'        => 'array',
							'description' => 'Events grouped by date',
							'items'       => array( 'type' => 'object' ),
						),
						'event_summary' => array( 'type' => 'string', 'description' => 'Plain text event summary' ),
					),
				),
				'execute_callback'    => array( $this, 'executeQuery' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				},
				'meta' => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerGenerateAbility(): void {
		wp_register_ability(
			'extrachill/weekly-roundup-generate',
			array(
				'label'        => __( 'Weekly Roundup Generate', 'extrachill-events' ),
				'description'  => __( 'Generate Instagram carousel images from event data.', 'extrachill-events' ),
				'category'     => 'extrachill-events',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'day_groups' => array(
							'type'        => 'array',
							'description' => __( 'Day-grouped events from weekly-roundup-query.', 'extrachill-events' ),
							'items'       => array( 'type' => 'object' ),
						),
						'title' => array(
							'type'        => 'string',
							'description' => __( 'Optional title for first slide.', 'extrachill-events' ),
						),
						'storage_context' => array(
							'type'        => 'object',
							'description' => __( 'Optional storage context with pipeline_id and flow_id.', 'extrachill-events' ),
						),
					),
					'required' => array( 'day_groups' ),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'image_paths' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'slide_count' => array( 'type' => 'integer' ),
						'message'     => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGenerate' ),
				'permission_callback' => function() {
					return current_user_can( 'upload_files' );
				},
				'meta' => array( 'show_in_rest' => true ),
			)
		);
	}

	public function executeQuery( array $input ): array {
		$week_start_day   = $input['week_start_day'] ?? '';
		$week_end_day     = $input['week_end_day'] ?? '';
		$location_term_id = $input['location_term_id'] ?? 0;

		if ( empty( $week_start_day ) || empty( $week_end_day ) ) {
			return array(
				'error'   => true,
				'message' => __( 'week_start_day and week_end_day are required.', 'extrachill-events' ),
			);
		}

		$date_range = $this->resolveNextWeekdayRange( $week_start_day, $week_end_day );
		$date_start = $date_range['date_start'];
		$date_end   = $date_range['date_end'];

		$day_groups = $this->queryEvents( $date_start, $date_end, $location_term_id );

		if ( empty( $day_groups ) ) {
			return array(
				'date_start'    => $date_start,
				'date_end'      => $date_end,
				'location_name' => $this->getLocationName( $location_term_id ),
				'total_events'  => 0,
				'day_groups'    => array(),
				'event_summary' => '',
			);
		}

		$total_events  = $this->countEvents( $day_groups );
		$location_name = $this->getLocationName( $location_term_id );
		$event_summary = $this->buildEventSummary( $day_groups );

		return array(
			'date_start'    => $date_start,
			'date_end'      => $date_end,
			'location_name' => $location_name,
			'total_events'  => $total_events,
			'day_groups'    => $day_groups,
			'event_summary' => $event_summary,
		);
	}

	public function executeGenerate( array $input ): array {
		$day_groups      = $input['day_groups'] ?? array();
		$title           = $input['title'] ?? '';
		$storage_context = $input['storage_context'] ?? array();

		if ( empty( $day_groups ) ) {
			return array(
				'success'     => false,
				'image_paths' => array(),
				'slide_count' => 0,
				'message'     => __( 'day_groups is required.', 'extrachill-events' ),
			);
		}

		if ( ! class_exists( '\ExtraChillEvents\Handlers\WeeklyRoundup\SlideGenerator' ) ) {
			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/handlers/weekly-roundup/SlideGenerator.php';
		}

		$generator   = new \ExtraChillEvents\Handlers\WeeklyRoundup\SlideGenerator();
		$image_paths = $generator->generate_slides( $day_groups, $storage_context, $title );

		if ( empty( $image_paths ) ) {
			return array(
				'success'     => false,
				'image_paths' => array(),
				'slide_count' => 0,
				'message'     => __( 'Failed to generate carousel images.', 'extrachill-events' ),
			);
		}

		return array(
			'success'     => true,
			'image_paths' => $image_paths,
			'slide_count' => count( $image_paths ),
			'message'     => sprintf(
				__( 'Generated %d carousel slides.', 'extrachill-events' ),
				count( $image_paths )
			),
		);
	}

	private function queryEvents( string $date_start, string $date_end, int $location_term_id ): array {
		if ( ! class_exists( 'DataMachineEvents\Blocks\Calendar\Calendar_Query' ) ) {
			return array();
		}

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
		$query        = new \WP_Query( $query_args );
		$paged_events = Calendar_Query::build_paged_events( $query );

		return Calendar_Query::group_events_by_date( $paged_events );
	}

	private function resolveNextWeekdayRange( string $week_start_day, string $week_end_day ): array {
		$now       = new \DateTime( 'now', wp_timezone() );
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

	private function countEvents( array $day_groups ): int {
		$count = 0;
		foreach ( $day_groups as $day_group ) {
			$count += count( $day_group['events'] ?? array() );
		}
		return $count;
	}

	private function getLocationName( int $term_id ): string {
		if ( $term_id <= 0 ) {
			return 'All Locations';
		}

		$term = get_term( $term_id, 'location' );
		if ( ! $term || is_wp_error( $term ) ) {
			return 'Unknown Location';
		}

		return $term->name;
	}

	private function buildEventSummary( array $day_groups ): string {
		$lines = array();

		foreach ( $day_groups as $day_group ) {
			$date_obj = $day_group['date_obj'] ?? null;
			$events   = $day_group['events'] ?? array();

			$day_label = $date_obj ? $date_obj->format( 'l, M j' ) : 'Unknown Date';
			$lines[]   = $day_label . ':';

			foreach ( $events as $event_item ) {
				$post       = $event_item['post'] ?? null;
				$event_data = $event_item['event_data'] ?? array();

				$title      = $post ? $post->post_title : 'Untitled';
				$venue      = $event_data['venue'] ?? '';
				$start_time = $event_data['startTime'] ?? '';

				$formatted_time = '';
				if ( $start_time ) {
					$time_obj = \DateTime::createFromFormat( 'H:i:s', $start_time );
					if ( $time_obj ) {
						$formatted_time = $time_obj->format( 'g:i A' );
					}
				}

				$event_line = "- {$title}";
				if ( $venue ) {
					$event_line .= " @ {$venue}";
				}
				if ( $formatted_time ) {
					$event_line .= " ({$formatted_time})";
				}

				$lines[] = $event_line;
			}

			$lines[] = '';
		}

		return implode( "\n", $lines );
	}
}
