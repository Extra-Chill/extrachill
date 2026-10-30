<?php
/**
 * Self-scoped managed identity workspace abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\PromoterAuthorityRepository;
use ExtraChillEvents\Core\PromoterAuthorization;
use ExtraChillEvents\Core\PromoterVenueGrantRepository;
use ExtraChillEvents\Core\PromoterWorkspace;

defined( 'ABSPATH' ) || exit;

/** Registers closed identity listing and exact context resolution contracts. */
final class PromoterWorkspaceAbilities {
	/**
	 * Whether the ability lifecycle hook has registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;
	/**
	 * Workspace read model.
	 *
	 * @var PromoterWorkspace
	 */
	private $workspace;

	/**
	 * Construct the read-only ability adapter.
	 *
	 * @param PromoterWorkspace|null $workspace Read model override.
	 */
	public function __construct( ?PromoterWorkspace $workspace = null ) {
		$this->workspace = $workspace ? $workspace : new PromoterWorkspace();
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register self-scoped REST-visible reads. */
	public function register(): void {
		$this->register_ability(
			'extrachill/list-managed-workspace-identities',
			__( 'List Managed Workspace Identities', 'extrachill-events' ),
			array(),
			array(),
			array( $this, 'identities' ),
			$this->identity_list_schema()
		);
		$this->register_ability(
			'extrachill/resolve-managed-workspace-context',
			__( 'Resolve Managed Workspace Context', 'extrachill-events' ),
			array(
				'selected_reference' => array(
					'type'      => 'string',
					'maxLength' => 30,
					'pattern'   => '^(?:|venue:[1-9][0-9]{0,9}|promoter:[1-9][0-9]{0,9})$',
				),
			),
			array( 'selected_reference' ),
			array( $this, 'resolve' ),
			$this->context_schema()
		);
	}

	/** Require only an effective authenticated WordPress actor. */
	public function can_read(): bool {
		$user_id = PromoterAuthorization::effective_user_id();
		return $user_id > 0 && (bool) get_userdata( $user_id );
	}

	/** Execute the current-actor identity projection. */
	public function identities() {
		return $this->workspace->identities();
	}

	/**
	 * Resolve one exact caller-selected typed reference.
	 *
	 * @param array $input Closed ability input.
	 */
	public function resolve( array $input ) {
		return $this->workspace->resolve( (string) ( $input['selected_reference'] ?? '' ) );
	}

	/**
	 * Register one closed read-only ability.
	 *
	 * @param string   $name          Ability name.
	 * @param string   $label         Human-readable label.
	 * @param array    $properties    Input properties.
	 * @param array    $required      Required input keys.
	 * @param callable $execute       Execution callback.
	 * @param array    $output_schema Closed output schema.
	 */
	private function register_ability( string $name, string $label, array $properties, array $required, callable $execute, array $output_schema ): void {
		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $label,
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => $required,
					'additionalProperties' => false,
				),
				'output_schema'       => $output_schema,
				'execute_callback'    => $execute,
				'permission_callback' => array( $this, 'can_read' ),
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

	/** Closed current actor schema. */
	private function actor_schema(): array {
		return $this->object(
			array(
				'id'   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'name' => array(
					'type'      => 'string',
					'maxLength' => 255,
				),
			),
			array( 'id', 'name' )
		);
	}

	/** Closed manageable identity schema. */
	private function identity_schema(): array {
		return $this->object(
			array(
				'reference'   => array(
					'type'      => 'string',
					'maxLength' => 30,
					'pattern'   => '^(?:venue|promoter):[1-9][0-9]{0,9}$',
				),
				'type'        => array(
					'type' => 'string',
					'enum' => array( 'venue', 'promoter' ),
				),
				'id'          => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'name'        => array(
					'type'      => 'string',
					'maxLength' => 255,
				),
				'is_owner'    => array( 'type' => 'boolean' ),
				'permissions' => array(
					'type'     => 'array',
					'maxItems' => 3,
					'items'    => array(
						'type' => 'string',
						'enum' => array( 'access_venue', 'manage_members', 'manage_finances', 'access_promoter' ),
					),
				),
			),
			array( 'reference', 'type', 'id', 'name', 'is_owner', 'permissions' )
		);
	}

	/** Closed list response schema. */
	private function identity_list_schema(): array {
		return $this->object(
			array(
				'actor'      => $this->actor_schema(),
				'identities' => array(
					'type'     => 'array',
					'maxItems' => PromoterWorkspace::MAX_IDENTITIES,
					'items'    => $this->identity_schema(),
				),
			),
			array( 'actor', 'identities' )
		);
	}

	/** Closed full context response schema. */
	private function context_schema(): array {
		$nullable_identity                   = $this->identity_schema();
		$nullable_identity['type']           = array( 'object', 'null' );
		$promoter                            = $this->identity_schema();
		$promoter['type']                    = array( 'object', 'null' );
		$promoter['properties']['link_page'] = $this->object(
			array(
				'status'         => array(
					'type' => 'string',
					'enum' => array( 'available', 'not_provisioned', 'unavailable' ),
				),
				'management_url' => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
			),
			array( 'status', 'management_url' )
		);
		$promoter['required'][]              = 'link_page';
		return $this->object(
			array(
				'actor'                  => $this->actor_schema(),
				'identities'             => array(
					'type'     => 'array',
					'maxItems' => PromoterWorkspace::MAX_IDENTITIES,
					'items'    => $this->identity_schema(),
				),
				'selection'              => $this->object(
					array(
						'reference' => array(
							'type'      => 'string',
							'maxLength' => 30,
						),
						'state'     => array(
							'type' => 'string',
							'enum' => array( 'active', 'empty', 'stale', 'denied' ),
						),
						'type'      => array(
							'type' => 'string',
							'enum' => array( 'venue', 'promoter' ),
						),
						'id'        => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'reason'    => array(
							'type' => 'string',
							'enum' => array( 'invalid', 'unavailable' ),
						),
					),
					array( 'reference', 'state' )
				),
				'promoter'               => $promoter,
				'venue'                  => $nullable_identity,
				'granted_venues'         => array(
					'type'     => 'array',
					'maxItems' => PromoterVenueGrantRepository::MAX_GRANTS,
					'items'    => $this->granted_venue_schema(),
				),
				'promoter_relationships' => array(
					'type'     => 'array',
					'maxItems' => PromoterVenueGrantRepository::MAX_GRANTS,
					'items'    => $this->relationship_schema(),
				),
			),
			array( 'actor', 'identities', 'selection', 'promoter', 'venue', 'granted_venues', 'promoter_relationships' )
		);
	}

	/** Closed active grant projection. */
	private function granted_venue_schema(): array {
		return $this->object(
			array(
				'id'           => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'name'         => array(
					'type'      => 'string',
					'maxLength' => 255,
				),
				'action'       => array(
					'type' => 'string',
					'enum' => PromoterVenueGrantRepository::actions(),
				),
				'action_label' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			array( 'id', 'name', 'action', 'action_label' )
		);
	}

	/** Closed venue-owner relationship projection. */
	private function relationship_schema(): array {
		return $this->object(
			array(
				'promoter_term_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'promoter_name'    => array(
					'type'      => 'string',
					'maxLength' => 255,
				),
				'action'           => array(
					'type' => 'string',
					'enum' => PromoterVenueGrantRepository::actions(),
				),
				'action_label'     => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'status'           => array(
					'type' => 'string',
					'enum' => PromoterAuthorityRepository::statuses(),
				),
			),
			array( 'promoter_term_id', 'promoter_name', 'action', 'action_label', 'status' )
		);
	}

	/**
	 * Build one closed object schema.
	 *
	 * @param array $properties Object properties.
	 * @param array $required   Required property names.
	 */
	private function object( array $properties, array $required ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}
}
