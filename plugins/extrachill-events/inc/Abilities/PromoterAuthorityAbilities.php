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

		$this->register_invitations( $term, $version );

		$this->register_ability(
			'extrachill/verify-promoter-organization',
			__( 'Verify Promoter Organization', 'extrachill-events' ),
			__( 'Verify a promoter term, optionally bootstrapping an initial explicit owner. Without owner_user_id the organization is verified with no members; site administrators manage members afterwards.', 'extrachill-events' ),
			array(
				'promoter_term_id' => $term,
				'owner_user_id'    => array_merge(
					$user,
					array( 'description' => __( 'Optional existing network user ID to bootstrap as the initial owner. Omit to verify without an owner.', 'extrachill-events' ) )
				),
			),
			array( 'promoter_term_id' ),
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
			__( 'Add an existing network user to a verified promoter. Allowed for site administrators and active promoter owners.', 'extrachill-events' ),
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

	/** Register email onboarding through the shared team service. */
	private function register_invitations( array $term, array $version ): void {
		$id                = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$text              = array(
			'type'      => 'string',
			'minLength' => 1,
		);
		$inputs            = array(
			'invite'  => array(
				'promoter_term_id' => $term,
				'email'            => $text,
				'is_owner'         => array( 'type' => 'boolean' ),
			),
			'resend'  => array(
				'invitation_id'    => $id,
				'expected_version' => $version,
			),
			'cancel'  => array(
				'invitation_id'    => $id,
				'expected_version' => $version,
			),
			'list'    => array( 'promoter_term_id' => $term ),
			'respond' => array(
				'public_id'        => $text,
				'token'            => $text,
				'promoter_term_id' => $term,
				'is_owner'         => array( 'type' => 'boolean' ),
				'expected_version' => $version,
				'decision'         => array(
					'type' => 'string',
					'enum' => array( 'accepted', 'rejected' ),
				),
			),
		);
		$authority_service = $this->service;
		$output            = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
		foreach ( array( 'id', 'team_term_id', 'user_id', 'version', 'invited_by_user_id', 'delivery_attempts' ) as $field ) {
			$output['properties'][ $field ] = array( 'type' => 'integer' );
		}
		foreach ( array( 'public_id', 'team_type', 'status', 'created_at', 'updated_at', 'expires_at', 'delivery_status', 'display_name', 'email' ) as $field ) {
			$output['properties'][ $field ] = array( 'type' => 'string' );
		}
		foreach ( array( 'resolved_at', 'delivered_at' ) as $field ) {
			$output['properties'][ $field ] = array( 'type' => array( 'string', 'null' ) );
		}
		foreach ( array( 'is_owner', 'delivery_queued', 'account_created' ) as $field ) {
			$output['properties'][ $field ] = array( 'type' => 'boolean' );
		}
		foreach ( $inputs as $operation => $properties ) {
			$name = 'invite' === $operation ? 'invite-promoter-member' : $operation . '-promoter-invitation' . ( 'list' === $operation ? 's' : '' );
			$this->register_ability(
				'extrachill/' . $name,
				__( 'Promoter Invitation', 'extrachill-events' ),
				__( 'Manage email invitations for a promoter team.', 'extrachill-events' ),
				$properties,
				array_keys( $properties ),
				static function ( array $input ) use ( $operation, $authority_service ) {
					$service = $authority_service->onboarding();
					$actor   = PromoterAuthorization::effective_user_id();
					switch ( $operation ) {
						case 'invite':
							return $service->invite( $actor, $input['promoter_term_id'], $input['email'], $input['is_owner'] );
						case 'resend':
							return $service->resend( $actor, $input['invitation_id'], $input['expected_version'] );
						case 'cancel':
							return $service->cancel_invitation( $actor, $input['invitation_id'], $input['expected_version'] );
						case 'list':
							return $service->list_invitations( $actor, $input['promoter_term_id'] );
						default:
							return $service->respond( $actor, $input['public_id'], $input['token'], $input['promoter_term_id'], $input['is_owner'], $input['expected_version'], $input['decision'] );
					}
				},
				static function () { return PromoterAuthorization::effective_user_id() > 0; },
				'list' === $operation,
				false,
				'cancel' === $operation,
				'list' === $operation ? array(
					'type'  => 'array',
					'items' => $output,
				) : $output
			);
		}
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
	 * Authorize site-administrator or promoter-owner membership management.
	 *
	 * @param array $input Ability input.
	 */
	public function can_manage_members( array $input ) {
		return $this->authorization->authorize( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ), PromoterAuthorization::ACTION_MANAGE_MEMBERS );
	}

	/**
	 * Execute organization verification and optional owner bootstrap.
	 *
	 * @param array $input Ability input.
	 */
	public function verify( array $input ) {
		$owner_user_id = isset( $input['owner_user_id'] ) ? absint( $input['owner_user_id'] ) : null;
		return $this->service->verify( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ), $owner_user_id );
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

	/** Return the closed bootstrap result schema; membership is null when verified without an owner. */
	private function bootstrap_schema(): array {
		$membership         = $this->membership_schema();
		$membership['type'] = array( 'object', 'null' );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'organization' => $this->organization_schema(),
				'membership'   => $membership,
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
