<?php
/**
 * Promoter venue grant abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\PromoterVenueGrantRepository;
use ExtraChillEvents\Core\PromoterVenueGrantService;
use ExtraChillEvents\Core\PromoterAuthorization;

defined( 'ABSPATH' ) || exit;

/** Registers narrow promoter venue grant management contracts. */
final class PromoterVenueGrantAbilities {
	/**
	 * Whether the ability hook has registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;
	/**
	 * Grant service.
	 *
	 * @var PromoterVenueGrantService
	 */
	private $service;

	/** Construct the ability adapter. */
	public function __construct( ?PromoterVenueGrantService $service = null ) {
		$this->service = $service ? $service : new PromoterVenueGrantService();
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register create, revoke, reactivate, and list contracts. */
	public function register(): void {
		$promoter = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Exact current-site promoter term ID.', 'extrachill-events' ),
		);
		$venue    = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Exact current-site venue term ID.', 'extrachill-events' ),
		);
		$action   = array(
			'type' => 'string',
			'enum' => PromoterVenueGrantRepository::actions(),
		);
		$version  = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$base     = array(
			'promoter_term_id' => $promoter,
			'venue_term_id'    => $venue,
			'action'           => $action,
		);

		$this->register_ability( 'extrachill/create-promoter-venue-grant', __( 'Create Promoter Venue Grant', 'extrachill-events' ), __( 'Grant one verified promoter one exact delegated venue action.', 'extrachill-events' ), $base, array( 'promoter_term_id', 'venue_term_id', 'action' ), array( $this, 'create' ), array( $this, 'can_issue' ), false, false, false, $this->grant_schema() );
		$this->register_ability( 'extrachill/revoke-promoter-venue-grant', __( 'Revoke Promoter Venue Grant', 'extrachill-events' ), __( 'Revoke one exact promoter venue grant.', 'extrachill-events' ), array_merge( $base, array( 'expected_version' => $version ) ), array( 'promoter_term_id', 'venue_term_id', 'action', 'expected_version' ), array( $this, 'revoke' ), array( $this, 'can_manage' ), false, false, true, $this->grant_schema() );
		$this->register_ability( 'extrachill/reactivate-promoter-venue-grant', __( 'Reactivate Promoter Venue Grant', 'extrachill-events' ), __( 'Reactivate one revoked promoter venue grant.', 'extrachill-events' ), array_merge( $base, array( 'expected_version' => $version ) ), array( 'promoter_term_id', 'venue_term_id', 'action', 'expected_version' ), array( $this, 'reactivate' ), array( $this, 'can_issue' ), false, false, false, $this->grant_schema() );
		$this->register_ability(
			'extrachill/list-promoter-venue-grants',
			__( 'List Promoter Venue Grants', 'extrachill-events' ),
			__( 'List bounded grants for one exact promoter and venue.', 'extrachill-events' ),
			array(
				'promoter_term_id' => $promoter,
				'venue_term_id'    => $venue,
			),
			array( 'promoter_term_id', 'venue_term_id' ),
			array( $this, 'list_grants' ),
			array( $this, 'can_manage' ),
			true,
			true,
			false,
			array(
				'type'     => 'array',
				'maxItems' => PromoterVenueGrantRepository::MAX_GRANTS,
				'items'    => $this->grant_schema(),
			)
		);
	}

	/** Authorize direct venue-owner issuance. */
	public function can_issue( array $input ) {
		return $this->service->can_issue( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ), absint( $input['venue_term_id'] ?? 0 ) );
	}

	/** Authorize direct venue owners or exact promoter owners. */
	public function can_manage( array $input ) {
		return $this->service->can_manage( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ), absint( $input['venue_term_id'] ?? 0 ) );
	}

	/** Execute grant creation. */
	public function create( array $input ) {
		return $this->service->create( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ), absint( $input['venue_term_id'] ?? 0 ), (string) ( $input['action'] ?? '' ) );
	}

	/** Execute grant revocation. */
	public function revoke( array $input ) {
		return $this->service->revoke( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ), absint( $input['venue_term_id'] ?? 0 ), (string) ( $input['action'] ?? '' ), absint( $input['expected_version'] ?? 0 ) );
	}

	/** Execute grant reactivation. */
	public function reactivate( array $input ) {
		return $this->service->reactivate( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ), absint( $input['venue_term_id'] ?? 0 ), (string) ( $input['action'] ?? '' ), absint( $input['expected_version'] ?? 0 ) );
	}

	/** Execute bounded exact-pair listing. */
	public function list_grants( array $input ) {
		return $this->service->list( PromoterAuthorization::effective_user_id(), absint( $input['promoter_term_id'] ?? 0 ), absint( $input['venue_term_id'] ?? 0 ) );
	}

	/** Register one strict ability contract. */
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

	/** Return the strict closed grant result schema. */
	private function grant_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                 => array( 'type' => 'integer' ),
				'promoter_term_id'   => array( 'type' => 'integer' ),
				'venue_term_id'      => array( 'type' => 'integer' ),
				'action'             => array(
					'type' => 'string',
					'enum' => PromoterVenueGrantRepository::actions(),
				),
				'status'             => array(
					'type' => 'string',
					'enum' => array( 'active', 'revoked' ),
				),
				'version'            => array( 'type' => 'integer' ),
				'created_by_user_id' => array( 'type' => 'integer' ),
				'created_at'         => array( 'type' => 'string' ),
				'updated_by_user_id' => array( 'type' => 'integer' ),
				'updated_at'         => array( 'type' => 'string' ),
				'revoked_by_user_id' => array( 'type' => array( 'integer', 'null' ) ),
				'revoked_at'         => array( 'type' => array( 'string', 'null' ) ),
			),
			'required'             => array( 'id', 'promoter_term_id', 'venue_term_id', 'action', 'status', 'version', 'created_by_user_id', 'created_at', 'updated_by_user_id', 'updated_at', 'revoked_by_user_id', 'revoked_at' ),
			'additionalProperties' => false,
		);
	}
}
