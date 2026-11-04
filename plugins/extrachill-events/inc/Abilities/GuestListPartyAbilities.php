<?php
/**
 * Guest List Party Abilities (extrachill#1128)
 *
 * A venue or promoter host invites a band or artist management to fill
 * guest list spots for one event; the party adds names through a private
 * link. Behavior lives in inc/core/guest-list-parties.php. Host abilities
 * reuse RsvpPassAbilities' door-list authority
 * (extrachill_events_user_can_manage_event()); party abilities are gated by
 * the invite token alone and are hidden from the generic REST runner: the
 * only public write surface is the token-gated invite page.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GuestListPartyAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/**
	 * Shared input property: event post ID.
	 *
	 * @return array
	 */
	private static function event_id_property(): array {
		return array(
			'type'        => 'integer',
			'description' => __( 'Event post ID.', 'extrachill-events' ),
		);
	}

	/**
	 * Shared input property: party ID.
	 *
	 * @return array
	 */
	private static function party_id_property(): array {
		return array(
			'type'        => 'integer',
			'description' => __( 'Invited party ID, from extrachill/list-guest-list-parties.', 'extrachill-events' ),
		);
	}

	/**
	 * Shared input property: raw invite token.
	 *
	 * @return array
	 */
	private static function token_property(): array {
		return array(
			'type'        => 'string',
			'description' => __( 'Invite token from the party\'s private link.', 'extrachill-events' ),
		);
	}

	/**
	 * Output schema of one party summary.
	 *
	 * @return array
	 */
	private static function party_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'party_id'   => array( 'type' => 'integer' ),
				'name'       => array( 'type' => 'string' ),
				'email'      => array( 'type' => 'string' ),
				'status'     => array( 'type' => 'string' ),
				'allotment'  => array( 'type' => array( 'integer', 'null' ) ),
				'used'       => array( 'type' => 'integer' ),
				'spots_left' => array( 'type' => array( 'integer', 'null' ) ),
				'created_at' => array( 'type' => 'string' ),
			),
		);
	}

	public function register(): void {
		if ( ! class_exists( RsvpPassAbilities::class, false ) ) {
			require_once __DIR__ . '/RsvpPassAbilities.php';
		}
		// Same host authority as the door list; the constructor only hooks once.
		$host = new RsvpPassAbilities();

		wp_register_ability(
			'extrachill/create-guest-list-party',
			array(
				'label'               => __( 'Invite Band To Guest List', 'extrachill-events' ),
				'description'         => __( 'Host-only: invite a band or artist management to fill guest list spots for an event. Emails them a private link where they add guest names with no password or account.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'event_id'  => self::event_id_property(),
						'name'      => array(
							'type'        => 'string',
							'description' => __( 'Band or management name, shown on the door list next to each guest they add.', 'extrachill-events' ),
						),
						'email'     => array(
							'type'        => 'string',
							'description' => __( 'Where to send the private invite link.', 'extrachill-events' ),
						),
						'allotment' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Spots for this party. Required when the event has allotments on; stored but unused while they are off.', 'extrachill-events' ),
						),
					),
					'required'   => array( 'event_id', 'name', 'email' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'party_id'   => array( 'type' => 'integer' ),
						'name'       => array( 'type' => 'string' ),
						'email'      => array( 'type' => 'string' ),
						'allotment'  => array( 'type' => array( 'integer', 'null' ) ),
						'invite_url' => array( 'type' => 'string' ),
						'emailed'    => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'create_party' ),
				'permission_callback' => array( $host, 'can_manage_door_list' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'     => false,
						'idempotent'   => false,
						'destructive'  => false,
						'instructions' => __( 'The invite_url carries the only copy of the token; it is never retrievable again. Needs the guest list turned on (perk enabled with a password).', 'extrachill-events' ),
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/list-guest-list-parties',
			array(
				'label'               => __( 'List Guest List Parties', 'extrachill-events' ),
				'description'         => __( 'Host-only: list the bands and management invited to an event\'s guest list, with spots used and allotted.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'event_id' => self::event_id_property(),
					),
					'required'   => array( 'event_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'allotments_enabled' => array( 'type' => 'boolean' ),
						'parties'            => array(
							'type'  => 'array',
							'items' => self::party_schema(),
						),
					),
				),
				'execute_callback'    => array( $this, 'list_parties' ),
				'permission_callback' => array( $host, 'can_manage_door_list' ),
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
			'extrachill/revoke-guest-list-party',
			array(
				'label'               => __( 'Revoke Guest List Party Invite', 'extrachill-events' ),
				'description'         => __( 'Host-only: revoke a band or management invite. Their link stops working; guests they already added stay on the list and can be removed one by one.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'event_id' => self::event_id_property(),
						'party_id' => self::party_id_property(),
					),
					'required'   => array( 'event_id', 'party_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'revoked'     => array( 'type' => 'boolean' ),
						'kept_guests' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'revoke_party' ),
				'permission_callback' => array( $host, 'can_manage_door_list' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => false,
						'destructive' => true,
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/set-guest-list-party-allotment',
			array(
				'label'               => __( 'Set Guest List Party Allotment', 'extrachill-events' ),
				'description'         => __( 'Host-only: set how many guest list spots an invited band or management gets. Applies while the event has allotments on.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'event_id'  => self::event_id_property(),
						'party_id'  => self::party_id_property(),
						'allotment' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Spots for this party.', 'extrachill-events' ),
						),
					),
					'required'   => array( 'event_id', 'party_id', 'allotment' ),
				),
				'output_schema'       => self::party_schema(),
				'execute_callback'    => array( $this, 'set_allotment' ),
				'permission_callback' => array( $host, 'can_manage_door_list' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'     => false,
						'idempotent'   => true,
						'destructive'  => false,
						'instructions' => __( 'Lowering an allotment below the names already added stops new adds; it never removes anyone.', 'extrachill-events' ),
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/party-add-guest-list-name',
			array(
				'label'               => __( 'Invited Party Adds Guest List Name', 'extrachill-events' ),
				'description'         => __( 'An invited band or management adds a guest by name through its invite token. No account. Counts against the event\'s spots and, when allotments are on, the party\'s allotment.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'token' => self::token_property(),
						'name'  => array(
							'type'        => 'string',
							'description' => __( 'Guest name, shown to the door with the party name.', 'extrachill-events' ),
						),
						'email' => array(
							'type'        => 'string',
							'description' => __( 'Optional address to email the pass to.', 'extrachill-events' ),
						),
					),
					'required'   => array( 'token', 'name' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'code'             => array( 'type' => 'string' ),
						'name'             => array( 'type' => 'string' ),
						'pass_url'         => array( 'type' => 'string' ),
						'qr_url'           => array( 'type' => 'string' ),
						'emailed'          => array( 'type' => 'boolean' ),
						'spots_left'       => array( 'type' => array( 'integer', 'null' ) ),
						'party_spots_left' => array( 'type' => array( 'integer', 'null' ) ),
					),
				),
				'execute_callback'    => array( $this, 'party_add_name' ),
				// Public by design: the 256-bit invite token is the gate, checked inside.
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'     => false,
						'idempotent'   => false,
						'destructive'  => false,
						'instructions' => __( 'Unknown tokens return invalid_invite; revoked ones invite_revoked. A used-up allotment returns party_allotment_full; a full event guest_list_full.', 'extrachill-events' ),
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/party-remove-guest-list-name',
			array(
				'label'               => __( 'Invited Party Removes Guest List Name', 'extrachill-events' ),
				'description'         => __( 'An invited band or management takes one of its own guests off the list by pass code, through its invite token. Frees the spot.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'token' => self::token_property(),
						'code'  => array(
							'type'        => 'string',
							'description' => __( 'Pass code of a guest this party added.', 'extrachill-events' ),
						),
					),
					'required'   => array( 'token', 'code' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'removed' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'party_remove_name' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => false,
						'destructive' => true,
					),
				),
			)
		);
	}

	/**
	 * Host invites a party.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function create_party( array $input ) {
		return extrachill_events_create_guest_list_party(
			(int) ( $input['event_id'] ?? 0 ),
			(string) ( $input['name'] ?? '' ),
			(string) ( $input['email'] ?? '' ),
			isset( $input['allotment'] ) ? (int) $input['allotment'] : null,
			get_current_user_id()
		);
	}

	/**
	 * Host lists parties.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function list_parties( array $input ) {
		return extrachill_events_list_guest_list_parties( (int) ( $input['event_id'] ?? 0 ) );
	}

	/**
	 * Host revokes a party.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function revoke_party( array $input ) {
		return extrachill_events_revoke_guest_list_party( (int) ( $input['event_id'] ?? 0 ), (int) ( $input['party_id'] ?? 0 ) );
	}

	/**
	 * Host sets a party's allotment.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function set_allotment( array $input ) {
		return extrachill_events_set_guest_list_party_allotment(
			(int) ( $input['event_id'] ?? 0 ),
			(int) ( $input['party_id'] ?? 0 ),
			(int) ( $input['allotment'] ?? 0 )
		);
	}

	/**
	 * Party adds a guest through its token.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function party_add_name( array $input ) {
		return extrachill_events_party_add_guest_list_name(
			(string) ( $input['token'] ?? '' ),
			(string) ( $input['name'] ?? '' ),
			(string) ( $input['email'] ?? '' )
		);
	}

	/**
	 * Party removes one of its guests through its token.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function party_remove_name( array $input ) {
		return extrachill_events_party_remove_guest_list_name(
			(string) ( $input['token'] ?? '' ),
			(string) ( $input['code'] ?? '' )
		);
	}
}
