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
		\add_action( 'wp_abilities_api_init', array( $this, 'register' ), 10, 1 );
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

		wp_register_ability(
			'extrachill/grant-event-priority-boost',
			array(
				'label'               => __( 'Grant Event Priority Boost', 'extrachill-events' ),
				'description'         => __( 'Idempotently grant paid priority to a canonical event.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'event'              => array(
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => 191,
							'description' => __( 'Canonical event post slug or numeric ID.', 'extrachill-events' ),
						),
						'external_reference' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => 191,
							'description' => __( 'Opaque reference for the external purchase or grant.', 'extrachill-events' ),
						),
						'idempotency_key'    => array(
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => 191,
							'description' => __( 'Stable key identifying this grant operation.', 'extrachill-events' ),
						),
					),
					'required'   => array( 'event', 'external_reference', 'idempotency_key' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'                     => array( 'type' => 'boolean' ),
						'replayed'                    => array( 'type' => 'boolean' ),
						'existing_priority_preserved' => array( 'type' => 'boolean' ),
						'event'                       => array(
							'type'       => 'object',
							'properties' => array(
								'post_id'  => array( 'type' => 'integer' ),
								'title'    => array( 'type' => 'string' ),
								'slug'     => array( 'type' => 'string' ),
								'priority' => array( 'type' => 'boolean' ),
							),
						),
						'receipt'                     => array(
							'type'       => 'object',
							'properties' => array(
								'operation_id' => array( 'type' => 'string' ),
								'actor_id'     => array( 'type' => 'integer' ),
								'granted_at'   => array( 'type' => 'string' ),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'grant_priority_boost' ),
				'permission_callback' => array( $this, 'can_grant_priority_boost' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'     => false,
						'idempotent'   => true,
						'destructive'  => false,
						'instructions' => __( 'Grant priority after an authorized external purchase. Reuse the same idempotency key only for the same event and external reference.', 'extrachill-events' ),
					),
				),
			)
		);
	}

	/**
	 * Require an administrator or a trusted commerce fulfillment context.
	 *
	 * @param array $input Validated ability input.
	 * @return true|\WP_Error
	 */
	public function can_grant_priority_boost( array $input ) {
		if ( $this->priority_boost_is_trusted_commerce_request( $input ) ) {
			return true;
		}

		$actor_id = $this->get_priority_boost_actor_id();
		if ( $actor_id < 1 ) {
			return new \WP_Error( 'priority_boost_authentication_required', __( 'Authentication is required to grant event priority.', 'extrachill-events' ), array( 'status' => 401 ) );
		}

		return $this->priority_boost_actor_can_manage( $actor_id )
			? true
			: new \WP_Error( 'priority_boost_forbidden', __( 'Administrator access is required to grant event priority.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	/**
	 * Grant paid event priority with an opaque, atomic replay receipt.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function grant_priority_boost( array $input ) {
		$event_reference    = trim( (string) ( $input['event'] ?? '' ) );
		$external_reference = trim( (string) ( $input['external_reference'] ?? '' ) );
		$idempotency_key    = trim( (string) ( $input['idempotency_key'] ?? '' ) );

		if ( '' === $event_reference ) {
			return new \WP_Error( 'priority_boost_event_required', __( 'A canonical event reference is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( '' === $external_reference || strlen( $external_reference ) > 191 ) {
			return new \WP_Error( 'priority_boost_external_reference_invalid', __( 'A bounded external reference is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( '' === $idempotency_key || strlen( $idempotency_key ) > 191 ) {
			return new \WP_Error( 'priority_boost_idempotency_key_invalid', __( 'A bounded idempotency key is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$post = $this->resolve_priority_boost_event( $event_reference );
		if ( ! $post || 'data_machine_events' !== $post->post_type || 'trash' === $post->post_status ) {
			return new \WP_Error( 'priority_boost_event_not_found', __( 'The canonical event could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		$operation_hash = hash( 'sha256', $idempotency_key );
		$option_name    = 'extrachill_priority_boost_receipt_' . $operation_hash;
		$request_hash   = hash( 'sha256', $post->ID . "\0" . $external_reference );
		$receipt        = $this->get_priority_boost_receipt( $option_name );

		if ( is_array( $receipt ) ) {
			return $this->replay_priority_boost( $option_name, $receipt, $request_hash, $post );
		}

		if ( ! $this->priority_boost_event_is_eligible( (int) $post->ID ) ) {
			return new \WP_Error( 'priority_boost_event_ineligible', __( 'Priority boosts are available only for upcoming events.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		$existing_priority = $this->is_priority_boosted_event( (int) $post->ID );
		$receipt           = array(
			'version'                     => 1,
			'status'                      => 'pending',
			'request_hash'                => $request_hash,
			'actor_id'                    => $this->get_priority_boost_actor_id(),
			'existing_priority_preserved' => $existing_priority,
			'granted_at'                  => $this->priority_boost_timestamp(),
			'event'                       => array(
				'post_id'  => (int) $post->ID,
				'title'    => (string) $post->post_title,
				'slug'     => (string) $post->post_name,
				'priority' => true,
			),
		);

		if ( ! $this->add_priority_boost_receipt( $option_name, $receipt ) ) {
			$concurrent = $this->get_priority_boost_receipt( $option_name );
			if ( is_array( $concurrent ) ) {
				return $this->replay_priority_boost( $option_name, $concurrent, $request_hash, $post );
			}

			return new \WP_Error( 'priority_boost_receipt_unavailable', __( 'The priority boost receipt could not be reserved.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		return $this->complete_priority_boost( $option_name, $receipt, $post, false );
	}

	/**
	 * Resolve an exact retry or reject conflicting key reuse.
	 *
	 * @param string  $option_name Receipt option name.
	 * @param array   $receipt Stored operation receipt.
	 * @param string  $request_hash Hash of canonical inputs.
	 * @param \WP_Post $post Canonical event.
	 * @return array|\WP_Error
	 */
	private function replay_priority_boost( string $option_name, array $receipt, string $request_hash, $post ) {
		if ( empty( $receipt['request_hash'] ) || ! hash_equals( (string) $receipt['request_hash'], $request_hash ) ) {
			return new \WP_Error( 'priority_boost_idempotency_conflict', __( 'The idempotency key was already used for a different priority boost.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		if ( 'complete' !== ( $receipt['status'] ?? '' ) ) {
			return $this->complete_priority_boost( $option_name, $receipt, $post, true );
		}

		return $this->priority_boost_projection( $option_name, $receipt, true );
	}

	/**
	 * Persist the priority state and complete its reserved receipt.
	 *
	 * @param string  $option_name Receipt option name.
	 * @param array   $receipt Reserved operation receipt.
	 * @param \WP_Post $post Canonical event.
	 * @param bool    $replayed Whether this execution reused a reservation.
	 * @return array|\WP_Error
	 */
	private function complete_priority_boost( string $option_name, array $receipt, $post, bool $replayed ) {
		$this->set_priority_boosted_event( (int) $post->ID );
		if ( ! $this->is_priority_boosted_event( (int) $post->ID ) ) {
			return new \WP_Error( 'priority_boost_persistence_failed', __( 'The event priority state could not be saved.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		$this->delete_priority_boost_cache();
		$receipt['status'] = 'complete';
		if ( ! $this->update_priority_boost_receipt( $option_name, $receipt ) ) {
			$stored = $this->get_priority_boost_receipt( $option_name );
			if ( ! is_array( $stored ) || 'complete' !== ( $stored['status'] ?? '' ) ) {
				return new \WP_Error( 'priority_boost_receipt_persistence_failed', __( 'The priority boost receipt could not be completed.', 'extrachill-events' ), array( 'status' => 500 ) );
			}
			$receipt = $stored;
		}

		return $this->priority_boost_projection( $option_name, $receipt, $replayed );
	}

	/**
	 * Return the stable Events-owned projection without exposing commerce data.
	 *
	 * @param string $option_name Receipt option name.
	 * @param array  $receipt Completed operation receipt.
	 * @param bool   $replayed Whether this is an exact replay.
	 * @return array
	 */
	private function priority_boost_projection( string $option_name, array $receipt, bool $replayed ): array {
		return array(
			'success'                     => true,
			'replayed'                    => $replayed,
			'existing_priority_preserved' => ! empty( $receipt['existing_priority_preserved'] ),
			'event'                       => $receipt['event'],
			'receipt'                     => array(
				'operation_id' => substr( $option_name, -64, 32 ),
				'actor_id'     => (int) $receipt['actor_id'],
				'granted_at'   => (string) $receipt['granted_at'],
			),
		);
	}

	/**
	 * Resolve a canonical event reference.
	 *
	 * @param string $event_reference Event ID or slug.
	 * @return \WP_Post|null
	 */
	protected function resolve_priority_boost_event( string $event_reference ) {
		return is_numeric( $event_reference )
			? get_post( (int) $event_reference )
			: get_page_by_path( $event_reference, OBJECT, 'data_machine_events' );
	}

	/** Get the authenticated actor ID. */
	protected function get_priority_boost_actor_id(): int {
		return get_current_user_id();
	}

	/**
	 * Check paid-priority authority.
	 *
	 * @param int $actor_id Actor user ID.
	 */
	protected function priority_boost_actor_can_manage( int $actor_id ): bool {
		return user_can( $actor_id, 'manage_options' );
	}

	/**
	 * Accept only exact target-runtime service authority verified by Network.
	 *
	 * @param array $input Validated ability input.
	 * @return bool
	 */
	protected function priority_boost_is_trusted_commerce_request( array $input ): bool {
		unset( $input );

		return function_exists( 'extrachill_events_priority_boost_has_verified_service_authority' )
			&& extrachill_events_priority_boost_has_verified_service_authority();
	}

	/**
	 * Check that a new grant targets an event that has not passed.
	 *
	 * @param int $post_id Event post ID.
	 * @return bool
	 */
	protected function priority_boost_event_is_eligible( int $post_id ): bool {
		$event_date = trim( (string) get_post_meta( $post_id, '_event_date', true ) );
		$date       = \DateTimeImmutable::createFromFormat( '!Y-m-d', $event_date );

		return false !== $date
			&& $date->format( 'Y-m-d' ) === $event_date
			&& $event_date >= current_time( 'Y-m-d' );
	}

	/**
	 * Read an operation receipt.
	 *
	 * @param string $option_name Receipt option name.
	 * @return array|false
	 */
	protected function get_priority_boost_receipt( string $option_name ) {
		return get_option( $option_name, false );
	}

	/**
	 * Atomically reserve an operation receipt.
	 *
	 * @param string $option_name Receipt option name.
	 * @param array  $receipt Receipt payload.
	 */
	protected function add_priority_boost_receipt( string $option_name, array $receipt ): bool {
		return add_option( $option_name, $receipt, '', false );
	}

	/**
	 * Complete an operation receipt.
	 *
	 * @param string $option_name Receipt option name.
	 * @param array  $receipt Receipt payload.
	 */
	protected function update_priority_boost_receipt( string $option_name, array $receipt ): bool {
		return update_option( $option_name, $receipt, false );
	}

	/**
	 * Read the established priority state.
	 *
	 * @param int $post_id Event post ID.
	 */
	protected function is_priority_boosted_event( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, '_extrachill_priority_event', true );
	}

	/**
	 * Persist the established priority state.
	 *
	 * @param int $post_id Event post ID.
	 */
	protected function set_priority_boosted_event( int $post_id ): void {
		update_post_meta( $post_id, '_extrachill_priority_event', true );
	}

	/** Invalidate the Events-owned priority cache. */
	protected function delete_priority_boost_cache(): void {
		wp_cache_delete( 'extrachill_priority_event_ids', 'extrachill-events' );
	}

	/** Get the immutable receipt timestamp. */
	protected function priority_boost_timestamp(): string {
		return gmdate( 'c' );
	}

	public function listPriorityEvents( array $input ): array {
		unset( $input );
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

	/**
	 * Set manual event priority.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function setPriorityEvent( array $input ) {
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
