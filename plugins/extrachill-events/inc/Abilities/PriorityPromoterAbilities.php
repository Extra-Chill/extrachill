<?php
/**
 * Priority Promoter Abilities
 *
 * WordPress 6.9 Abilities API for managing priority promoters via CLI/Homeboy.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PriorityPromoterAbilities {

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
			'extrachill/list-priority-promoters',
			array(
				'label'               => __( 'List Priority Promoters', 'extrachill-events' ),
				'description'         => __( 'List all promoters whose events are automatically marked as priority.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'promoters' => array(
							'type'        => 'array',
							'description' => __( 'Array of priority promoter objects.', 'extrachill-events' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'term_id' => array(
										'type'        => 'integer',
										'description' => __( 'Promoter term ID.', 'extrachill-events' ),
									),
									'name'    => array(
										'type'        => 'string',
										'description' => __( 'Promoter display name.', 'extrachill-events' ),
									),
									'slug'    => array(
										'type'        => 'string',
										'description' => __( 'Promoter URL slug.', 'extrachill-events' ),
									),
								),
							),
						),
						'count'     => array(
							'type'        => 'integer',
							'description' => __( 'Total number of priority promoters.', 'extrachill-events' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'listPriorityPromoters' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_categories' );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'     => true,
						'idempotent'   => true,
						'destructive'  => false,
						'instructions' => __( 'Returns all promoters marked as priority. Events assigned a priority promoter are automatically marked as priority events.', 'extrachill-events' ),
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/set-priority-promoter',
			array(
				'label'               => __( 'Set Priority Promoter', 'extrachill-events' ),
				'description'         => __( 'Mark or unmark a promoter as priority. Events from priority promoters are automatically marked as priority events.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'promoter' => array(
							'type'        => 'string',
							'description' => __( 'Promoter term slug or numeric ID.', 'extrachill-events' ),
						),
						'priority' => array(
							'type'        => 'boolean',
							'description' => __( 'True to mark as priority, false to remove priority status.', 'extrachill-events' ),
							'default'     => true,
						),
					),
					'required'   => array( 'promoter' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'        => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the operation succeeded.', 'extrachill-events' ),
						),
						'promoter'       => array(
							'type'        => 'object',
							'description' => __( 'Updated promoter data.', 'extrachill-events' ),
							'properties'  => array(
								'term_id'  => array(
									'type'        => 'integer',
									'description' => __( 'Promoter term ID.', 'extrachill-events' ),
								),
								'name'     => array(
									'type'        => 'string',
									'description' => __( 'Promoter display name.', 'extrachill-events' ),
								),
								'priority' => array(
									'type'        => 'boolean',
									'description' => __( 'Current priority status.', 'extrachill-events' ),
								),
							),
						),
						'events_flagged' => array(
							'type'        => 'integer',
							'description' => __( 'Upcoming published events newly marked as priority by the backfill.', 'extrachill-events' ),
						),
						'message'        => array(
							'type'        => 'string',
							'description' => __( 'Human-readable result message.', 'extrachill-events' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'setPriorityPromoter' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_categories' );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'     => false,
						'idempotent'   => true,
						'destructive'  => false,
						'instructions' => __( 'Set or remove priority status for a promoter. Marking a promoter as priority flags its upcoming published events as priority events, and any event later assigned the promoter is flagged automatically. Removing priority status never clears existing event priority flags.', 'extrachill-events' ),
					),
				),
			)
		);
	}

	public function listPriorityPromoters( array $input ): array {
		unset( $input );
		$ids = ec_get_priority_promoter_ids();

		if ( empty( $ids ) ) {
			return array(
				'promoters' => array(),
				'count'     => 0,
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'promoter',
				'include'    => $ids,
				'hide_empty' => false,
			)
		);

		$promoters = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$promoters[] = array(
				'term_id' => $term->term_id,
				'name'    => $term->name,
				'slug'    => $term->slug,
			);
		}

		return array(
			'promoters' => $promoters,
			'count'     => count( $promoters ),
		);
	}

	public function setPriorityPromoter( array $input ): array|\WP_Error {
		$promoter = $input['promoter'] ?? '';

		if ( empty( $promoter ) ) {
			return new \WP_Error(
				'missing_promoter',
				__( 'Promoter identifier is required.', 'extrachill-events' ),
				array( 'status' => 400 )
			);
		}

		$priority = (bool) ( $input['priority'] ?? true );

		$term = is_numeric( $promoter )
			? get_term( (int) $promoter, 'promoter' )
			: get_term_by( 'slug', $promoter, 'promoter' );

		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error(
				'promoter_not_found',
				sprintf(
					/* translators: %s: promoter identifier */
					__( 'Promoter "%s" not found.', 'extrachill-events' ),
					$promoter
				),
				array( 'status' => 404 )
			);
		}

		$flagged = ec_set_priority_promoter( (int) $term->term_id, $priority );

		return array(
			'success'        => true,
			'promoter'       => array(
				'term_id'  => $term->term_id,
				'name'     => $term->name,
				'priority' => $priority,
			),
			'events_flagged' => $flagged,
			'message'        => $priority
				? sprintf(
					/* translators: 1: promoter name, 2: number of events flagged */
					__( '%1$s marked as priority promoter (%2$d upcoming events flagged).', 'extrachill-events' ),
					$term->name,
					$flagged
				)
				: sprintf(
					/* translators: %s: promoter name */
					__( '%s removed from priority promoters. Existing event priority flags are unchanged.', 'extrachill-events' ),
					$term->name
				),
		);
	}
}
