<?php
/**
 * Promoter authority abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\PromoterAuthorityRepository;
use ExtraChillEvents\Core\PromoterAuthorization;
use ExtraChillEvents\Core\PromoterAuthorityService;

defined( 'ABSPATH' ) || exit;

/** Registers the bounded promoter organization management surface. */
final class PromoterAuthorityAbilities {
	/**
	 * Whether the ability lifecycle hook has registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Promoter authorization policy.
	 *
	 * @var PromoterAuthorization
	 */
	private $authorization;

	/**
	 * Promoter authority service.
	 *
	 * @var PromoterAuthorityService
	 */
	private $service;

	/**
	 * Construct the ability adapter.
	 *
	 * @param PromoterAuthorityService|null $service       Service override.
	 * @param PromoterAuthorization|null    $authorization Authorization override.
	 */
	public function __construct( ?PromoterAuthorityService $service = null, ?PromoterAuthorization $authorization = null ) {
		$this->authorization = $authorization ? $authorization : new PromoterAuthorization();
		$this->service       = $service ? $service : new PromoterAuthorityService( null, $this->authorization );
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register all six strict promoter authority contracts. */
	public function register(): void {
		$term    = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Exact current-site promoter term ID.', 'extrachill-events' ),
		);
		$user    = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Existing network user ID.', 'extrachill-events' ),
		);
		$owner   = array(
			'type'        => 'boolean',
			'description' => __( 'Whether this active member may manage promoter membership.', 'extrachill-events' ),
		);
		$version = array(
			'type'    => 'integer',
			'minimum' => 1,
		);

