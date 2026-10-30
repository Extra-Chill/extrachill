<?php
/**
 * Experiment assignment ability.
 *
 * @package ExtraChillNetwork\Abilities
 */

namespace ExtraChillNetwork\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes cache-safe deterministic assignment through the core Abilities API.
 */
class ExperimentAssignmentAbility {

	/**
	 * Register on Abilities API initialization.
	 */
	public function __construct() {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register public assignment/exposure and private lifecycle abilities.
	 */
	public function register(): void {
		wp_register_ability(
			'extrachill/resolve-experiment-assignment',
			array(
				'label'               => __( 'Resolve Experiment Assignment', 'extrachill-network' ),
				'description'         => __( 'Resolve a deterministic variant for an eligible, privacy-permitted experiment subject.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'experiment_key', 'surface' ),
					'additionalProperties' => false,
					'properties'           => array(
						'experiment_key' => array(
							'type'      => 'string',
							'pattern'   => '^[a-z0-9][a-z0-9_-]{0,63}$',
							'maxLength' => 64,
						),
						'surface'        => array(
							'type'      => 'string',
							'pattern'   => '^[a-z0-9][a-z0-9_-]{0,63}$',
							'maxLength' => 64,
						),
						'context'        => array(
							'type'                 => 'object',
							'maxProperties'        => 10,
							'additionalProperties' => array(
								'type' => array( 'string', 'integer', 'number', 'boolean' ),
							),
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'experiment_key', 'definition_version', 'assignment_policy', 'variant', 'surface', 'measurement_eligible', 'exposure_token' ),
					'additionalProperties' => false,
					'properties'           => array(
						'experiment_key'       => array( 'type' => 'string' ),
						'definition_version'   => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => \EXTRACHILL_EXPERIMENT_MAX_DEFINITION_VERSION,
						),
						'assignment_policy'    => array( 'type' => 'string' ),
						'variant'              => array( 'type' => 'string' ),
						'surface'              => array( 'type' => 'string' ),
						'measurement_eligible' => array( 'type' => 'boolean' ),
						'exposure_token'       => array(
							'type'      => 'string',
							'maxLength' => 108,
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/record-experiment-exposure',
			array(
				'label'               => __( 'Record Experiment Exposure', 'extrachill-network' ),
				'description'         => __( 'Validate a signed assignment proof and emit a trusted viewport exposure hook.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'experiment_key', 'definition_version', 'assignment_policy', 'variant', 'surface', 'exposure_token' ),
					'additionalProperties' => false,
					'properties'           => array(
						'experiment_key'     => $this->identifier_schema(),
						'definition_version' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => \EXTRACHILL_EXPERIMENT_MAX_DEFINITION_VERSION,
						),
						'assignment_policy'  => array(
							'type' => 'string',
							'enum' => array( \EXTRACHILL_EXPERIMENT_ASSIGNMENT_POLICY ),
						),
						'variant'            => $this->identifier_schema(),
						'surface'            => $this->identifier_schema(),
						'context'            => $this->context_schema(),
						'exposure_token'     => array(
							'type'      => 'string',
							'pattern'   => '^\\d{10}\\.[a-f0-9]{32}\\.[a-f0-9]{64}$',
							'maxLength' => 108,
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'accepted' ),
					'additionalProperties' => false,
					'properties'           => array(
						'accepted' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_exposure' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'idempotent' => false,
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/list-experiments',
			array(
				'label'               => __( 'List Experiments', 'extrachill-network' ),
				'description'         => __( 'List normalized code definitions and their effective network lifecycle states.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'items', 'registered_count', 'orphan_count', 'orphan_samples_truncated', 'lifecycle_over_bound' ),
					'additionalProperties' => false,
					'properties'           => array(
						'items'                    => array(
							'type'     => 'array',
							'maxItems' => \EXTRACHILL_EXPERIMENT_MAX_LIST_ITEMS,
							'items'    => array(
								'type'                 => 'object',
								'required'             => array( 'key', 'registered', 'orphaned', 'definition_version', 'assignment_policy', 'default_state', 'state', 'default_variant', 'control_variant', 'variants', 'surfaces' ),
								'additionalProperties' => false,
								'properties'           => array(
									'key'                => $this->identifier_schema(),
									'registered'         => array( 'type' => 'boolean' ),
									'orphaned'           => array( 'type' => 'boolean' ),
									'definition_version' => array(
										'type'    => 'integer',
										'minimum' => 0,
										'maximum' => \EXTRACHILL_EXPERIMENT_MAX_DEFINITION_VERSION,
									),
									'assignment_policy'  => array(
										'type' => 'string',
										'enum' => array( '', \EXTRACHILL_EXPERIMENT_ASSIGNMENT_POLICY ),
									),
									'default_state'      => array(
										'type' => 'string',
										'enum' => \EXTRACHILL_EXPERIMENT_STATES,
									),
									'state'              => array(
										'type' => 'string',
										'enum' => \EXTRACHILL_EXPERIMENT_STATES,
									),
									'default_variant'    => array(
										'type'      => 'string',
										'maxLength' => 64,
									),
									'control_variant'    => array(
										'type'      => 'string',
										'maxLength' => 64,
									),
									'variants'           => array(
										'type'          => 'object',
										'maxProperties' => \EXTRACHILL_EXPERIMENT_MAX_VARIANTS,
										'additionalProperties' => array(
											'type'    => 'integer',
											'minimum' => 1,
											'maximum' => \EXTRACHILL_EXPERIMENT_MAX_VARIANT_WEIGHT,
										),
									),
									'surfaces'           => array(
										'type'        => 'array',
										'maxItems'    => \EXTRACHILL_EXPERIMENT_MAX_SURFACES,
										'uniqueItems' => true,
										'items'       => $this->identifier_schema(),
									),
								),
							),
						),
						'registered_count'         => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => \EXTRACHILL_EXPERIMENT_MAX_DEFINITIONS,
						),
						'orphan_count'             => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => \EXTRACHILL_EXPERIMENT_MAX_REPORTED_ORPHANS,
						),
						'orphan_samples_truncated' => array( 'type' => 'boolean' ),
						'lifecycle_over_bound'     => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_list' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/transition-experiment-state',
			array(
				'label'               => __( 'Transition Experiment State', 'extrachill-network' ),
				'description'         => __( 'Transition one registered experiment definition version to a valid lifecycle state.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'experiment_key', 'definition_version', 'state' ),
					'additionalProperties' => false,
					'properties'           => array(
						'experiment_key'     => $this->identifier_schema(),
						'definition_version' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => \EXTRACHILL_EXPERIMENT_MAX_DEFINITION_VERSION,
						),
						'state'              => array(
							'type' => 'string',
							'enum' => \EXTRACHILL_EXPERIMENT_STATES,
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'experiment_key', 'definition_version', 'previous_state', 'state' ),
					'additionalProperties' => false,
					'properties'           => array(
						'experiment_key'     => array( 'type' => 'string' ),
						'definition_version' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => \EXTRACHILL_EXPERIMENT_MAX_DEFINITION_VERSION,
						),
						'previous_state'     => array( 'type' => 'string' ),
						'state'              => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_transition' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Return the bounded identifier schema shared by both abilities.
	 *
	 * @return array<string, mixed>
	 */
	private function identifier_schema(): array {
		return array(
			'type'      => 'string',
			'pattern'   => '^[a-z0-9][a-z0-9_-]{0,63}$',
			'maxLength' => 64,
		);
	}

	/**
	 * Return the bounded consumer context schema.
	 *
	 * @return array<string, mixed>
	 */
	private function context_schema(): array {
		return array(
			'type'                 => 'object',
			'maxProperties'        => 10,
			'additionalProperties' => array(
				'type' => array( 'string', 'integer', 'number', 'boolean' ),
			),
		);
	}

	/**
	 * Resolve one assignment.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		$context = isset( $input['context'] ) && is_array( $input['context'] ) ? $input['context'] : array();

		return extrachill_resolve_experiment_assignment(
			(string) $input['experiment_key'],
			(string) $input['surface'],
			$context
		);
	}

	/**
	 * Validate and emit one trusted viewport exposure.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array{accepted: bool}|\WP_Error
	 */
	public function execute_exposure( array $input ) {
		$context  = isset( $input['context'] ) && is_array( $input['context'] ) ? $input['context'] : array();
		$metadata = extrachill_validate_experiment_exposure(
			(string) $input['experiment_key'],
			(int) $input['definition_version'],
			(string) $input['assignment_policy'],
			(string) $input['variant'],
			(string) $input['surface'],
			$context,
			(string) $input['exposure_token']
		);
		if ( null === $metadata ) {
			return new \WP_Error( 'invalid_experiment_exposure', __( 'Experiment exposure proof is invalid or expired.', 'extrachill-network' ), array( 'status' => 400 ) );
		}
		if ( ! extrachill_consume_experiment_exposure_token( (string) $input['exposure_token'] ) ) {
			return new \WP_Error( 'experiment_exposure_already_consumed', __( 'Experiment exposure proof was already consumed.', 'extrachill-network' ), array( 'status' => 409 ) );
		}

		/**
		 * Fires after a signed viewport exposure is validated server-side.
		 *
		 * Analytics may consume this hook for persistence. Browser DOM events do
		 * not reach this hook without a valid subject/context-bound proof.
		 *
		 * @param array{experiment_key: string, definition_version: int, assignment_policy: string, variant: string, surface: string} $metadata Bounded exposure metadata.
		 */
		do_action( 'extrachill_experiment_exposure', $metadata );

		return array( 'accepted' => true );
	}

	/**
	 * Require a network-options capability for lifecycle administration.
	 *
	 * Cookie-authenticated REST calls are nonce-validated by WordPress before
	 * the Ability permission callback runs.
	 */
	public function check_admin_permission(): bool {
		return extrachill_experiment_admin_permission();
	}

	/**
	 * List audit-safe definitions and effective states.
	 *
	 * @return array<string, mixed>
	 */
	public function execute_list(): array {
		return extrachill_list_experiments();
	}

	/**
	 * Transition one current code definition version.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_transition( array $input ) {
		return extrachill_transition_experiment_state(
			(string) $input['experiment_key'],
			(int) $input['definition_version'],
			(string) $input['state']
		);
	}
}
