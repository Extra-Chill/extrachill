<?php
/**
 * Owner's Guest List Abilities (extrachill#1130)
 *
 * A venue's or promoter's permanent guest list: list, add, remove. Behavior
 * lives in inc/core/owner-guest-list.php. Only the venue's active team, the
 * promoter's active members, and admins may touch a list
 * (extrachill_events_user_can_manage_owner_list()). Door check-in is not
 * here: it rides extrachill/redeem-event-pass, whose code path falls
 * through to the owner's list for codes that are not per-event passes.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OwnerGuestListAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/**
	 * Shared input properties: which list.
	 *
	 * @return array
	 */
	private static function owner_properties(): array {
		return array(
			'owner_type' => array(
				'type'        => 'string',
				'enum'        => array( 'venue', 'promoter' ),
				'description' => __( 'Who owns the list: a venue or a promoter.', 'extrachill-events' ),
			),
			'owner_id'   => array(
				'type'        => 'integer',
				'description' => __( 'Venue or promoter term ID.', 'extrachill-events' ),
			),
		);
	}

	/**
	 * Output schema of one person on the list.
	 *
	 * @return array
	 */
	private static function member_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'member_id'  => array( 'type' => 'integer' ),
				'name'       => array( 'type' => 'string' ),
				'email'      => array( 'type' => 'string' ),
				'code'       => array( 'type' => 'string' ),
				'status'     => array( 'type' => 'string' ),
				'pass_url'   => array( 'type' => 'string' ),
				'qr_url'     => array( 'type' => 'string' ),
				'added_by'   => array( 'type' => 'string' ),
				'created_at' => array( 'type' => 'string' ),
			),
		);
	}

	public function register(): void {
		$owner_schema = array(
			'owner_type' => array( 'type' => 'string' ),
			'owner_id'   => array( 'type' => 'integer' ),
			'owner_name' => array( 'type' => 'string' ),
			'label'      => array( 'type' => 'string' ),
		);

		wp_register_ability(
			'extrachill/list-owner-guest-list',
			array(
				'label'               => __( 'List Owner\'s Guest List', 'extrachill-events' ),
				'description'         => __( 'Venue team or promoter members only: list the people on a venue\'s or promoter\'s permanent guest list, with their pass links.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => self::owner_properties(),
					'required'   => array( 'owner_type', 'owner_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => $owner_schema + array(
						'members' => array(
							'type'  => 'array',
							'items' => self::member_schema(),
						),
					),
				),
				'execute_callback'    => array( $this, 'list_members' ),
				'permission_callback' => array( $this, 'can_manage_owner_list' ),
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
			'extrachill/add-owner-guest-list-name',
			array(
				'label'               => __( 'Add To Owner\'s Guest List', 'extrachill-events' ),
				'description'         => __( 'Venue team or promoter members only: put someone on the permanent guest list. They get one pass that checks in at every published show tagged with that venue or promoter, without using the show\'s guest list spots. Emails the pass when an email is given.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => self::owner_properties() + array(
						'name'  => array(
							'type'        => 'string',
							'description' => __( 'Name shown at the door.', 'extrachill-events' ),
						),
						'email' => array(
							'type'        => 'string',
							'description' => __( 'Optional: where to send the pass.', 'extrachill-events' ),
						),
					),
					'required'   => array( 'owner_type', 'owner_id', 'name' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => $owner_schema + self::member_schema()['properties'] + array(
						'emailed' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'add_member' ),
				'permission_callback' => array( $this, 'can_manage_owner_list' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => false,
						'destructive' => false,
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/remove-owner-guest-list-name',
			array(
				'label'               => __( 'Remove From Owner\'s Guest List', 'extrachill-events' ),
				'description'         => __( 'Venue team or promoter members only: take someone off the permanent guest list. Their pass stops working at once; past check-ins stay on record.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => self::owner_properties() + array(
						'member_id' => array(
							'type'        => 'integer',
							'description' => __( 'Person ID, from extrachill/list-owner-guest-list.', 'extrachill-events' ),
						),
					),
					'required'   => array( 'owner_type', 'owner_id', 'member_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'removed'   => array( 'type' => 'boolean' ),
						'member_id' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'remove_member' ),
				'permission_callback' => array( $this, 'can_manage_owner_list' ),
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
	}

	/**
	 * Authorize: the owning venue's team, the promoter's members, or an admin.
	 *
	 * @param array $input Ability input.
	 * @return bool|\WP_Error
	 */
	public function can_manage_owner_list( array $input ) {
		$owner_type = (string) ( $input['owner_type'] ?? '' );
		$owner_id   = (int) ( $input['owner_id'] ?? 0 );

		return extrachill_events_user_can_manage_owner_list( get_current_user_id(), $owner_type, $owner_id )
			? true
			: new \WP_Error( 'owner_list_forbidden', __( 'Only this venue\'s or promoter\'s team can manage its guest list.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	/**
	 * List the people on the list.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function list_members( array $input ) {
		return extrachill_events_list_owner_list( (string) $input['owner_type'], (int) $input['owner_id'] );
	}

	/**
	 * Add someone.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function add_member( array $input ) {
		return extrachill_events_add_owner_list_name(
			(string) $input['owner_type'],
			(int) $input['owner_id'],
			(string) ( $input['name'] ?? '' ),
			(string) ( $input['email'] ?? '' ),
			get_current_user_id()
		);
	}

	/**
	 * Remove someone.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function remove_member( array $input ) {
		return extrachill_events_remove_owner_list_name(
			(string) $input['owner_type'],
			(int) $input['owner_id'],
			(int) ( $input['member_id'] ?? 0 ),
			get_current_user_id()
		);
	}
}
