<?php
/**
 * Event Roundup Abilities
 *
 * WordPress 6.9 Abilities API integration for event roundup operations.
 * Provides three abilities:
 *
 *   - extrachill/event-roundup-query   — query events for a date range
 *                                         and group by day
 *   - extrachill/event-roundup-render  — render slides from day_groups
 *                                         (consumes datamachine/render-image-template)
 *   - extrachill/event-roundup-build   — one-shot combo: query + render
 *                                         (the primary CLI / agent / on-demand entry point)
 *
 * Date range native: accepts arbitrary Y-m-d inputs OR weekday-name shortcuts
 * (e.g. week_start_day=thursday). Location accepts taxonomy term ID or slug.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventRoundupAbilities {

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
		$this->registerRenderAbility();
		$this->registerBuildAbility();
	}

	private function registerQueryAbility(): void {
		wp_register_ability(
			'extrachill/event-roundup-query',
			array(
				'label'               => __( 'Event Roundup Query', 'extrachill-events' ),
				'description'         => __( 'Query events for a roundup by date range and location. Accepts arbitrary date ranges or weekday-name shortcuts.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'date_start'       => array(
							'type'        => 'string',
							'description' => __( 'Start date (Y-m-d). Mutually exclusive with week_start_day.', 'extrachill-events' ),
						),
						'date_end'         => array(
							'type'        => 'string',
							'description' => __( 'End date (Y-m-d). Defaults to date_start if omitted (single-day roundup).', 'extrachill-events' ),
						),
						'week_start_day'   => array(
							'type'        => 'string',
							'description' => __( 'Weekday-name shortcut: resolves to next occurrence (e.g. "thursday").', 'extrachill-events' ),
						),
						'week_end_day'     => array(
							'type'        => 'string',
							'description' => __( 'Weekday-name shortcut: resolves to next occurrence after week_start_day (e.g. "sunday").', 'extrachill-events' ),
						),
						'location'         => array(
							'type'        => 'string',
							'description' => __( 'Location taxonomy term slug or numeric term ID. Optional.', 'extrachill-events' ),
						),
						'location_term_id' => array(
							'type'        => 'integer',
							'description' => __( 'Deprecated alias for location (numeric term ID). Use location instead.', 'extrachill-events' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'date_start'    => array( 'type' => 'string' ),
						'date_end'      => array( 'type' => 'string' ),
						'location_name' => array( 'type' => 'string' ),
						'total_events'  => array( 'type' => 'integer' ),
						'day_groups'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'event_summary' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeQuery' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerRenderAbility(): void {
		wp_register_ability(
			'extrachill/event-roundup-render',
			array(
				'label'               => __( 'Event Roundup Render', 'extrachill-events' ),
				'description'         => __( 'Render Instagram carousel images from day-grouped event data via the event_roundup template.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'day_groups'      => array(
							'type'        => 'array',
							'description' => __( 'Day-grouped events from event-roundup-query.', 'extrachill-events' ),
							'items'       => array( 'type' => 'object' ),
						),
						'title'           => array(
							'type'        => 'string',
							'description' => __( 'Optional title for first slide.', 'extrachill-events' ),
						),
						'storage_context' => array(
							'type'        => 'object',
							'description' => __( 'Optional storage context with pipeline_id and flow_id. When omitted, renders to temp files.', 'extrachill-events' ),
						),
					),
					'required'   => array( 'day_groups' ),
				),
				'output_schema'       => array(
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
				'execute_callback'    => array( $this, 'executeRender' ),
				'permission_callback' => function () {
					return current_user_can( 'upload_files' );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerBuildAbility(): void {
		wp_register_ability(
			'extrachill/event-roundup-build',
			array(
				'label'               => __( 'Event Roundup Build', 'extrachill-events' ),
				'description'         => __( 'One-shot: query events for a date range and location, then render the carousel slides. Returns image paths plus event metadata.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'date_start' => array(
							'type'        => 'string',
							'description' => __( 'Start date (Y-m-d). Defaults to today.', 'extrachill-events' ),
						),
						'date_end'   => array(
							'type'        => 'string',
							'description' => __( 'End date (Y-m-d). Defaults to date_start (single-day roundup).', 'extrachill-events' ),
						),
						'location'   => array(
							'type'        => 'string',
							'description' => __( 'Location taxonomy term slug or numeric term ID. Optional.', 'extrachill-events' ),
						),
						'title'      => array(
							'type'        => 'string',
							'description' => __( 'Optional title for the first slide.', 'extrachill-events' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'image_paths'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'slide_count'   => array( 'type' => 'integer' ),
						'date_start'    => array( 'type' => 'string' ),
						'date_end'      => array( 'type' => 'string' ),
						'location_name' => array( 'type' => 'string' ),
						'total_events'  => array( 'type' => 'integer' ),
						'event_summary' => array( 'type' => 'string' ),
						'message'       => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeBuild' ),
				'permission_callback' => function () {
					return current_user_can( 'upload_files' );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	public function executeQuery( array $input ): array|\WP_Error {
		$date_range = $this->resolveDateRange( $input );
		if ( \is_wp_error( $date_range ) ) {
			return $date_range;
		}

		$location_term_id = $this->resolveLocationTermId( $input );

		$day_groups = $this->queryEvents( $date_range['date_start'], $date_range['date_end'], $location_term_id );

		if ( empty( $day_groups ) ) {
			return array(
				'date_start'    => $date_range['date_start'],
				'date_end'      => $date_range['date_end'],
				'location_name' => $this->getLocationName( $location_term_id ),
				'total_events'  => 0,
				'day_groups'    => array(),
				'event_summary' => '',
			);
		}

		return array(
			'date_start'    => $date_range['date_start'],
			'date_end'      => $date_range['date_end'],
			'location_name' => $this->getLocationName( $location_term_id ),
			'total_events'  => $this->countEvents( $day_groups ),
			'day_groups'    => $day_groups,
			'event_summary' => $this->buildEventSummary( $day_groups ),
		);
	}

	public function executeRender( array $input ): array|\WP_Error {
		$day_groups      = $input['day_groups'] ?? array();
		$title           = (string) ( $input['title'] ?? '' );
		$storage_context = (array) ( $input['storage_context'] ?? array() );

		if ( empty( $day_groups ) ) {
			return new \WP_Error( 'missing_day_groups', __( 'day_groups is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new \WP_Error( 'abilities_unavailable', __( 'Abilities API not available.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		$ability = \wp_get_ability( 'datamachine/render-image-template' );
		if ( ! $ability ) {
			return new \WP_Error( 'template_renderer_missing', __( 'datamachine/render-image-template ability not registered.', 'extrachill-events' ), array( 'status' => 500 ) );
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
			return new \WP_Error( 'generation_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}

		$image_paths = (array) ( $result['file_paths'] ?? array() );

		if ( empty( $image_paths ) ) {
			return new \WP_Error( 'generation_failed', __( 'Failed to generate carousel images.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		return array(
			'success'     => true,
			'image_paths' => $image_paths,
			'slide_count' => count( $image_paths ),
			'message'     => sprintf(
				/* translators: %d: number of generated slides */
				__( 'Generated %d carousel slides.', 'extrachill-events' ),
				count( $image_paths )
			),
		);
	}

	public function executeBuild( array $input ): array|\WP_Error {
		// Default date_start to today if neither dates nor weekday names provided.
		if ( empty( $input['date_start'] ) && empty( $input['week_start_day'] ) ) {
			$input['date_start'] = ( new \DateTime( 'now', \wp_timezone() ) )->format( 'Y-m-d' );
		}

		$query_result = $this->executeQuery( $input );
		if ( \is_wp_error( $query_result ) ) {
			return $query_result;
		}

		if ( empty( $query_result['day_groups'] ) ) {
			return array(
				'success'       => false,
				'image_paths'   => array(),
				'slide_count'   => 0,
				'date_start'    => $query_result['date_start'],
				'date_end'      => $query_result['date_end'],
				'location_name' => $query_result['location_name'],
				'total_events'  => 0,
				'event_summary' => '',
				'message'       => __( 'No events found for the requested date range and location.', 'extrachill-events' ),
			);
		}

		$render_result = $this->executeRender( array(
			'day_groups' => $query_result['day_groups'],
			'title'      => (string) ( $input['title'] ?? '' ),
		) );
		if ( \is_wp_error( $render_result ) ) {
			return $render_result;
		}

		return array(
			'success'       => true,
			'image_paths'   => $render_result['image_paths'],
			'slide_count'   => $render_result['slide_count'],
			'date_start'    => $query_result['date_start'],
			'date_end'      => $query_result['date_end'],
			'location_name' => $query_result['location_name'],
			'total_events'  => $query_result['total_events'],
			'event_summary' => $query_result['event_summary'],
			'message'       => $render_result['message'],
		);
	}

	/**
	 * Resolve date_start / date_end from inputs.
	 *
	 * Precedence: explicit Y-m-d dates > weekday-name shortcuts > error.
	 * date_end defaults to date_start (single-day roundup).
	 *
	 * @return array{date_start: string, date_end: string}|\WP_Error
	 */
	private function resolveDateRange( array $input ): array|\WP_Error {
		$date_start = isset( $input['date_start'] ) ? trim( (string) $input['date_start'] ) : '';
		$date_end   = isset( $input['date_end'] ) ? trim( (string) $input['date_end'] ) : '';

		if ( '' !== $date_start ) {
			if ( ! $this->isValidDate( $date_start ) ) {
				return new \WP_Error(
					'invalid_date_start',
					sprintf(
						/* translators: %s: invalid date string */
						__( 'date_start "%s" is not a valid Y-m-d date.', 'extrachill-events' ),
						$date_start
					),
					array( 'status' => 400 )
				);
			}

			if ( '' === $date_end ) {
				$date_end = $date_start;
			} elseif ( ! $this->isValidDate( $date_end ) ) {
				return new \WP_Error(
					'invalid_date_end',
					sprintf(
						/* translators: %s: invalid date string */
						__( 'date_end "%s" is not a valid Y-m-d date.', 'extrachill-events' ),
						$date_end
					),
					array( 'status' => 400 )
				);
			}

			if ( $date_end < $date_start ) {
				return new \WP_Error( 'invalid_date_range', __( 'date_end must be on or after date_start.', 'extrachill-events' ), array( 'status' => 400 ) );
			}

			return array(
				'date_start' => $date_start,
				'date_end'   => $date_end,
			);
		}

		$week_start_day = isset( $input['week_start_day'] ) ? strtolower( trim( (string) $input['week_start_day'] ) ) : '';
		$week_end_day   = isset( $input['week_end_day'] ) ? strtolower( trim( (string) $input['week_end_day'] ) ) : '';

		if ( '' !== $week_start_day && '' !== $week_end_day ) {
			return $this->resolveNextWeekdayRange( $week_start_day, $week_end_day );
		}

		return new \WP_Error(
			'missing_date_inputs',
			__( 'Provide date_start (Y-m-d) or both week_start_day and week_end_day.', 'extrachill-events' ),
			array( 'status' => 400 )
		);
	}

	private function resolveNextWeekdayRange( string $week_start_day, string $week_end_day ): array {
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

	private function isValidDate( string $date ): bool {
		$parsed = \DateTime::createFromFormat( 'Y-m-d', $date );
		return $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Resolve a `location` input (slug or numeric ID) into a term ID.
	 *
	 * Returns 0 when no location filter requested. Falls back to the
	 * legacy `location_term_id` integer input when `location` isn't set.
	 */
	private function resolveLocationTermId( array $input ): int {
		$location = $input['location'] ?? null;
		if ( null !== $location && '' !== $location ) {
			if ( is_numeric( $location ) ) {
				return (int) $location;
			}

			$term = \get_term_by( 'slug', (string) $location, 'location' );
			if ( $term && ! \is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}

			$term = \get_term_by( 'name', (string) $location, 'location' );
			if ( $term && ! \is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}

			return 0;
		}

		return (int) ( $input['location_term_id'] ?? 0 );
	}

	private function queryEvents( string $date_start, string $date_end, int $location_term_id ): array {
		$query_input = array(
			'date_start' => $date_start,
			'date_end'   => $date_end,
			'per_page'   => -1,
			'order'      => 'ASC',
		);

		if ( $location_term_id > 0 ) {
			$query_input['tax_filters'] = array(
				'location' => array( $location_term_id ),
			);
		}

		$ability = new \DataMachineEvents\Abilities\EventDateQueryAbilities();
		$result  = $ability->executeQueryEvents( $query_input );

		if ( ! class_exists( '\DataMachineEvents\Blocks\Calendar\Data\EventHydrator' )
			|| ! class_exists( '\DataMachineEvents\Blocks\Calendar\Grouping\DateGrouper' ) ) {
			return array();
		}

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
