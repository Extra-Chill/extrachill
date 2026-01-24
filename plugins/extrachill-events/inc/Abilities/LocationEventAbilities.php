<?php
/**
 * Location Event Abilities
 *
 * WordPress 6.9 Abilities API integration for location-based event queries.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use DataMachineEvents\Blocks\Calendar\Calendar_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LocationEventAbilities {

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
		wp_register_ability(
			'extrachill/get-location-events',
			array(
				'label'        => __( 'Get Location Events', 'extrachill-events' ),
				'description'  => __( 'Query events by Extra Chill location taxonomy.', 'extrachill-events' ),
				'category'     => 'extrachill-events',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'location' => array(
							'type'        => 'string',
							'description' => __( 'Location term slug or ID.', 'extrachill-events' ),
						),
						'date_start' => array(
							'type'        => 'string',
							'description' => __( 'Optional start date (Y-m-d). Defaults to today.', 'extrachill-events' ),
						),
						'date_end' => array(
							'type'        => 'string',
							'description' => __( 'Optional end date (Y-m-d). Defaults to 30 days from start.', 'extrachill-events' ),
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => __( 'Maximum events to return (default: 50).', 'extrachill-events' ),
							'default'     => 50,
						),
					),
					'required' => array( 'location' ),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'location' => array(
							'type'       => 'object',
							'properties' => array(
								'term_id' => array( 'type' => 'integer' ),
								'name'    => array( 'type' => 'string' ),
								'slug'    => array( 'type' => 'string' ),
							),
						),
						'events' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'         => array( 'type' => 'integer' ),
									'title'      => array( 'type' => 'string' ),
									'date'       => array( 'type' => 'string' ),
									'start_time' => array( 'type' => 'string' ),
									'venue'      => array( 'type' => 'string' ),
									'permalink'  => array( 'type' => 'string' ),
								),
							),
						),
						'returned_count' => array( 'type' => 'integer' ),
						'message'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetLocationEvents' ),
				'permission_callback' => function() {
					return current_user_can( 'read' );
				},
				'meta' => array( 'show_in_rest' => true ),
			)
		);
	}

	public function executeGetLocationEvents( array $input ): array {
		$location   = $input['location'] ?? '';
		$date_start = $input['date_start'] ?? '';
		$date_end   = $input['date_end'] ?? '';
		$limit      = $input['limit'] ?? 50;

		if ( empty( $location ) ) {
			return array(
				'error'   => true,
				'message' => __( 'location is required.', 'extrachill-events' ),
			);
		}

		$term = $this->resolveLocationTerm( $location );
		if ( ! $term ) {
			return array(
				'location'       => null,
				'events'         => array(),
				'returned_count' => 0,
				'message'        => sprintf(
					__( 'Location "%s" not found.', 'extrachill-events' ),
					$location
				),
			);
		}

		if ( empty( $date_start ) ) {
			$date_start = ( new \DateTime( 'now', wp_timezone() ) )->format( 'Y-m-d' );
		}

		if ( empty( $date_end ) ) {
			$date_end = ( new \DateTime( $date_start, wp_timezone() ) )
				->modify( '+30 days' )
				->format( 'Y-m-d' );
		}

		$events = $this->queryEventsByLocation( $term->term_id, $date_start, $date_end, $limit );

		return array(
			'location' => array(
				'term_id' => $term->term_id,
				'name'    => $term->name,
				'slug'    => $term->slug,
			),
			'events'         => $events,
			'returned_count' => count( $events ),
			'message'        => sprintf(
				__( 'Found %d events in %s.', 'extrachill-events' ),
				count( $events ),
				$term->name
			),
		);
	}

	private function resolveLocationTerm( string $location ): ?\WP_Term {
		if ( is_numeric( $location ) ) {
			$term = get_term( (int) $location, 'location' );
		} else {
			$term = get_term_by( 'slug', $location, 'location' );
		}

		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		return $term;
	}

	private function queryEventsByLocation( int $term_id, string $date_start, string $date_end, int $limit ): array {
		if ( ! class_exists( 'DataMachineEvents\Blocks\Calendar\Calendar_Query' ) ) {
			return array();
		}

		$params = array(
			'date_start'  => $date_start,
			'date_end'    => $date_end,
			'show_past'   => false,
			'tax_filters' => array(
				'location' => array( $term_id ),
			),
		);

		$query_args                  = Calendar_Query::build_query_args( $params );
		$query_args['posts_per_page'] = $limit;

		$query        = new \WP_Query( $query_args );
		$paged_events = Calendar_Query::build_paged_events( $query );

		$events = array();
		foreach ( $paged_events as $event_item ) {
			$post       = $event_item['post'] ?? null;
			$event_data = $event_item['event_data'] ?? array();

			if ( ! $post ) {
				continue;
			}

			$start_time     = $event_data['startTime'] ?? '';
			$formatted_time = '';
			if ( $start_time ) {
				$time_obj = \DateTime::createFromFormat( 'H:i:s', $start_time );
				if ( $time_obj ) {
					$formatted_time = $time_obj->format( 'g:i A' );
				}
			}

			$events[] = array(
				'id'         => $post->ID,
				'title'      => $post->post_title,
				'date'       => $event_data['startDate'] ?? '',
				'start_time' => $formatted_time,
				'venue'      => $event_data['venue'] ?? '',
				'permalink'  => get_permalink( $post->ID ),
			);
		}

		return $events;
	}
}
