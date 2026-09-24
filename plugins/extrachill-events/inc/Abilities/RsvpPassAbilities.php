<?php
/**
 * RSVP Pass Abilities
 *
 * Attendee-facing pass lookup and host-facing door list / redemption.
 * Authorization for the door list and redemption both reuse
 * extrachill_events_user_can_manage_event() (see
 * inc/core/event-management-authority.php) — the single place that knows
 * what "manage this event" means, shared with EventPerkAbilities and with
 * the extrachill-users-owned `extrachill_users_can_manage_event_attendance`
 * filter.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\RsvpPassesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RsvpPassAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/get-my-event-pass',
			array(
				'label'               => __( 'Get My Event RSVP Pass', 'extrachill-events' ),
				'description'         => __( 'Get the current user\'s RSVP perk pass for an event, if any.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'event_id' => array(
							'type'        => 'integer',
							'description' => __( 'Event post ID.', 'extrachill-events' ),
						),
					),
					'required'   => array( 'event_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'issued'    => array( 'type' => 'boolean' ),
						'code'      => array( 'type' => 'string' ),
						'perk_text' => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string' ),
					),
					'required'   => array( 'issued' ),
				),
				'execute_callback'    => array( $this, 'get_my_event_pass' ),
				'permission_callback' => 'is_user_logged_in',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'     => true,
						'idempotent'   => true,
						'destructive'  => false,
						'instructions' => __( 'Read-only: never issues a pass. Passes are issued only by marking Going on a perk-enabled event.', 'extrachill-events' ),
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/list-event-door-attendees',
			array(
				'label'               => __( 'List Event Door Attendees', 'extrachill-events' ),
				'description'         => __( 'List every attendee of any event the caller manages, including private ones. Host/organizer only. Includes RSVP pass redemption state when the event has a perk enabled.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'event_id' => array(
							'type'        => 'integer',
							'description' => __( 'Event post ID.', 'extrachill-events' ),
						),
					),
					'required'   => array( 'event_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'perk_enabled' => array( 'type' => 'boolean' ),
						'perk_text'    => array( 'type' => 'string' ),
						'attendees'    => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'user_id'      => array( 'type' => 'integer' ),
									'display_name' => array( 'type' => 'string' ),
									'marked_at'    => array( 'type' => 'string' ),
									'pass_status'  => array( 'type' => 'string' ),
									'redeemed_at'  => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'list_event_door_attendees' ),
				'permission_callback' => array( $this, 'can_manage_door_list' ),
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

		wp_register_ability(
			'extrachill/redeem-event-pass',
			array(
				'label'               => __( 'Redeem Event RSVP Pass', 'extrachill-events' ),
				'description'         => __( 'Atomically redeem an attendee\'s RSVP perk pass at the door. Safe against a double-tap or two hosts redeeming at once.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'event_id' => array(
							'type'        => 'integer',
							'description' => __( 'Event post ID.', 'extrachill-events' ),
						),
						'user_id'  => array(
							'type'        => 'integer',
							'description' => __( 'Attendee user ID.', 'extrachill-events' ),
						),
					),
					'required'   => array( 'event_id', 'user_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'already_redeemed'    => array( 'type' => 'boolean' ),
						'redeemed_at'         => array( 'type' => 'string' ),
						'redeemed_by_user_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'already_redeemed' ),
				),
				'execute_callback'    => array( $this, 'redeem_event_pass' ),
				'permission_callback' => array( $this, 'can_manage_door_list' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'     => false,
						'idempotent'   => true,
						'destructive'  => false,
						'instructions' => __( 'Idempotent: redeeming an already-redeemed pass returns its existing redemption record rather than erroring.', 'extrachill-events' ),
					),
				),
			)
		);
	}

	/**
	 * Authorize door-list read/redeem: the event's host, or a network admin.
	 *
	 * @param array $input Ability input.
	 * @return bool|\WP_Error
	 */
	public function can_manage_door_list( array $input ) {
		$event_id = (int) ( $input['event_id'] ?? 0 );
		$post     = get_post( $event_id );
		if ( ! $post || 'data_machine_events' !== $post->post_type ) {
			return new \WP_Error( 'event_not_found', __( 'The event could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		// Non-enumerating denial: an unauthorized caller learns nothing
		// beyond "forbidden," never whether the event has an attendee list,
		// a perk, or any attendees at all.
		return extrachill_events_user_can_manage_event( get_current_user_id(), $event_id )
			? true
			: new \WP_Error( 'event_door_list_forbidden', __( 'You are not authorized to manage this event\'s door list.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	/**
	 * Get the current user's pass for an event. Read-only; never issues.
	 *
	 * @param array $input Validated ability input.
	 * @return array
	 */
	public function get_my_event_pass( array $input ): array {
		$event_id = (int) $input['event_id'];
		$user_id  = get_current_user_id();

		$pass = RsvpPassesTable::find_for_user_event( $event_id, $user_id );
		if ( ! $pass || RsvpPassesTable::STATUS_ACTIVE !== $pass['status'] ) {
			return array( 'issued' => false );
		}

		return array(
			'issued'    => true,
			'code'      => (string) $pass['code'],
			'perk_text' => function_exists( 'extrachill_events_get_perk_text' ) ? extrachill_events_get_perk_text( $event_id ) : '',
			'status'    => (string) $pass['status'],
		);
	}

	/**
	 * List every attendee of an event (including private) with pass state.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function list_event_door_attendees( array $input ) {
		$event_id = (int) $input['event_id'];

		if ( ! function_exists( 'ec_users_get_event_attendees_full' ) ) {
			return new \WP_Error( 'door_list_unavailable', __( 'The attendee list is temporarily unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}

		$attendees = ec_users_get_event_attendees_full( $event_id );
		$passes    = RsvpPassesTable::list_for_event( $event_id );

		$rows = array();
		foreach ( $attendees as $attendee ) {
			$user_id = (int) $attendee['user_id'];
			$pass    = $passes[ $user_id ] ?? null;
			$rows[]  = array(
				'user_id'      => $user_id,
				'display_name' => (string) $attendee['display_name'],
				// ec_users_get_event_attendees_full() always returns marked_at
				// (non-nullable) — no ?? fallback needed here.
				'marked_at'    => (string) $attendee['marked_at'],
				'pass_status'  => $pass ? (string) $pass['status'] : '',
				'redeemed_at'  => $pass && ! empty( $pass['redeemed_at'] ) ? (string) $pass['redeemed_at'] : '',
			);
		}

		return array(
			'perk_enabled' => function_exists( 'extrachill_events_perk_enabled' ) ? extrachill_events_perk_enabled( $event_id ) : false,
			'perk_text'    => function_exists( 'extrachill_events_get_perk_text' ) ? extrachill_events_get_perk_text( $event_id ) : '',
			'attendees'    => $rows,
		);
	}

	/**
	 * Redeem an attendee's pass.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function redeem_event_pass( array $input ) {
		$event_id = (int) $input['event_id'];
		$user_id  = (int) $input['user_id'];

		return RsvpPassesTable::redeem( $event_id, $user_id, get_current_user_id() );
	}
}
