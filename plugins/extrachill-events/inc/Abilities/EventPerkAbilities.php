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
				'description'         => __( 'Enable or disable an RSVP perk on an event, set its attendee-facing description, and choose how guests get on the list (mode). Empty or omitted fields keep their current value.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						...TicketAbilities::fields(),
						'event'              => array(
							'type'        => 'string',
							'description' => __( 'Event post slug or numeric ID.', 'extrachill-events' ),
						),
						'enabled'            => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the perk is offered. Omit to keep the current setting.', 'extrachill-events' ),
						),
						'text'               => array(
							'type'        => 'string',
							'maxLength'   => EXTRACHILL_EVENTS_PERK_TEXT_MAXLEN,
							'description' => __( 'Attendee-facing perk description, e.g. "First beer on Extra Chill." Omit or leave empty to keep the current text; use clear_text to remove it.', 'extrachill-events' ),
						),
						'clear_text'         => array(
							'type'        => 'boolean',
							'description' => __( 'Remove the perk description.', 'extrachill-events' ),
							'default'     => false,
						),
						'mode'               => array(
							'type'        => 'string',
							'enum'        => array( EXTRACHILL_EVENTS_PERK_MODE_HOST_LIST, EXTRACHILL_EVENTS_PERK_MODE_PASSWORD, EXTRACHILL_EVENTS_PERK_MODE_GOING ),
							'description' => __( 'Who gets on the list. host_list: only names the host adds (no password, no public claim, Going grants nothing). password: guests claim a pass with the password. going: marking Going issues the pass. Omit to keep the current mode.', 'extrachill-events' ),
						),
						'password'           => array(
							'type'        => 'string',
							'maxLength'   => EXTRACHILL_EVENTS_PERK_PASSWORD_MAX,
							'description' => __( 'Guest list password, used in password mode. Omit or leave empty to keep the current password; use clear_password to remove it.', 'extrachill-events' ),
						),
						'clear_password'     => array(
							'type'        => 'boolean',
							'description' => __( 'Remove the guest list password.', 'extrachill-events' ),
							'default'     => false,
						),
						'capacity'           => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'maximum'     => EXTRACHILL_EVENTS_PERK_CAPACITY_MAX,
							'description' => __( 'Guest list spots: the most passes the event will issue. 0 = unlimited. Omit to keep the current value.', 'extrachill-events' ),
						),
						'image_id'           => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Pass artwork: an image attachment ID shown on the pass next to the QR code. 0 removes it. Omit to keep the current image.', 'extrachill-events' ),
						),
						'allotments_enabled' => array(
							'type'        => 'boolean',
							'description' => __( 'Cap each invited band or management at its own allotment of spots. Off by default: invited parties are limited only by capacity. Omit to keep the current setting.', 'extrachill-events' ),
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
								...TicketAbilities::fields(),
								'post_id'            => array( 'type' => 'integer' ),
								'title'              => array( 'type' => 'string' ),
								'enabled'            => array( 'type' => 'boolean' ),
								'text'               => array( 'type' => 'string' ),
								'password_required'  => array( 'type' => 'boolean' ),
								'mode'               => array(
									'type' => 'string',
									'enum' => array( 'host_list', 'password', 'going' ),
								),
								'capacity'           => array( 'type' => 'integer' ),
								'allotments_enabled' => array( 'type' => 'boolean' ),
								'image_id'           => array( 'type' => 'integer' ),
								'image_url'          => array( 'type' => 'string' ),
								'pass_image_url'     => array( 'type' => 'string' ),
								'pass_image_source'  => array(
									'type' => 'string',
									'enum' => array( 'custom', 'poster', 'card' ),
								),
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
						'instructions' => __( 'In going mode, marking Going on an enabled-perk event issues each attendee a pass shown on screen, emailed to them, and visible to the host at the door. In password mode guests claim with the password on the /guest-list page. In host_list mode only names the host adds get a pass. Hosts can add names in every mode.', 'extrachill-events' ),
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
						...TicketAbilities::fields(),
						'post_id'            => array( 'type' => 'integer' ),
						'enabled'            => array( 'type' => 'boolean' ),
						'text'               => array( 'type' => 'string' ),
						'password_required'  => array( 'type' => 'boolean' ),
						'mode'               => array(
							'type' => 'string',
							'enum' => array( 'host_list', 'password', 'going' ),
						),
						'capacity'           => array( 'type' => 'integer' ),
						'claimed'            => array( 'type' => 'integer' ),
						'checked_in'         => array( 'type' => 'integer' ),
						'spots_left'         => array( 'type' => array( 'integer', 'null' ) ),
						'full'               => array( 'type' => 'boolean' ),
						'allotments_enabled' => array( 'type' => 'boolean' ),
						'image_id'           => array( 'type' => 'integer' ),
						'image_url'          => array( 'type' => 'string' ),
						'pass_image_url'     => array( 'type' => 'string' ),
						'pass_image_source'  => array(
							'type' => 'string',
							'enum' => array( 'custom', 'poster', 'card' ),
						),
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

		$ticket_settings = TicketAbilities::validate_settings( $post->ID, $input );
		if ( is_wp_error( $ticket_settings ) ) {
			return $ticket_settings;
		}

		// Validate the artwork before writing anything, so a bad ID leaves
		// the perk untouched instead of half-applied.
		$image_id = isset( $input['image_id'] ) ? max( 0, (int) $input['image_id'] ) : null;
		if ( $image_id && ! wp_attachment_is_image( $image_id ) ) {
			return new \WP_Error( 'invalid_perk_image', __( 'The pass artwork must be an image in the media library.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$mode = isset( $input['mode'] ) ? (string) $input['mode'] : null;
		if ( null !== $mode && '' !== $mode && ! in_array( $mode, extrachill_events_perk_modes(), true ) ) {
			return new \WP_Error( 'invalid_perk_mode', __( 'The guest list mode must be host_list, password, or going.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$password       = isset( $input['password'] ) ? (string) $input['password'] : null;
		$clear_password = ! empty( $input['clear_password'] );
		$will_have_pass = ! $clear_password && ( ( null !== $password && '' !== $password ) || extrachill_events_perk_password_required( $post->ID ) );
		if ( EXTRACHILL_EVENTS_PERK_MODE_PASSWORD === $mode && ! $will_have_pass ) {
			return new \WP_Error( 'perk_password_required', __( 'Set a guest list password to use password mode.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		update_post_meta( $post->ID, '_extrachill_ticket_offer', $ticket_settings );

		// Empty or omitted fields mean unchanged (extrachill#1246): a caller
		// that only sends a password or a capacity never wipes the perk text
		// or turns the perk off.
		$enabled = isset( $input['enabled'] ) ? (bool) $input['enabled'] : null;
		$text    = isset( $input['text'] ) ? substr( sanitize_textarea_field( (string) $input['text'] ), 0, EXTRACHILL_EVENTS_PERK_TEXT_MAXLEN ) : '';

		// Guest list settings go first: enabling the perk backfills passes
		// for everyone already Going, and that must see the mode, password,
		// and capacity, not an open perk.
		$access = extrachill_events_apply_perk_access( $post->ID, $mode, $password, $clear_password );
		if ( is_wp_error( $access ) ) {
			return $access;
		}

		if ( isset( $input['capacity'] ) ) {
			extrachill_events_set_perk_capacity( $post->ID, (int) $input['capacity'] );
		}

		if ( null !== $image_id ) {
			extrachill_events_set_perk_image_id( $post->ID, $image_id );
		}

		if ( isset( $input['allotments_enabled'] ) ) {
			extrachill_events_set_guest_list_allotments( $post->ID, (bool) $input['allotments_enabled'] );
		}

		if ( true === $enabled ) {
			update_post_meta( $post->ID, EXTRACHILL_EVENTS_PERK_ENABLED_META, true );
		} elseif ( false === $enabled ) {
			delete_post_meta( $post->ID, EXTRACHILL_EVENTS_PERK_ENABLED_META );
		}

		if ( '' !== $text ) {
			update_post_meta( $post->ID, EXTRACHILL_EVENTS_PERK_TEXT_META, $text );
		} elseif ( ! empty( $input['clear_text'] ) ) {
			delete_post_meta( $post->ID, EXTRACHILL_EVENTS_PERK_TEXT_META );
		}

		return array(
			'success' => true,
			'event'   => array(
				...$ticket_settings,
				'post_id'            => $post->ID,
				'title'              => $post->post_title,
				'enabled'            => extrachill_events_perk_enabled( $post->ID ),
				'text'               => extrachill_events_get_perk_text( $post->ID ),
				'password_required'  => extrachill_events_perk_password_required( $post->ID ),
				'mode'               => extrachill_events_get_perk_mode( $post->ID ),
				'capacity'           => extrachill_events_get_perk_capacity( $post->ID ),
				'allotments_enabled' => extrachill_events_guest_list_allotments_enabled( $post->ID ),
				'image_id'           => extrachill_events_get_perk_image_id( $post->ID ),
				'image_url'          => extrachill_events_get_perk_image_url( $post->ID ),
				'pass_image_url'     => extrachill_events_get_pass_image_url( $post->ID ),
				'pass_image_source'  => extrachill_events_get_pass_image_source( $post->ID ),
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
			...TicketAbilities::settings( $post->ID ),
			'post_id'            => $post->ID,
			'enabled'            => extrachill_events_perk_enabled( $post->ID ),
			'text'               => extrachill_events_get_perk_text( $post->ID ),
			'password_required'  => extrachill_events_perk_password_required( $post->ID ),
			'mode'               => extrachill_events_get_perk_mode( $post->ID ),
			'capacity'           => $counts['capacity'],
			'claimed'            => $counts['claimed'],
			'checked_in'         => $counts['checked_in'],
			'spots_left'         => $counts['spots_left'],
			'full'               => $counts['full'],
			'allotments_enabled' => extrachill_events_guest_list_allotments_enabled( $post->ID ),
			'image_id'           => extrachill_events_get_perk_image_id( $post->ID ),
			'image_url'          => extrachill_events_get_perk_image_url( $post->ID ),
			'pass_image_url'     => extrachill_events_get_pass_image_url( $post->ID ),
			'pass_image_source'  => extrachill_events_get_pass_image_source( $post->ID ),
		);
	}
}