		$this->register_ability(
			'extrachill/verify-promoter-organization',
			__( 'Verify Promoter Organization', 'extrachill-events' ),
			__( 'Verify a promoter term and bootstrap its first explicit owner.', 'extrachill-events' ),
			array(
				'promoter_term_id' => $term,
				'owner_user_id'    => $user,
			),
			array( 'promoter_term_id', 'owner_user_id' ),
			array( $this, 'verify' ),
			array( $this, 'can_verify' ),
			false,
			false,
			false,
			$this->bootstrap_schema()
		);
		$this->register_ability(
			'extrachill/revoke-promoter-organization',
			__( 'Revoke Promoter Organization', 'extrachill-events' ),
			__( 'Revoke a verified promoter organization while preserving its history.', 'extrachill-events' ),
			array(
				'promoter_term_id' => $term,
				'expected_version' => $version,
			),
			array( 'promoter_term_id', 'expected_version' ),
			array( $this, 'revoke_organization' ),
			array( $this, 'can_revoke_organization' ),
			false,
			false,
			true,
			$this->organization_schema()
		);
		$this->register_ability(
			'extrachill/create-promoter-membership',
			__( 'Create Promoter Membership', 'extrachill-events' ),
			__( 'Add an existing network user to a verified promoter.', 'extrachill-events' ),
			array(
				'promoter_term_id' => $term,
				'user_id'          => $user,
				'is_owner'         => $owner,
			),
			array( 'promoter_term_id', 'user_id', 'is_owner' ),
			array( $this, 'create_membership' ),
			array( $this, 'can_manage_members' ),
			false,
			false,
			false,
			$this->membership_schema()
		);
		$this->register_ability(
			'extrachill/update-promoter-membership',
			__( 'Update Promoter Membership', 'extrachill-events' ),
			__( 'Change structural promoter ownership at an expected version.', 'extrachill-events' ),
			array(
				'promoter_term_id' => $term,
				'user_id'          => $user,
				'is_owner'         => $owner,
				'expected_version' => $version,
			),
			array( 'promoter_term_id', 'user_id', 'is_owner', 'expected_version' ),
			array( $this, 'update_membership' ),
			array( $this, 'can_manage_members' ),
			false,
			false,
			true,
			$this->membership_schema()
		);
		$this->register_ability(
			'extrachill/revoke-promoter-membership',
			__( 'Revoke Promoter Membership', 'extrachill-events' ),
			__( 'Revoke a promoter membership at an expected version.', 'extrachill-events' ),
			array(
				'promoter_term_id' => $term,
				'user_id'          => $user,
				'expected_version' => $version,
			),
			array( 'promoter_term_id', 'user_id', 'expected_version' ),
			array( $this, 'revoke_membership' ),
			array( $this, 'can_manage_members' ),
			false,
			false,
			true,
			$this->membership_schema()
		);
		$this->register_ability(
			'extrachill/list-promoter-memberships',
			__( 'List Promoter Memberships', 'extrachill-events' ),
			__( 'List preserved membership records for one authorized promoter.', 'extrachill-events' ),
			array( 'promoter_term_id' => $term ),
			array( 'promoter_term_id' ),
			array( $this, 'list_memberships' ),
			array( $this, 'can_manage_members' ),
			true,
			true,
			false,
			array(
				'type'     => 'array',
				'maxItems' => PromoterAuthorityRepository::MAX_MEMBERS,
				'items'    => $this->membership_schema(),
			)
		);
	}

	/**
	 * Authorize administrator verification.
	 *
	 * @param array $input Ability input.
	 */
	public function can_verify( array $input ) {
		return $this->authorization->authorize( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ), PromoterAuthorization::ACTION_VERIFY_ORGANIZATION );
	}

	/**
	 * Authorize administrator organization revocation.
	 *
	 * @param array $input Ability input.
	 */
	public function can_revoke_organization( array $input ) {
		return $this->authorization->authorize( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ), PromoterAuthorization::ACTION_REVOKE_ORGANIZATION );
	}

	/**
	 * Authorize promoter-owner membership management.
	 *
	 * @param array $input Ability input.
	 */
	public function can_manage_members( array $input ) {
		return $this->authorization->authorize( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ), PromoterAuthorization::ACTION_MANAGE_MEMBERS );
	}

	/**
	 * Execute organization verification and owner bootstrap.
	 *
	 * @param array $input Ability input.
	 */
	public function verify( array $input ) {
		return $this->service->verify( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ), absint( $input['owner_user_id'] ?? 0 ) );
	}

	/**
	 * Execute organization revocation.
	 *
	 * @param array $input Ability input.
	 */
	public function revoke_organization( array $input ) {
		return $this->service->revoke_organization( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ), absint( $input['expected_version'] ?? 0 ) );
	}

	/**
	 * Execute membership creation.
	 *
	 * @param array $input Ability input.
	 */
	public function create_membership( array $input ) {
		return $this->service->create_membership( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ), absint( $input['user_id'] ?? 0 ), (bool) ( $input['is_owner'] ?? false ) );
	}

	/**
	 * Execute membership owner-state update.
	 *
	 * @param array $input Ability input.
	 */
	public function update_membership( array $input ) {
		return $this->service->update_membership( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ), absint( $input['user_id'] ?? 0 ), (bool) ( $input['is_owner'] ?? false ), absint( $input['expected_version'] ?? 0 ) );
	}

	/**
	 * Execute membership revocation.
	 *
	 * @param array $input Ability input.
	 */
	public function revoke_membership( array $input ) {
		return $this->service->revoke_membership( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ), absint( $input['user_id'] ?? 0 ), absint( $input['expected_version'] ?? 0 ) );
	}

	/**
	 * Execute bounded membership listing.
	 *
	 * @param array $input Ability input.
	 */
	public function list_memberships( array $input ) {
		return $this->service->list_memberships( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ) );
	}

	/**
	 * Register one strict ability contract.
	 *
	 * @param string   $name          Ability name.
	 * @param string   $label         Human-readable label.
	 * @param string   $description   Human-readable description.
	 * @param array    $properties    Input properties.
	 * @param string[] $required      Required input names.
	 * @param callable $execute       Execution callback.
	 * @param callable $permission    Permission callback.
	 * @param bool     $is_readonly   Whether the operation is read-only.
	 * @param bool     $idempotent    Whether repeated calls are idempotent.
	 * @param bool     $destructive   Whether the operation is destructive.
	 * @param array    $output_schema Strict output schema.
	 */
	private function register_ability( string $name, string $label, string $description, array $properties, array $required, callable $execute, callable $permission, bool $is_readonly, bool $idempotent, bool $destructive, array $output_schema ): void {
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
				'output_schema'       => $output_schema,
				'execute_callback'    => $execute,
				'permission_callback' => $permission,
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

	/** Return the closed bootstrap result schema. */
	private function bootstrap_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'organization' => $this->organization_schema(),
				'membership'   => $this->membership_schema(),
			),
			'required'             => array( 'organization', 'membership' ),
			'additionalProperties' => false,
		);
	}

	/** Return the closed organization result schema. */
	private function organization_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                  => array( 'type' => 'integer' ),
				'promoter_term_id'    => array( 'type' => 'integer' ),
				'status'              => array(
					'type' => 'string',
					'enum' => PromoterAuthorityRepository::statuses(),
				),
				'version'             => array( 'type' => 'integer' ),
				'verified_by_user_id' => array( 'type' => 'integer' ),
				'verified_at'         => array( 'type' => 'string' ),
				'updated_at'          => array( 'type' => 'string' ),
				'revoked_by_user_id'  => array( 'type' => array( 'integer', 'null' ) ),
				'revoked_at'          => array( 'type' => array( 'string', 'null' ) ),
			),
			'required'             => array( 'id', 'promoter_term_id', 'status', 'version', 'verified_by_user_id', 'verified_at', 'updated_at', 'revoked_by_user_id', 'revoked_at' ),
			'additionalProperties' => false,
		);
	}

	/** Return the closed membership result schema. */
	private function membership_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                 => array( 'type' => 'integer' ),
				'promoter_term_id'   => array( 'type' => 'integer' ),
				'user_id'            => array( 'type' => 'integer' ),
				'is_owner'           => array( 'type' => 'boolean' ),
				'status'             => array(
					'type' => 'string',
					'enum' => PromoterAuthorityRepository::statuses(),
				),
				'version'            => array( 'type' => 'integer' ),
				'created_by_user_id' => array( 'type' => 'integer' ),
				'created_at'         => array( 'type' => 'string' ),
				'updated_at'         => array( 'type' => 'string' ),
				'revoked_by_user_id' => array( 'type' => array( 'integer', 'null' ) ),
				'revoked_at'         => array( 'type' => array( 'string', 'null' ) ),
			),
			'required'             => array( 'id', 'promoter_term_id', 'user_id', 'is_owner', 'status', 'version', 'created_by_user_id', 'created_at', 'updated_at', 'revoked_by_user_id', 'revoked_at' ),
			'additionalProperties' => false,
		);
	}
}
