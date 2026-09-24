<?php
/**
 * Event RSVP Perk Abilities
 *
 * WordPress Abilities API surface for configuring an event's RSVP perk,
 * mirroring PriorityEventAbilities' shape. Admin UI equivalent lives in
 * inc/admin/event-perks.php.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventPerkAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/set-event-perk',
			array(
				'label'               => __( 'Set Event RSVP Perk', 'extrachill-events' ),
				'description'         => __( 'Enable or disable an RSVP perk on an event and set its attendee-facing description.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'event'   => array(
							'type'        => 'string',
							'description' => __( 'Event post slug or numeric ID.', 'extrachill-events' ),
						),
						'enabled' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the perk is offered.', 'extrachill-events' ),
							'default'     => true,
						),
						'text'    => array(
							'type'        => 'string',
							'maxLength'   => EXTRACHILL_EVENTS_PERK_TEXT_MAXLEN,
							'description' => __( 'Attendee-facing perk description, e.g. "First beer on Extra Chill."', 'extrachill-events' ),
							'default'     => '',
						),
					),
					'required'   => array( 'event' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'event'   => array(
							'type'       => 'object',
							'properties' => array(
								'post_id' => array( 'type' => 'integer' ),
								'title'   => array( 'type' => 'string' ),
								'enabled' => array( 'type' => 'boolean' ),
								'text'    => array( 'type' => 'string' ),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'set_event_perk' ),
				'permission_callback' => array( $this, 'can_manage_event_perk' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'     => false,
						'idempotent'   => true,
						'destructive'  => false,
						'instructions' => __( 'Marking Going on an enabled-perk event issues each attendee a pass shown on screen, emailed to them, and visible to the host at the door.', 'extrachill-events' ),
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/get-event-perk',
			array(
				'label'               => __( 'Get Event RSVP Perk', 'extrachill-events' ),
				'description'         => __( 'Get an event\'s RSVP perk configuration.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'event' => array(
							'type'        => 'string',
							'description' => __( 'Event post slug or numeric ID.', 'extrachill-events' ),
						),
					),
					'required'   => array( 'event' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'enabled' => array( 'type' => 'boolean' ),
						'text'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'get_event_perk' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);
	}

	/**
	 * Resolve an event post from a slug or numeric ID.
	 *
	 * @param string $event_reference Event slug or numeric ID.
	 * @return \WP_Post|null
	 */
	private function resolve_event( string $event_reference ): ?\WP_Post {
		$post = is_numeric( $event_reference )
			? get_post( (int) $event_reference )
			: get_page_by_path( $event_reference, OBJECT, 'data_machine_events' );

		return ( $post instanceof \WP_Post && 'data_machine_events' === $post->post_type ) ? $post : null;
	}

	/**
	 * Authorize perk configuration: anyone who can edit the event post.
	 *
	 * @param array $input Ability input.
	 * @return bool|\WP_Error
	 */
	public function can_manage_event_perk( array $input ) {
		$post = $this->resolve_event( (string) ( $input['event'] ?? '' ) );
		if ( ! $post ) {
			return new \WP_Error( 'event_not_found', __( 'The event could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		return current_user_can( 'edit_post', $post->ID )
			? true
			: new \WP_Error( 'event_perk_forbidden', __( 'You are not authorized to manage this event.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	/**
	 * Set an event's RSVP perk.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function set_event_perk( array $input ) {
		$post = $this->resolve_event( (string) ( $input['event'] ?? '' ) );
		if ( ! $post ) {
			return new \WP_Error( 'event_not_found', __( 'The event could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		$enabled = (bool) ( $input['enabled'] ?? true );
		$text    = substr( sanitize_textarea_field( (string) ( $input['text'] ?? '' ) ), 0, EXTRACHILL_EVENTS_PERK_TEXT_MAXLEN );

		if ( $enabled ) {
			update_post_meta( $post->ID, EXTRACHILL_EVENTS_PERK_ENABLED_META, true );
		} else {
			delete_post_meta( $post->ID, EXTRACHILL_EVENTS_PERK_ENABLED_META );
		}

		if ( '' !== $text ) {
			update_post_meta( $post->ID, EXTRACHILL_EVENTS_PERK_TEXT_META, $text );
		} else {
			delete_post_meta( $post->ID, EXTRACHILL_EVENTS_PERK_TEXT_META );
		}

		return array(
			'success' => true,
			'event'   => array(
				'post_id' => $post->ID,
				'title'   => $post->post_title,
				'enabled' => $enabled,
				'text'    => $text,
			),
		);
	}

	/**
	 * Get an event's RSVP perk configuration.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function get_event_perk( array $input ) {
		$post = $this->resolve_event( (string) ( $input['event'] ?? '' ) );
		if ( ! $post ) {
			return new \WP_Error( 'event_not_found', __( 'The event could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		return array(
			'post_id' => $post->ID,
			'enabled' => extrachill_events_perk_enabled( $post->ID ),
			'text'    => extrachill_events_get_perk_text( $post->ID ),
		);
	}
}
