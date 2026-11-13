<?php
/**
 * Priority Term Abilities
 *
 * WordPress 6.9 Abilities API for marking venue and promoter terms as
 * priority via CLI/Homeboy. Both kinds share one registration and execution
 * path; each definition supplies only its taxonomy, storage helpers and copy.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PriorityTermAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/**
	 * Per-kind definitions. Copy stays literal so every string is translatable.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function definitions(): array {
		return array(
			'venue'    => array(
				'plural'       => 'venues',
				'ids'          => 'ec_get_priority_venue_ids',
				'set'          => 'ec_set_priority_venue',
				'reports_flag' => false,
				'copy'         => array(
					'list_label'        => __( 'List Priority Venues', 'extrachill-events' ),
					'list_description'  => __( 'List all venues marked as priority for calendar sorting.', 'extrachill-events' ),
					'list_items'        => __( 'Array of priority venue objects.', 'extrachill-events' ),
					'term_id'           => __( 'Venue term ID.', 'extrachill-events' ),
					'name'              => __( 'Venue display name.', 'extrachill-events' ),
					'slug'              => __( 'Venue URL slug.', 'extrachill-events' ),
					'count'             => __( 'Total number of priority venues.', 'extrachill-events' ),
					'list_instructions' => __( 'Returns all venues marked as priority. Priority venues appear first in single-location calendar views.', 'extrachill-events' ),
					'set_label'         => __( 'Set Priority Venue', 'extrachill-events' ),
					'set_description'   => __( 'Mark or unmark a venue as priority for calendar sorting.', 'extrachill-events' ),
					'identifier'        => __( 'Venue term slug or numeric ID.', 'extrachill-events' ),
					'updated'           => __( 'Updated venue data.', 'extrachill-events' ),
					'set_instructions'  => __( 'Set or remove priority status for a venue. Priority venues appear first in single-location calendar views (location archives, location filters). Has no effect on multi-location calendar views.', 'extrachill-events' ),
					'missing'           => __( 'Venue identifier is required.', 'extrachill-events' ),
					/* translators: %s: venue identifier */
					'not_found'         => __( 'Venue "%s" not found.', 'extrachill-events' ),
					/* translators: %s: venue name */
					'marked'            => __( '%s marked as priority venue.', 'extrachill-events' ),
					/* translators: %s: venue name */
					'removed'           => __( '%s removed from priority venues.', 'extrachill-events' ),
				),
			),
			'promoter' => array(
				'plural'       => 'promoters',
				'ids'          => 'ec_get_priority_promoter_ids',
				'set'          => 'ec_set_priority_promoter',
				'reports_flag' => true,
				'copy'         => array(
					'list_label'        => __( 'List Priority Promoters', 'extrachill-events' ),
					'list_description'  => __( 'List all promoters whose events are automatically marked as priority.', 'extrachill-events' ),
					'list_items'        => __( 'Array of priority promoter objects.', 'extrachill-events' ),
					'term_id'           => __( 'Promoter term ID.', 'extrachill-events' ),
					'name'              => __( 'Promoter display name.', 'extrachill-events' ),
					'slug'              => __( 'Promoter URL slug.', 'extrachill-events' ),
					'count'             => __( 'Total number of priority promoters.', 'extrachill-events' ),
					'list_instructions' => __( 'Returns all promoters marked as priority. Events assigned a priority promoter are automatically marked as priority events.', 'extrachill-events' ),
					'set_label'         => __( 'Set Priority Promoter', 'extrachill-events' ),
					'set_description'   => __( 'Mark or unmark a promoter as priority. Events from priority promoters are automatically marked as priority events.', 'extrachill-events' ),
					'identifier'        => __( 'Promoter term slug or numeric ID.', 'extrachill-events' ),
					'updated'           => __( 'Updated promoter data.', 'extrachill-events' ),
					'set_instructions'  => __( 'Set or remove priority status for a promoter. Marking a promoter as priority flags its upcoming published events as priority events, and any event later assigned the promoter is flagged automatically. Removing priority status never clears existing event priority flags.', 'extrachill-events' ),
					'missing'           => __( 'Promoter identifier is required.', 'extrachill-events' ),
					/* translators: %s: promoter identifier */
					'not_found'         => __( 'Promoter "%s" not found.', 'extrachill-events' ),
					/* translators: 1: promoter name, 2: number of events flagged */
					'marked'            => __( '%1$s marked as priority promoter (%2$d upcoming events flagged).', 'extrachill-events' ),
					/* translators: %s: promoter name */
					'removed'           => __( '%s removed from priority promoters. Existing event priority flags are unchanged.', 'extrachill-events' ),
				),
			),
		);
	}

	public function register(): void {
		foreach ( self::definitions() as $kind => $definition ) {
			$this->register_kind( $kind, $definition );
		}
	}

	private function register_kind( string $kind, array $definition ): void {
		$copy       = $definition['copy'];
		$plural     = $definition['plural'];
		$permission = function () {
			return current_user_can( 'manage_categories' );
		};

		wp_register_ability(
			'extrachill/list-priority-' . $plural,
			array(
				'label'               => $copy['list_label'],
				'description'         => $copy['list_description'],
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						$plural => array(
							'type'        => 'array',
							'description' => $copy['list_items'],
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'term_id' => array(
										'type'        => 'integer',
										'description' => $copy['term_id'],
									),
									'name'    => array(
										'type'        => 'string',
										'description' => $copy['name'],
									),
									'slug'    => array(
										'type'        => 'string',
										'description' => $copy['slug'],
									),
								),
							),
						),
						'count' => array(
							'type'        => 'integer',
							'description' => $copy['count'],
						),
					),
				),
				'execute_callback'    => function ( $input = array() ) use ( $kind ) {
					return $this->list_terms( $kind, (array) $input );
				},
				'permission_callback' => $permission,
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'     => true,
						'idempotent'   => true,
						'destructive'  => false,
						'instructions' => $copy['list_instructions'],
					),
				),
			)
		);

		$output = array(
			'success' => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the operation succeeded.', 'extrachill-events' ),
			),
			$kind     => array(
				'type'        => 'object',
				'description' => $copy['updated'],
				'properties'  => array(
					'term_id'  => array(
						'type'        => 'integer',
						'description' => $copy['term_id'],
					),
					'name'     => array(
						'type'        => 'string',
						'description' => $copy['name'],
					),
					'priority' => array(
						'type'        => 'boolean',
						'description' => __( 'Current priority status.', 'extrachill-events' ),
					),
				),
			),
		);
		if ( $definition['reports_flag'] ) {
			$output['events_flagged'] = array(
				'type'        => 'integer',
				'description' => __( 'Upcoming published events newly marked as priority by the backfill.', 'extrachill-events' ),
			);
		}
		$output['message'] = array(
			'type'        => 'string',
			'description' => __( 'Human-readable result message.', 'extrachill-events' ),
		);

		wp_register_ability(
			'extrachill/set-priority-' . $kind,
			array(
				'label'               => $copy['set_label'],
				'description'         => $copy['set_description'],
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						$kind      => array(
							'type'        => 'string',
							'description' => $copy['identifier'],
						),
						'priority' => array(
							'type'        => 'boolean',
							'description' => __( 'True to mark as priority, false to remove priority status.', 'extrachill-events' ),
							'default'     => true,
						),
					),
					'required'   => array( $kind ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => $output,
				),
				'execute_callback'    => function ( $input = array() ) use ( $kind ) {
					return $this->set_term( $kind, (array) $input );
				},
				'permission_callback' => $permission,
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'     => false,
						'idempotent'   => true,
						'destructive'  => false,
						'instructions' => $copy['set_instructions'],
					),
				),
			)
		);
	}

	/**
	 * List priority terms of one kind.
	 *
	 * @param string $kind  `venue` or `promoter`.
	 * @param array  $input Ability input (unused).
	 */
	public function list_terms( string $kind, array $input ): array {
		unset( $input );
		$definition = self::definitions()[ $kind ];
		$plural     = $definition['plural'];
		$ids        = call_user_func( $definition['ids'] );

		if ( empty( $ids ) ) {
			return array(
				$plural => array(),
				'count' => 0,
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $kind,
				'include'    => $ids,
				'hide_empty' => false,
			)
		);

		$items = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$items[] = array(
				'term_id' => $term->term_id,
				'name'    => $term->name,
				'slug'    => $term->slug,
			);
		}

		return array(
			$plural => $items,
			'count' => count( $items ),
		);
	}

	/**
	 * Mark or unmark one term of one kind as priority.
	 *
	 * @param string $kind  `venue` or `promoter`.
	 * @param array  $input Ability input.
	 * @return array|\WP_Error
	 */
	public function set_term( string $kind, array $input ): array|\WP_Error {
		$definition = self::definitions()[ $kind ];
		$copy       = $definition['copy'];
		$identifier = $input[ $kind ] ?? '';

		if ( empty( $identifier ) ) {
			return new \WP_Error( 'missing_' . $kind, $copy['missing'], array( 'status' => 400 ) );
		}

		$priority = (bool) ( $input['priority'] ?? true );
		$term     = is_numeric( $identifier )
			? get_term( (int) $identifier, $kind )
			: get_term_by( 'slug', $identifier, $kind );

		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( $kind . '_not_found', sprintf( $copy['not_found'], $identifier ), array( 'status' => 404 ) );
		}

		$flagged = (int) call_user_func( $definition['set'], (int) $term->term_id, $priority );
		$result  = array(
			'success' => true,
			$kind     => array(
				'term_id'  => $term->term_id,
				'name'     => $term->name,
				'priority' => $priority,
			),
		);
		if ( $definition['reports_flag'] ) {
			$result['events_flagged'] = $flagged;
		}
		$result['message'] = $priority
			? sprintf( $copy['marked'], $term->name, $flagged )
			: sprintf( $copy['removed'], $term->name );

		return $result;
	}
}
