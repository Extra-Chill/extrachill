<?php
/**
 * Read-only event source ramp ability.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\EventSourceRampEvaluator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the read-only source ramp evaluation ability. */
class EventSourceRampAbilities {

	/**
	 * Whether the registration hook has already been attached.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/** Attach ability registration once. */
	public function __construct() {
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register the source ramp ability. */
	public function register(): void {
		wp_register_ability(
			'extrachill/evaluate-event-source-ramp',
			array(
				'label'               => __( 'Evaluate Event Source Ramp', 'extrachill-events' ),
				'description'         => __( 'Evaluate timestamped source-class ramp evidence and return a reversible, non-executed plan.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => self::input_schema(),
				'output_schema'       => self::output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
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
	 * Return the ability input schema.
	 *
	 * @return array<string,mixed>
	 */
	public static function input_schema(): array {
		return array(
			'$schema'              => 'https://json-schema.org/draft/2020-12/schema',
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'source_class', 'current_max_items', 'phase', 'scope', 'scope_id', 'evidence' ),
			'properties'           => array(
				'source_class'      => array(
					'type' => 'string',
					'enum' => array( 'ticketmaster', 'dice', 'universal_scraper' ),
				),
				'current_max_items' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'phase'             => array(
					'type' => 'string',
					'enum' => array( 'preflight', 'postflight' ),
				),
				'scope'             => array(
					'type' => 'string',
					'enum' => array( 'pipeline', 'flow' ),
				),
				'scope_id'          => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'evidence'          => self::evidence_schema(),
			),
		);
	}

	/**
	 * Return the ability output schema.
	 *
	 * @return array<string,mixed>
	 */
	public static function output_schema(): array {
		return array(
			'$schema'              => 'https://json-schema.org/draft/2020-12/schema',
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'schema_version', 'generated_at', 'phase', 'source_class', 'profile', 'scope', 'current_stage', 'proposed_stage', 'decision', 'can_advance', 'failed_gates', 'gates', 'evidence', 'apply_plan' ),
			'properties'           => array(
				'schema_version' => array(
					'type'  => 'string',
					'const' => EventSourceRampEvaluator::SCHEMA_VERSION,
				),
				'generated_at'   => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'phase'          => array(
					'type' => 'string',
					'enum' => array( 'preflight', 'postflight' ),
				),
				'source_class'   => array( 'type' => 'string' ),
				'profile'        => array( 'type' => 'object' ),
				'scope'          => array( 'type' => 'object' ),
				'current_stage'  => array( 'type' => 'integer' ),
				'proposed_stage' => array( 'type' => array( 'integer', 'null' ) ),
				'decision'       => array(
					'type' => 'string',
					'enum' => array( 'advance', 'hold', 'rollback', 'complete' ),
				),
				'can_advance'    => array( 'type' => 'boolean' ),
				'failed_gates'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'gates'          => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'evidence'       => array( 'type' => 'object' ),
				'apply_plan'     => array( 'type' => 'object' ),
			),
		);
	}

	/**
	 * Return the submitted evidence schema.
	 *
	 * Individual metrics are optional at schema validation so the evaluator can
	 * return machine-readable missing-gate decisions instead of transport errors.
	 *
	 * @return array<string,mixed>
	 */
	public static function evidence_schema(): array {
		$metric_names = array_keys( EventSourceRampEvaluator::profiles()['ticketmaster']['thresholds'] );
		$metrics      = array();
		foreach ( $metric_names as $name ) {
			$metrics[ $name ] = array( 'type' => 'number' );
		}

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'schema_version', 'observed_at', 'window_start', 'window_end', 'provenance', 'metrics' ),
			'properties'           => array(
				'schema_version' => array(
					'type'  => 'string',
					'const' => EventSourceRampEvaluator::SCHEMA_VERSION,
				),
				'observed_at'    => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'window_start'   => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'window_end'     => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'provenance'     => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'metrics'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => $metrics,
				),
				'cadence_status' => array( 'type' => array( 'object', 'null' ) ),
			),
		);
	}

	/**
	 * Evaluate supplied evidence without runtime mutation.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	public function execute( array $input ): array {
		return ( new EventSourceRampEvaluator() )->evaluate( $input );
	}
}
