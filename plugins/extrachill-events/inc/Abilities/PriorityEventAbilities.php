<?php
/**
 * Priority Event Abilities
 *
 * WordPress 6.9 Abilities API for managing priority events via CLI/Homeboy.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PriorityEventAbilities {

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
			'extrachill/list-priority-events',
			array(
				'label'               => __( 'List Priority Events', 'extrachill-events' ),
				'description'         => __( 'List all events marked as priority for calendar sorting.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'events' => array(
							'type'        => 'array',
							'description' => __( 'Array of priority event objects.', 'extrachill-events' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'post_id' => array(
										'type'        => 'integer',
										'description' => __( 'Event post ID.', 'extrachill-events' ),
									),
									'title'   => array(
										'type'        => 'string',
										'description' => __( 'Event title.', 'extrachill-events' ),
									),
									'slug'    => array(
										'type'        => 'string',
										'description' => __( 'Event URL slug.', 'extrachill-events' ),
									),
									'date'    => array(
										'type'        => 'string',
										'description' => __( 'Event date.', 'extrachill-events' ),
									),
								),
							),
						),
						'count'  => array(
							'type'        => 'integer',
							'description' => __( 'Total number of priority events.', 'extrachill-events' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'listPriorityEvents' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'     => true,
						'idempotent'   => true,
						'destructive'  => false,
						'instructions' => __( 'Returns all events marked as priority. Priority events appear first in calendar day groups, ahead of priority venue events and regular events.', 'extrachill-events' ),
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/set-priority-event',
			array(
				'label'               => __( 'Set Priority Event', 'extrachill-events' ),
				'description'         => __( 'Mark or unmark an event as priority for calendar sorting.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'event'    => array(
							'type'        => 'string',
							'description' => __( 'Event post slug or numeric ID.', 'extrachill-events' ),
						),
						'priority' => array(
							'type'        => 'boolean',
							'description' => __( 'True to mark as priority, false to remove priority status.', 'extrachill-events' ),
							'default'     => true,
						),
					),
					'required'   => array( 'event' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the operation succeeded.', 'extrachill-events' ),
						),
						'event'   => array(
							'type'        => 'object',
							'description' => __( 'Updated event data.', 'extrachill-events' ),
							'properties'  => array(
								'post_id'  => array(
									'type'        => 'integer',
									'description' => __( 'Event post ID.', 'extrachill-events' ),
								),
								'title'    => array(
									'type'        => 'string',
									'description' => __( 'Event title.', 'extrachill-events' ),
								),
								'priority' => array(
									'type'        => 'boolean',
									'description' => __( 'Current priority status.', 'extrachill-events' ),
								),
							),
						),
						'message' => array(
							'type'        => 'string',
							'description' => __( 'Human-readable result message.', 'extrachill-events' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'setPriorityEvent' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'     => false,
						'idempotent'   => true,
						'destructive'  => false,
						'instructions' => __( 'Set or remove priority status for an event. Priority events appear first in calendar day groups, ahead of priority venue events and regular events.', 'extrachill-events' ),
					),
				),
			)
		);
	}

	public function listPriorityEvents( array $input ): array {
		$ids = extrachill_get_priority_event_ids();

		if ( empty( $ids ) ) {
			return array(
				'events' => array(),
				'count'  => 0,
			);
		}

		$posts = get_posts(
			array(
				'post_type'      => 'data_machine_events',
				'post__in'       => $ids,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$events = array();
		foreach ( $posts as $post ) {
			$event_date = get_post_meta( $post->ID, '_event_date', true );
			$events[]   = array(
				'post_id' => $post->ID,
				'title'   => $post->post_title,
				'slug'    => $post->post_name,
				'date'    => $event_date ? $event_date : '',
			);
		}

		return array(
			'events' => $events,
			'count'  => count( $events ),
		);
	}

	public function setPriorityEvent( array $input ): array|\WP_Error {
		$event = $input['event'] ?? '';

		if ( empty( $event ) ) {
			return new \WP_Error(
				'missing_event',
				__( 'Event identifier is required.', 'extrachill-events' ),
				array( 'status' => 400 )
			);
		}

		$priority = $input['priority'] ?? true;

		$post = is_numeric( $event )
			? get_post( (int) $event )
			: get_page_by_path( $event, OBJECT, 'data_machine_events' );

		if ( ! $post || 'data_machine_events' !== $post->post_type ) {
			return new \WP_Error(
				'event_not_found',
				sprintf(
					/* translators: %s: event identifier */
					__( 'Event "%s" not found.', 'extrachill-events' ),
					$event
				),
				array( 'status' => 404 )
			);
		}

		if ( $priority ) {
			update_post_meta( $post->ID, '_extrachill_priority_event', true );
		} else {
			delete_post_meta( $post->ID, '_extrachill_priority_event' );
		}

		wp_cache_delete( 'extrachill_priority_event_ids', 'extrachill-events' );

		return array(
			'success' => true,
			'event'   => array(
				'post_id'  => $post->ID,
				'title'    => $post->post_title,
				'priority' => $priority,
			),
			'message' => $priority
				? sprintf(
					/* translators: %s: event title */
					__( '%s marked as priority event.', 'extrachill-events' ),
					$post->post_title
				)
				: sprintf(
					/* translators: %s: event title */
					__( '%s removed from priority events.', 'extrachill-events' ),
					$post->post_title
				),
		);
	}
}
