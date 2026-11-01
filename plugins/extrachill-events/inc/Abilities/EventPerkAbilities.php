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
						'event'          => array(
							'type'        => 'string',
							'description' => __( 'Event post slug or numeric ID.', 'extrachill-events' ),
						),
						'enabled'        => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the perk is offered.', 'extrachill-events' ),
							'default'     => true,
						),
						'text'           => array(
							'type'        => 'string',
							'maxLength'   => EXTRACHILL_EVENTS_PERK_TEXT_MAXLEN,
							'description' => __( 'Attendee-facing perk description, e.g. "First beer on Extra Chill."', 'extrachill-events' ),
							'default'     => '',
						),
						'password'       => array(
							'type'        => 'string',
							'maxLength'   => EXTRACHILL_EVENTS_PERK_PASSWORD_MAX,
							'description' => __( 'Guest list password. Only people who know it can claim a pass. Omit to keep the current password.', 'extrachill-events' ),
						),
						'clear_password' => array(
							'type'        => 'boolean',
							'description' => __( 'Remove the guest list password.', 'extrachill-events' ),
							'default'     => false,
						),
						'capacity'       => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'maximum'     => EXTRACHILL_EVENTS_PERK_CAPACITY_MAX,
							'description' => __( 'Guest list spots: the most passes the event will issue. 0 = unlimited. Omit to keep the current value.', 'extrachill-events' ),
						),
						'image_id'       => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Pass artwork: an image attachment ID shown on the pass next to the QR code. 0 removes it. Omit to keep the current image.', 'extrachill-events' ),
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
								'post_id'           => array( 'type' => 'integer' ),
								'title'             => array( 'type' => 'string' ),
								'enabled'           => array( 'type' => 'boolean' ),
								'text'              => array( 'type' => 'string' ),
								'password_required' => array( 'type' => 'boolean' ),
								'capacity'          => array( 'type' => 'integer' ),
								'image_id'          => array( 'type' => 'integer' ),
								'image_url'         => array( 'type' => 'string' ),
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
						'post_id'           => array( 'type' => 'integer' ),
						'enabled'           => array( 'type' => 'boolean' ),
						'text'              => array( 'type' => 'string' ),
						'password_required' => array( 'type' => 'boolean' ),
						'capacity'          => array( 'type' => 'integer' ),
						'claimed'           => array( 'type' => 'integer' ),
						'checked_in'        => array( 'type' => 'integer' ),
						'spots_left'        => array( 'type' => array( 'integer', 'null' ) ),
						'full'              => array( 'type' => 'boolean' ),
						'image_id'          => array( 'type' => 'integer' ),
						'image_url'         => array( 'type' => 'string' ),
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
	 * Authorize perk configuration: the same "can manage this event" check
	 * shared with the door list and redemption
	 * (extrachill_events_user_can_manage_event(), inc/core/event-management-authority.php).
	 * Previously this checked only edit_post directly — a narrower,
	 * duplicated definition of "host" that a promoter/venue team member
	 * without edit_post on this specific post would have failed even
	 * though they can already manage this event everywhere else.
	 *
	 * @param array $input Ability input.
	 * @return bool|\WP_Error
	 */
	public function can_manage_event_perk( array $input ) {
		$post = $this->resolve_event( (string) ( $input['event'] ?? '' ) );
		if ( ! $post ) {
			return new \WP_Error( 'event_not_found', __( 'The event could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		if ( ! function_exists( 'extrachill_events_user_can_manage_event' ) ) {
			return new \WP_Error( 'event_perk_authority_unavailable', __( 'Event management authority is temporarily unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}

		return extrachill_events_user_can_manage_event( get_current_user_id(), $post->ID )
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

		// Validate the artwork before writing anything, so a bad ID leaves
		// the perk untouched instead of half-applied.
		$image_id = isset( $input['image_id'] ) ? max( 0, (int) $input['image_id'] ) : null;
		if ( $image_id && ! wp_attachment_is_image( $image_id ) ) {
			return new \WP_Error( 'invalid_perk_image', __( 'The pass artwork must be an image in the media library.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$enabled = (bool) ( $input['enabled'] ?? true );
		$text    = substr( sanitize_textarea_field( (string) ( $input['text'] ?? '' ) ), 0, EXTRACHILL_EVENTS_PERK_TEXT_MAXLEN );

		// Guest list settings go first: enabling the perk backfills passes
		// for everyone already Going, and that must see the password and
		// capacity, not an open perk.
		if ( ! empty( $input['clear_password'] ) ) {
			extrachill_events_set_perk_password( $post->ID, '' );
		} elseif ( isset( $input['password'] ) && '' !== (string) $input['password'] ) {
			extrachill_events_set_perk_password( $post->ID, (string) $input['password'] );
		}

		if ( isset( $input['capacity'] ) ) {
			extrachill_events_set_perk_capacity( $post->ID, (int) $input['capacity'] );
		}

		if ( null !== $image_id ) {
			extrachill_events_set_perk_image_id( $post->ID, $image_id );
		}

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
				'post_id'           => $post->ID,
				'title'             => $post->post_title,
				'enabled'           => $enabled,
				'text'              => $text,
				'password_required' => extrachill_events_perk_password_required( $post->ID ),
				'capacity'          => extrachill_events_get_perk_capacity( $post->ID ),
				'image_id'          => extrachill_events_get_perk_image_id( $post->ID ),
				'image_url'         => extrachill_events_get_perk_image_url( $post->ID ),
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

		$counts = extrachill_events_get_guest_list_counts( $post->ID );

		return array(
			'post_id'           => $post->ID,
			'enabled'           => extrachill_events_perk_enabled( $post->ID ),
			'text'              => extrachill_events_get_perk_text( $post->ID ),
			'password_required' => extrachill_events_perk_password_required( $post->ID ),
			'capacity'          => $counts['capacity'],
			'claimed'           => $counts['claimed'],
			'checked_in'        => $counts['checked_in'],
			'spots_left'        => $counts['spots_left'],
			'full'              => $counts['full'],
			'image_id'          => extrachill_events_get_perk_image_id( $post->ID ),
			'image_url'         => extrachill_events_get_perk_image_url( $post->ID ),
		);
	}
}
