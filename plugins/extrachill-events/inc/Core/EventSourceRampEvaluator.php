<?php
/**
 * Deterministic source-class ramp gate evaluation.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Evaluates supplied operational evidence without reading or mutating runtime state. */
class EventSourceRampEvaluator {

	public const SCHEMA_VERSION               = '1.0.0';
	public const MINIMUM_WINDOW_HOURS         = 24;
	public const MAXIMUM_EVIDENCE_AGE_SECONDS = 7200;

	/**
	 * Required evidence provenance keys.
	 *
	 * @var string[]
	 */
	private const REQUIRED_PROVENANCE = array(
		'flow_settings',
		'jobs_liveness',
		'job_metrics',
		'action_scheduler',
		'event_quality',
		'bundle_wave',
	);

	/**
	 * Return the policy profiles used by both the evaluator and operator output.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function profiles(): array {
		$common = array(
			'queue_depth'                     => array(
				'operator' => 'max',
				'value'    => 25,
			),
			'oldest_queue_age_seconds'        => array(
				'operator' => 'max',
				'value'    => 7200,
			),
			'jobs_without_scheduler_path'     => array(
				'operator' => 'max',
				'value'    => 0,
			),
			'failed_rate'                     => array(
				'operator' => 'max',
				'value'    => 0.05,
			),
			'deferred_rate'                   => array(
				'operator' => 'max',
				'value'    => 0.10,
			),
			'ai_defer_budget_used_rate'       => array(
				'operator' => 'max',
				'value'    => 0.80,
			),
			'ai_defer_exhausted'              => array(
				'operator' => 'max',
				'value'    => 0,
			),
			'action_scheduler_pending_growth' => array(
				'operator' => 'max',
				'value'    => 10,
			),
		);

		return array(
			'ticketmaster'      => array(
				'handler'    => 'ticketmaster',
				'stages'     => array( 1, 3, 5 ),
				'thresholds' => array_merge(
					$common,
					array(
						'source_rejection_rate' => array(
							'operator' => 'max',
							'value'    => 0.10,
						),
						'eligible_events'       => array(
							'operator' => 'min',
							'value'    => 5,
						),
						'duplicate_rate'        => array(
							'operator' => 'max',
							'value'    => 0.10,
						),
						'event_yield'           => array(
							'operator' => 'min',
							'value'    => 0.50,
						),
					)
				),
			),
			'dice'              => array(
				'handler'    => 'dice_fm',
				'stages'     => array( 1, 3, 10 ),
				'thresholds' => array_merge(
					$common,
					array(
						'source_rejection_rate' => array(
							'operator' => 'max',
							'value'    => 0.15,
						),
						'eligible_events'       => array(
							'operator' => 'min',
							'value'    => 5,
						),
						'duplicate_rate'        => array(
							'operator' => 'max',
							'value'    => 0.15,
						),
						'event_yield'           => array(
							'operator' => 'min',
							'value'    => 0.40,
						),
					)
				),
			),
			'universal_scraper' => array(
				'handler'    => 'universal_web_scraper',
				'stages'     => array( 1, 2 ),
				'thresholds' => array_merge(
					$common,
					array(
						'source_rejection_rate' => array(
							'operator' => 'max',
							'value'    => 0.25,
						),
						'eligible_events'       => array(
							'operator' => 'min',
							'value'    => 2,
						),
						'duplicate_rate'        => array(
							'operator' => 'max',
							'value'    => 0.20,
						),
						'event_yield'           => array(
							'operator' => 'min',
							'value'    => 0.25,
						),
					)
				),
			),
		);
	}

	/**
	 * Evaluate a preflight or postflight evidence document.
	 *
	 * @param array<string,mixed> $input Evaluation input.
	 * @param int|null            $now   Stable clock for tests; defaults to time().
	 * @return array<string,mixed>
	 */
	public function evaluate( array $input, ?int $now = null ): array {
		$now          = $now ?? time();
		$source_class = (string) ( $input['source_class'] ?? '' );
		$phase        = (string) ( $input['phase'] ?? 'preflight' );
		$current      = (int) ( $input['current_max_items'] ?? 0 );
		$scope        = (string) ( $input['scope'] ?? 'pipeline' );
		$scope_id     = (int) ( $input['scope_id'] ?? 0 );
		$evidence     = is_array( $input['evidence'] ?? null ) ? $input['evidence'] : array();
		$profiles     = self::profiles();

		$validation_errors = array();
		if ( ! isset( $profiles[ $source_class ] ) ) {
			$validation_errors[] = 'Unknown source_class.';
		}
		if ( ! in_array( $phase, array( 'preflight', 'postflight' ), true ) ) {
			$validation_errors[] = 'phase must be preflight or postflight.';
		}
		if ( ! in_array( $scope, array( 'pipeline', 'flow' ), true ) || $scope_id < 1 ) {
			$validation_errors[] = 'A reversible pipeline or flow scope with a positive scope_id is required.';
		}

		$profile     = $profiles[ $source_class ] ?? array(
			'handler'    => '',
			'stages'     => array(),
			'thresholds' => array(),
		);
		$stage_index = array_search( $current, $profile['stages'], true );
		if ( false === $stage_index ) {
			$validation_errors[] = 'current_max_items must match a declared source stage.';
		}

		$gates = $this->evaluate_evidence( $evidence, $profile['thresholds'], $now );
		if ( ! empty( $validation_errors ) ) {
			$gates[] = array(
				'name'      => 'input_validation',
				'status'    => 'fail',
				'observed'  => $validation_errors,
				'threshold' => 'valid evaluation input',
			);
		}

		$failed_names      = array_values(
			array_map(
				static fn( array $gate ): string => (string) $gate['name'],
				array_filter( $gates, static fn( array $gate ): bool => 'pass' !== $gate['status'] )
			)
		);
		$blocking_evidence = array_intersect( $failed_names, array( 'schema_version', 'observation_window', 'freshness', 'provenance', 'input_validation' ) );
		$metric_failures   = array_diff( $failed_names, $blocking_evidence );
		$last_stage        = false !== $stage_index && count( $profile['stages'] ) - 1 === $stage_index;
		$next_stage        = ( false !== $stage_index && ! $last_stage ) ? $profile['stages'][ $stage_index + 1 ] : null;

		if ( ! empty( $blocking_evidence ) ) {
			$decision = 'hold';
		} elseif ( ! empty( $metric_failures ) ) {
			$decision = 'postflight' === $phase ? 'rollback' : 'hold';
		} elseif ( $last_stage ) {
			$decision = 'complete';
		} else {
			$decision = 'advance';
		}

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'generated_at'   => gmdate( 'c', $now ),
			'phase'          => $phase,
			'source_class'   => $source_class,
			'profile'        => $profile,
			'scope'          => array(
				'type' => $scope,
				'id'   => $scope_id,
			),
			'current_stage'  => $current,
			'proposed_stage' => 'advance' === $decision ? $next_stage : null,
			'decision'       => $decision,
			'can_advance'    => 'advance' === $decision,
			'failed_gates'   => $failed_names,
			'gates'          => $gates,
			'evidence'       => $evidence,
			'apply_plan'     => $this->build_plan( $decision, $profile, $current, $next_stage, $scope, $scope_id, $stage_index ),
		);
	}

	/**
	 * Evaluate evidence metadata and policy metrics.
	 *
	 * @param array<string,mixed> $evidence   Submitted evidence document.
	 * @param array<string,mixed> $thresholds Source-class thresholds.
	 * @param int                 $now        Evaluation clock.
	 * @return array<int,array<string,mixed>>
	 */
	private function evaluate_evidence( array $evidence, array $thresholds, int $now ): array {
		$gates   = array();
		$gates[] = $this->exact_gate( 'schema_version', $evidence['schema_version'] ?? null, self::SCHEMA_VERSION );

		$start   = $this->timestamp( $evidence['window_start'] ?? null );
		$end     = $this->timestamp( $evidence['window_end'] ?? null );
		$hours   = ( null !== $start && null !== $end && $end >= $start ) ? ( $end - $start ) / HOUR_IN_SECONDS : null;
		$gates[] = $this->numeric_gate( 'observation_window', $hours, 'min', self::MINIMUM_WINDOW_HOURS );

		$observed_at = $this->timestamp( $evidence['observed_at'] ?? null );
		$age         = null === $observed_at ? null : $now - $observed_at;
		$gates[]     = $this->numeric_gate( 'freshness', $age, 'range', array( -300, self::MAXIMUM_EVIDENCE_AGE_SECONDS ) );

		$provenance         = is_array( $evidence['provenance'] ?? null ) ? $evidence['provenance'] : array();
		$missing_provenance = array_values( array_diff( self::REQUIRED_PROVENANCE, array_keys( array_filter( $provenance ) ) ) );
		$gates[]            = array(
			'name'      => 'provenance',
			'status'    => empty( $missing_provenance ) ? 'pass' : 'missing',
			'observed'  => empty( $missing_provenance ) ? array_keys( $provenance ) : $missing_provenance,
			'threshold' => self::REQUIRED_PROVENANCE,
		);

		$metrics = is_array( $evidence['metrics'] ?? null ) ? $evidence['metrics'] : array();
		foreach ( $thresholds as $name => $threshold ) {
			$gates[] = $this->numeric_gate( $name, $metrics[ $name ] ?? null, $threshold['operator'], $threshold['value'] );
		}

		return $gates;
	}

	/**
	 * Compare a scalar value for exact equality.
	 *
	 * @param string $name      Gate name.
	 * @param mixed  $observed  Observed value.
	 * @param mixed  $threshold Required value.
	 * @return array<string,mixed>
	 */
	private function exact_gate( string $name, $observed, $threshold ): array {
		return array(
			'name'      => $name,
			'status'    => null === $observed ? 'missing' : ( $observed === $threshold ? 'pass' : 'fail' ),
			'observed'  => $observed,
			'threshold' => $threshold,
		);
	}

	/**
	 * Compare a numeric observation against a threshold.
	 *
	 * @param string          $name      Gate name.
	 * @param mixed           $observed  Observed value.
	 * @param string          $operator  min, max, or range.
	 * @param int|float|int[] $threshold Threshold value.
	 * @return array<string,mixed>
	 */
	private function numeric_gate( string $name, $observed, string $operator, $threshold ): array {
		if ( ! is_int( $observed ) && ! is_float( $observed ) ) {
			$status = 'missing';
		} elseif ( 'min' === $operator ) {
			$status = $observed >= $threshold ? 'pass' : 'fail';
		} elseif ( 'range' === $operator ) {
			$status = $observed >= $threshold[0] && $observed <= $threshold[1] ? 'pass' : 'fail';
		} else {
			$status = $observed <= $threshold ? 'pass' : 'fail';
		}

		return array(
			'name'      => $name,
			'status'    => $status,
			'observed'  => $observed,
			'operator'  => $operator,
			'threshold' => $threshold,
		);
	}

	/**
	 * Parse an evidence timestamp.
	 *
	 * @param mixed $value Candidate timestamp.
	 * @return int|null
	 */
	private function timestamp( $value ): ?int {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$timestamp = strtotime( $value );
		return false === $timestamp ? null : $timestamp;
	}

	/**
	 * Build an operator-confirmed apply and reversal plan.
	 *
	 * @param string              $decision    Evaluated decision.
	 * @param array<string,mixed> $profile     Source profile.
	 * @param int                 $current     Current max_items stage.
	 * @param int|null            $next        Proposed max_items stage.
	 * @param string              $scope       Pipeline or flow scope.
	 * @param int                 $scope_id    Pipeline or flow ID.
	 * @param int|false           $stage_index Current profile index.
	 * @return array<string,mixed>
	 */
	private function build_plan( string $decision, array $profile, int $current, ?int $next, string $scope, int $scope_id, $stage_index ): array {
		$plan = array(
			'observational'              => true,
			'mutation_owner'             => 'datamachine/configure-flow-steps',
			'canonical_events_preserved' => true,
			'preview_available'          => false,
			'preview_blocker'            => 'Extra-Chill/data-machine#3019',
			'apply_command'              => null,
			'rollback_command'           => null,
		);

		if ( 'advance' === $decision && null !== $next ) {
			$plan['apply_command']    = $this->bulk_config_command( $profile['handler'], $next, $scope, $scope_id );
			$plan['rollback_command'] = $this->bulk_config_command( $profile['handler'], $current, $scope, $scope_id );
		} elseif ( 'rollback' === $decision ) {
			$previous = is_int( $stage_index ) && $stage_index > 0 ? $profile['stages'][ $stage_index - 1 ] : null;
			if ( null !== $previous ) {
				$plan['apply_command']    = $this->bulk_config_command( $profile['handler'], $previous, $scope, $scope_id );
				$plan['rollback_command'] = $this->bulk_config_command( $profile['handler'], $current, $scope, $scope_id );
			} else {
				$plan['apply_command']    = 'pipeline' === $scope
					? sprintf( 'wp datamachine flows pause --pipeline=%d', $scope_id )
					: sprintf( 'wp datamachine flows pause %d', $scope_id );
				$plan['rollback_command'] = 'pipeline' === $scope
					? sprintf( 'wp datamachine flows resume --pipeline=%d', $scope_id )
					: sprintf( 'wp datamachine flows resume %d', $scope_id );
			}
		}

		return $plan;
	}

	/**
	 * Render an executable command owned by Data Machine.
	 *
	 * @param string $handler   Handler slug.
	 * @param int    $max_items Target max_items value.
	 * @param string $scope     Pipeline or flow scope.
	 * @param int    $scope_id  Pipeline or flow ID.
	 * @return string
	 */
	private function bulk_config_command( string $handler, int $max_items, string $scope, int $scope_id ): string {
		$id_flag = 'pipeline' === $scope ? 'pipeline_id' : 'flow_id';
		return sprintf(
			'wp datamachine flows bulk-config --handler=%s --config=\'{"max_items":%d}\' --scope=%s --%s=%d --execute --format=json',
			$handler,
			$max_items,
			$scope,
			$id_flag,
			$scope_id
		);
	}
}
