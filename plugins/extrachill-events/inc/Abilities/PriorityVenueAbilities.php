<?php
/**
 * Priority Venue Abilities
 *
 * WordPress 6.9 Abilities API for managing priority venues via CLI/Homeboy.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PriorityVenueAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'registerCategory' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	public function registerCategory(): void {
		wp_register_ability_category(
			'extrachill-events',
			array(
				'label'       => __( 'Extra Chill Events', 'extrachill-events' ),
				'description' => __( 'Event management capabilities', 'extrachill-events' ),
			)
		);
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/list-priority-venues',
			array(
				'label'        => __( 'List Priority Venues', 'extrachill-events' ),
				'description'  => __( 'List all venues marked as priority for calendar sorting.', 'extrachill-events' ),
				'category'     => 'extrachill-events',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'venues' => array(
							'type'        => 'array',
							'description' => __( 'Array of priority venue objects.', 'extrachill-events' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'term_id' => array(
										'type'        => 'integer',
										'description' => __( 'Venue term ID.', 'extrachill-events' ),
									),
									'name'    => array(
										'type'        => 'string',
										'description' => __( 'Venue display name.', 'extrachill-events' ),
									),
									'slug'    => array(
										'type'        => 'string',
										'description' => __( 'Venue URL slug.', 'extrachill-events' ),
									),
								),
							),
						),
						'count'  => array(
							'type'        => 'integer',
							'description' => __( 'Total number of priority venues.', 'extrachill-events' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'listPriorityVenues' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_categories' );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'     => true,
						'idempotent'   => true,
						'destructive'  => false,
						'instructions' => __( 'Returns all venues marked as priority. Priority venues appear first in single-location calendar views.', 'extrachill-events' ),
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/set-priority-venue',
			array(
				'label'        => __( 'Set Priority Venue', 'extrachill-events' ),
				'description'  => __( 'Mark or unmark a venue as priority for calendar sorting.', 'extrachill-events' ),
				'category'     => 'extrachill-events',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'venue'    => array(
							'type'        => 'string',
							'description' => __( 'Venue term slug or numeric ID.', 'extrachill-events' ),
						),
						'priority' => array(
							'type'        => 'boolean',
							'description' => __( 'True to mark as priority, false to remove priority status.', 'extrachill-events' ),
							'default'     => true,
						),
					),
					'required'   => array( 'venue' ),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the operation succeeded.', 'extrachill-events' ),
						),
						'venue'   => array(
							'type'        => 'object',
							'description' => __( 'Updated venue data.', 'extrachill-events' ),
							'properties'  => array(
								'term_id'  => array(
									'type'        => 'integer',
									'description' => __( 'Venue term ID.', 'extrachill-events' ),
								),
								'name'     => array(
									'type'        => 'string',
									'description' => __( 'Venue display name.', 'extrachill-events' ),
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
				'execute_callback'    => array( $this, 'setPriorityVenue' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_categories' );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'     => false,
						'idempotent'   => true,
						'destructive'  => false,
						'instructions' => __( 'Set or remove priority status for a venue. Priority venues appear first in single-location calendar views (location archives, location filters). Has no effect on multi-location calendar views.', 'extrachill-events' ),
					),
				),
			)
		);
	}

	public function listPriorityVenues( array $input ): array {
		$ids = ec_get_priority_venue_ids();

		if ( empty( $ids ) ) {
			return array(
				'venues' => array(),
				'count'  => 0,
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'venue',
				'include'    => $ids,
				'hide_empty' => false,
			)
		);

		$venues = array();
		foreach ( $terms as $term ) {
			$venues[] = array(
				'term_id' => $term->term_id,
				'name'    => $term->name,
				'slug'    => $term->slug,
			);
		}

		return array(
			'venues' => $venues,
			'count'  => count( $venues ),
		);
	}

	public function setPriorityVenue( array $input ): array|\WP_Error {
		$venue = $input['venue'] ?? '';

		if ( empty( $venue ) ) {
			return new \WP_Error(
				'missing_venue',
				__( 'Venue identifier is required.', 'extrachill-events' ),
				array( 'status' => 400 )
			);
		}

		$priority = $input['priority'] ?? true;

		$term = is_numeric( $venue )
			? get_term( (int) $venue, 'venue' )
			: get_term_by( 'slug', $venue, 'venue' );

		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error(
				'venue_not_found',
				sprintf(
					/* translators: %s: venue identifier */
					__( 'Venue "%s" not found.', 'extrachill-events' ),
					$venue
				),
				array( 'status' => 404 )
			);
		}

		if ( $priority ) {
			update_term_meta( $term->term_id, '_ec_priority_venue', true );
		} else {
			delete_term_meta( $term->term_id, '_ec_priority_venue' );
		}

		wp_cache_delete( 'ec_priority_venue_ids', 'extrachill-events' );

		return array(
			'success' => true,
			'venue'   => array(
				'term_id'  => $term->term_id,
				'name'     => $term->name,
				'priority' => $priority,
			),
			'message' => $priority
				? sprintf(
					/* translators: %s: venue name */
					__( '%s marked as priority venue.', 'extrachill-events' ),
					$term->name
				)
				: sprintf(
					/* translators: %s: venue name */
					__( '%s removed from priority venues.', 'extrachill-events' ),
					$term->name
				),
		);
	}
}
