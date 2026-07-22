<?php
/**
 * Venue membership abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueMembershipService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers owner/admin-managed venue membership operations. */
class VenueMembershipAbilities {

	private static bool $registered = false;

	/** @var VenueAuthorization */
	private $authorization;

	/** @var VenueMembershipService */
	private $service;

	public function __construct( ?VenueMembershipService $service = null, ?VenueAuthorization $authorization = null ) {
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->service       = $service ? $service : new VenueMembershipService( null, $this->authorization );
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register the four venue membership contracts. */
	public function register(): void {
		$base_properties = array(
			'venue_term_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Events-site venue term ID.', 'extrachill-events' ),
			),
			'user_id'       => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Network user ID.', 'extrachill-events' ),
			),
		);
		$role_schema     = array(
			'type' => 'string',
			'enum' => VenueAuthorization::roles(),
		);
		$version_schema  = array(
			'type'    => 'integer',
			'minimum' => 1,
		);

		$this->register_ability(
			'extrachill/create-venue-membership',
			__( 'Create Venue Membership', 'extrachill-events' ),
			__( 'Add an existing network user to a venue.', 'extrachill-events' ),
			array_merge( $base_properties, array( 'role' => $role_schema ) ),
			array( 'venue_term_id', 'user_id', 'role' ),
			array( $this, 'create_membership' ),
			false,
			false,
			false
		);

		$this->register_ability(
			'extrachill/update-venue-membership',
			__( 'Update Venue Membership', 'extrachill-events' ),
			__( 'Change a venue member role at an expected version.', 'extrachill-events' ),
			array_merge(
				$base_properties,
				array(
					'role'             => $role_schema,
					'expected_version' => $version_schema,
				)
			),
			array( 'venue_term_id', 'user_id', 'role', 'expected_version' ),
			array( $this, 'update_membership' ),
			false,
			false,
			true
		);

		$this->register_ability(
			'extrachill/revoke-venue-membership',
			__( 'Revoke Venue Membership', 'extrachill-events' ),
			__( 'Revoke a venue membership at an expected version.', 'extrachill-events' ),
			array_merge( $base_properties, array( 'expected_version' => $version_schema ) ),
			array( 'venue_term_id', 'user_id', 'expected_version' ),
			array( $this, 'revoke_membership' ),
			false,
			false,
			true
		);

		$this->register_ability(
			'extrachill/list-venue-memberships',
			__( 'List Venue Memberships', 'extrachill-events' ),
			__( 'List members for one authorized venue.', 'extrachill-events' ),
			array(
				'venue_term_id' => $base_properties['venue_term_id'],
				'role'          => array(
					'type' => 'string',
					'enum' => VenueAuthorization::roles(),
				),
				'status'        => array(
					'type' => 'string',
					'enum' => VenueAuthorization::statuses(),
				),
				'limit'         => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 50,
				),
				'offset'        => array(
					'type'    => 'integer',
					'minimum' => 0,
					'default' => 0,
				),
			),
			array( 'venue_term_id' ),
			array( $this, 'list_memberships' ),
			true,
			true,
			false,
			array(
				'type'  => 'array',
				'items' => $this->membership_schema(),
			)
		);
	}

	/** Canonical permission callback shared by every operation. */
	public function can_manage_members( array $input ) {
		return $this->authorization->authorize(
			get_current_user_id(),
			absint( $input['venue_term_id'] ?? 0 ),
			VenueAuthorization::ACTION_MANAGE_MEMBERS
		);
	}

	public function create_membership( array $input ) {
		return $this->service->create( get_current_user_id(), absint( $input['venue_term_id'] ?? 0 ), absint( $input['user_id'] ?? 0 ), (string) ( $input['role'] ?? '' ) );
	}

	public function update_membership( array $input ) {
		return $this->service->update_role(
			get_current_user_id(),
			absint( $input['venue_term_id'] ?? 0 ),
			absint( $input['user_id'] ?? 0 ),
			(string) ( $input['role'] ?? '' ),
			absint( $input['expected_version'] ?? 0 )
		);
	}

	public function revoke_membership( array $input ) {
		return $this->service->revoke(
			get_current_user_id(),
			absint( $input['venue_term_id'] ?? 0 ),
			absint( $input['user_id'] ?? 0 ),
			absint( $input['expected_version'] ?? 0 )
		);
	}

	public function list_memberships( array $input ) {
		return $this->service->list( get_current_user_id(), absint( $input['venue_term_id'] ?? 0 ), $input );
	}

	/** Register one operation with shared authorization and metadata. */
	private function register_ability( string $name, string $label, string $description, array $properties, array $required, callable $execute, bool $is_readonly, bool $idempotent, bool $destructive, ?array $output_schema = null ): void {
		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => $required,
					'additionalProperties' => false,
				),
				'output_schema'       => $output_schema ? $output_schema : $this->membership_schema(),
				'execute_callback'    => $execute,
				'permission_callback' => array( $this, 'can_manage_members' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => $is_readonly,
						'idempotent'  => $idempotent,
						'destructive' => $destructive,
					),
				),
			)
		);
	}

	/** Shared public membership result shape. */
	private function membership_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'                 => array( 'type' => 'integer' ),
				'venue_term_id'      => array( 'type' => 'integer' ),
				'user_id'            => array( 'type' => 'integer' ),
				'role'               => array(
					'type' => 'string',
					'enum' => VenueAuthorization::roles(),
				),
				'status'             => array(
					'type' => 'string',
					'enum' => VenueAuthorization::statuses(),
				),
				'version'            => array( 'type' => 'integer' ),
				'created_by_user_id' => array( 'type' => 'integer' ),
				'created_at'         => array( 'type' => 'string' ),
				'updated_at'         => array( 'type' => 'string' ),
				'revoked_at'         => array(
					'type' => array( 'string', 'null' ),
				),
			),
		);
	}
}
