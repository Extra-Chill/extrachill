<?php
/**
 * Bounded generic experiment summary report.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/experiment-reporting.php';

/** Register the private generic experiment summary Ability. */
function extrachill_analytics_register_experiment_summary_ability() {
	$identifier = array(
		'type'      => 'string',
		'pattern'   => '^[a-z0-9][a-z0-9_-]{0,63}$',
		'maxLength' => 64,
	);
	wp_register_ability(
		'extrachill/get-experiment-summary',
		array(
			'label'               => __( 'Get Experiment Summary', 'extrachill-analytics' ),
			'description'         => __( 'Report bounded assignment, exposure, outcome, lift, confidence, identity, privacy, and version coverage for one code-registered experiment.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'experiment_key', 'control_variant', 'variants' ),
				'additionalProperties' => false,
				'properties'           => array(
					'experiment_key'          => $identifier,
					'definition_version'      => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 1000000,
					),
					'control_variant'         => $identifier,
					'variants'                => array(
						'type'        => 'array',
						'minItems'    => 2,
						'maxItems'    => EC_ANALYTICS_EXPERIMENT_MAX_VARIANTS,
						'uniqueItems' => true,
						'items'       => $identifier,
					),
					'outcome_event_types'     => array(
						'type'        => 'array',
						'maxItems'    => EC_ANALYTICS_EXPERIMENT_MAX_OUTCOMES,
						'uniqueItems' => true,
						'items'       => $identifier,
					),
					'days'                    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 90,
						'default' => 28,
					),
					'attribution_window_days' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 90,
						'default' => 28,
					),
					'session_gap_mins'        => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 120,
						'default' => 30,
					),
					'max_events'              => array(
						'type'    => 'integer',
						'minimum' => 100,
						'maximum' => 100000,
						'default' => 50000,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Descriptive intent-to-treat and exposure-conditioned results. No winner is selected.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_experiment_summary',
			'permission_callback' => 'extrachill_analytics_can_read_reports',
			'meta'                => array(
				'show_in_rest' => false,
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
 * Validate and normalize generic report input.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Normalized options or an admission error.
 */
function extrachill_analytics_experiment_summary_options( $input ) {
	$allowed_keys = array( 'experiment_key', 'definition_version', 'control_variant', 'variants', 'outcome_event_types', 'days', 'attribution_window_days', 'session_gap_mins', 'max_events' );
	if ( ! is_array( $input ) || array_diff( array_keys( $input ), $allowed_keys ) ) {
		return new WP_Error( 'invalid_experiment_summary_input', __( 'Experiment summary input contains unsupported fields.', 'extrachill-analytics' ), array( 'status' => 400 ) );
	}

	$key      = $input['experiment_key'] ?? null;
	$control  = $input['control_variant'] ?? null;
	$variants = $input['variants'] ?? null;
	$outcomes = $input['outcome_event_types'] ?? array();
	$version  = $input['definition_version'] ?? null;
	if (
		! extrachill_analytics_experiment_identifier_is_valid( $key )
		|| ! extrachill_analytics_experiment_identifier_is_valid( $control )
		|| ! is_array( $variants )
		|| count( $variants ) < 2
		|| count( $variants ) > EC_ANALYTICS_EXPERIMENT_MAX_VARIANTS
		|| array_values( $variants ) !== $variants
		|| count( array_unique( $variants ) ) !== count( $variants )
		|| ! in_array( $control, $variants, true )
		|| ! is_array( $outcomes )
		|| count( $outcomes ) > EC_ANALYTICS_EXPERIMENT_MAX_OUTCOMES
		|| array_values( $outcomes ) !== $outcomes
		|| count( array_unique( $outcomes ) ) !== count( $outcomes )
		|| ( null !== $version && ( ! is_int( $version ) || $version <= 0 || $version > 1000000 ) )
	) {
		return new WP_Error( 'invalid_experiment_summary_input', __( 'Experiment key, version, control, variants, or outcomes are invalid.', 'extrachill-analytics' ), array( 'status' => 400 ) );
	}
	foreach ( $variants as $variant ) {
		if ( ! extrachill_analytics_experiment_identifier_is_valid( $variant ) ) {
			return new WP_Error( 'invalid_experiment_summary_input', __( 'Every variant must be a bounded canonical identifier.', 'extrachill-analytics' ), array( 'status' => 400 ) );
		}
	}
	$canonical_outcomes = extrachill_analytics_experiment_outcome_types();
	foreach ( $outcomes as $outcome ) {
		if ( ! is_string( $outcome ) || ! in_array( $outcome, $canonical_outcomes, true ) ) {
			return new WP_Error( 'invalid_experiment_summary_outcome', __( 'Every outcome must be a code-declared canonical analytics event.', 'extrachill-analytics' ), array( 'status' => 400 ) );
		}
	}

	return array(
		'experiment_key'          => $key,
		'definition_version'      => $version,
		'control_variant'         => $control,
		'variants'                => $variants,
		'outcome_event_types'     => $outcomes,
		'days'                    => min( 90, max( 1, (int) ( $input['days'] ?? 28 ) ) ),
		'attribution_window_days' => min( 90, max( 1, (int) ( $input['attribution_window_days'] ?? 28 ) ) ),
		'session_gap_mins'        => min( 120, max( 1, (int) ( $input['session_gap_mins'] ?? 30 ) ) ),
		'max_events'              => min( 100000, max( 100, (int) ( $input['max_events'] ?? 50000 ) ) ),
	);
}

/**
 * Execute one bounded generic experiment report.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Report or input error.
 */
function extrachill_analytics_ability_get_experiment_summary( $input ) {
	$options = extrachill_analytics_experiment_summary_options( $input );
	if ( is_wp_error( $options ) ) {
		return $options;
	}
	$options['as_of']              = gmdate( 'Y-m-d H:i:s' );
	$options['since']              = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $options['days'] . ' days' ) );
	$event_types                   = array_values(
		array_unique(
			array_merge(
				array( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT, EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE, EC_ANALYTICS_EVENT_PAGEVIEW ),
				$options['outcome_event_types']
			)
		)
	);
	$query                         = extrachill_analytics_experiment_read_events( $event_types, $options['since'], $options['as_of'], $options['max_events'] );
	$options['truncated']          = $query['truncated'];
	$options['instrumented_types'] = extrachill_analytics_experiment_instrumented_types( $event_types );

	return extrachill_analytics_build_experiment_summary( $query['rows'], $options );
}

/**
 * Return raw 95% Wilson score bounds for one binomial proportion.
 *
 * @param int $successes Success count.
 * @param int $total     Denominator.
 * @return array|null Lower and upper bounds, or null without a denominator.
 */
function extrachill_analytics_experiment_wilson_bounds( $successes, $total ) {
	if ( $total <= 0 || $successes < 0 || $successes > $total ) {
		return null;
	}
	$z      = 1.959963984540054;
	$p      = $successes / $total;
	$z2     = $z * $z;
	$center = ( $p + $z2 / ( 2 * $total ) ) / ( 1 + $z2 / $total );
	$margin = $z * sqrt( ( $p * ( 1 - $p ) + $z2 / ( 4 * $total ) ) / $total ) / ( 1 + $z2 / $total );

	return array(
		'lower' => max( 0, $center - $margin ),
		'upper' => min( 1, $center + $margin ),
	);
}

/**
 * Return a rounded 95% Wilson score interval for one binomial proportion.
 *
 * @param int $successes Success count.
 * @param int $total     Denominator.
 * @return array|null Lower and upper bounds, or null without a denominator.
 */
function extrachill_analytics_experiment_wilson_interval( $successes, $total ) {
	$bounds = extrachill_analytics_experiment_wilson_bounds( $successes, $total );
	if ( null === $bounds ) {
		return null;
	}

	return array(
		'lower' => round( $bounds['lower'], 4 ),
		'upper' => round( $bounds['upper'], 4 ),
	);
}

/**
 * Compare one variant proportion with control without winner declarations.
 *
 * @param int  $successes         Variant successes.
 * @param int  $total             Variant denominator.
 * @param int  $control_successes Control successes.
 * @param int  $control_total     Control denominator.
 * @param bool $is_control       Whether this is the reference row.
 * @return array Lift estimates, confidence intervals, and status.
 */
function extrachill_analytics_experiment_lift( $successes, $total, $control_successes, $control_total, $is_control = false ) {
	if ( $total <= 0 || $control_total <= 0 ) {
		return array(
			'absolute'       => null,
			'relative'       => null,
			'absolute_ci_95' => null,
			'relative_ci_95' => null,
			'status'         => 'zero_denominator',
		);
	}
	if ( $successes < 0 || $successes > $total || $control_successes < 0 || $control_successes > $control_total ) {
		return array(
			'absolute'       => null,
			'relative'       => null,
			'absolute_ci_95' => null,
			'relative_ci_95' => null,
			'status'         => 'insufficient_data',
		);
	}
	if ( $is_control ) {
		return array(
			'absolute'       => 0.0,
			'relative'       => 0.0,
			'absolute_ci_95' => null,
			'relative_ci_95' => null,
			'status'         => 'control_reference',
		);
	}

	$rate         = $successes / $total;
	$control_rate = $control_successes / $control_total;
	$variant_ci   = extrachill_analytics_experiment_wilson_bounds( $successes, $total );
	$control_ci   = extrachill_analytics_experiment_wilson_bounds( $control_successes, $control_total );
	$difference   = $rate - $control_rate;
	$absolute_ci  = array(
		'lower' => round( max( -1, $difference - sqrt( ( $rate - $variant_ci['lower'] ) ** 2 + ( $control_ci['upper'] - $control_rate ) ** 2 ) ), 4 ),
		'upper' => round( min( 1, $difference + sqrt( ( $variant_ci['upper'] - $rate ) ** 2 + ( $control_rate - $control_ci['lower'] ) ** 2 ) ), 4 ),
	);
	$relative     = $control_rate > 0 ? ( $rate - $control_rate ) / $control_rate : null;
	$relative_ci  = null;
	$status       = 'insufficient_data';
	if ( $successes > 0 && $successes < $total && $control_successes > 0 && $control_successes < $control_total ) {
		$log_rr      = log( $rate / $control_rate );
		$standard    = sqrt( ( 1 / $successes ) - ( 1 / $total ) + ( 1 / $control_successes ) - ( 1 / $control_total ) );
		$relative_ci = array(
			'lower' => round( exp( $log_rr - 1.959963984540054 * $standard ) - 1, 4 ),
			'upper' => round( exp( $log_rr + 1.959963984540054 * $standard ) - 1, 4 ),
		);
		$status      = 'measured';
	}

	return array(
		'absolute'       => round( $difference, 4 ),
		'relative'       => null === $relative ? null : round( $relative, 4 ),
		'absolute_ci_95' => $absolute_ci,
		'relative_ci_95' => $relative_ci,
		'status'         => $status,
	);
}

/**
 * Create one empty generic variant bucket.
 *
 * @param string[] $outcome_types Requested outcomes.
 * @return array Bucket.
 */
function extrachill_analytics_experiment_summary_bucket( $outcome_types ) {
	$outcomes = array();
	foreach ( $outcome_types as $type ) {
		$outcomes[ $type ] = array(
			'assignment' => array(),
			'exposure'   => array(),
		);
	}
	return array(
		'assignment_events' => 0,
		'assigned_people'   => 0,
		'exposure_events'   => 0,
		'exposed_people'    => 0,
		'outcomes'          => $outcomes,
	);
}

/**
 * Validate stored experiment metadata for the requested summary.
 *
 * @param array $event   Normalized event.
 * @param array $options Report options.
 * @return bool Whether the row matches the requested contract.
 */
function extrachill_analytics_experiment_summary_contract_matches( $event, $options ) {
	$data    = $event['event_data'];
	$version = $data['definition_version'] ?? null;
	return (string) ( $data['experiment_key'] ?? '' ) === (string) $options['experiment_key']
		&& is_int( $version )
		&& $version > 0
		&& $version <= 1000000
		&& ( null === $options['definition_version'] || $version === $options['definition_version'] )
		&& extrachill_analytics_experiment_identifier_is_valid( $data['assignment_policy'] ?? null )
		&& in_array( (string) ( $data['variant'] ?? '' ), $options['variants'], true )
		&& extrachill_analytics_experiment_identifier_is_valid( $data['surface'] ?? null );
}

/**
 * Classify one stored experiment row before identity observation.
 *
 * @param array $event   Normalized event.
 * @param array $options Report options.
 * @return string Contract status.
 */
function extrachill_analytics_experiment_summary_contract_status( $event, $options ) {
	if ( ! in_array( $event['event_type'], array( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT, EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE ), true ) ) {
		return 'not_experiment';
	}
	$data = $event['event_data'];
	if ( (string) ( $data['experiment_key'] ?? '' ) !== $options['experiment_key'] ) {
		return 'other_experiment';
	}
	$version = $data['definition_version'] ?? null;
	if ( ! is_int( $version ) || $version <= 0 || $version > 1000000 ) {
		return 'invalid_contract';
	}
	if ( null !== $options['definition_version'] && $version !== $options['definition_version'] ) {
		return 'other_definition_version';
	}
	return extrachill_analytics_experiment_summary_contract_matches( $event, $options ) ? 'matched' : 'invalid_contract';
}

/**
 * Aggregate stored rows into a bounded generic experiment summary.
 *
 * @param array $rows    Stored rows.
 * @param array $options Normalized report options.
 * @return array Machine-readable summary.
 */
function extrachill_analytics_build_experiment_summary( $rows, $options ) {
	$events = array_map( 'extrachill_analytics_experiment_normalize_event', $rows );
	usort(
		$events,
		static function ( $a, $b ) {
			return $a['ts'] === $b['ts'] ? $a['id'] <=> $b['id'] : $a['ts'] <=> $b['ts'];
		}
	);
	$identity_events = array();
	foreach ( $events as $event ) {
		if ( ! empty( $event['event_data']['is_bot'] ) ) {
			continue;
		}
		$status = extrachill_analytics_experiment_summary_contract_status( $event, $options );
		if ( in_array( $status, array( 'not_experiment', 'matched' ), true ) ) {
			$identity_events[] = $event;
		}
	}
	$identity        = extrachill_analytics_experiment_identity_map( $identity_events );
	$visitor_to_user = $identity['map'];
	$buckets         = array();
	foreach ( $options['variants'] as $variant ) {
		$buckets[ $variant ] = extrachill_analytics_experiment_summary_bucket( $options['outcome_event_types'] );
	}
	$assignments = array();
	$exposures   = array();
	$versions    = array();
	$surfaces    = array();
	$policies    = array();
	$cohort      = array();
	$coverage    = array(
		'loaded_rows'                   => count( $events ),
		'truncated'                     => ! empty( $options['truncated'] ),
		'bot_rows_excluded'             => 0,
		'unidentified_rows'             => array(),
		'other_experiment_rows'         => 0,
		'invalid_contract_rows'         => 0,
		'other_definition_version_rows' => 0,
		'duplicate_assignment_events'   => 0,
		'conflicting_assignment_events' => 0,
		'duplicate_exposure_events'     => 0,
		'unattributed_exposure_events'  => 0,
		'pre_assignment_outcome_events' => 0,
		'unattributed_outcome_events'   => 0,
		'ambiguous_visitor_ids'         => count( $identity['ambiguous'] ),
	);
	foreach ( $events as $event ) {
		if ( ! empty( $event['event_data']['is_bot'] ) || EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT !== $event['event_type'] || ! extrachill_analytics_experiment_summary_contract_matches( $event, $options ) ) {
			continue;
		}
		$person = extrachill_analytics_experiment_person_key( $event, $visitor_to_user );
		if ( '' !== $person ) {
			$cohort[ $person ] = true;
		}
	}

	foreach ( $events as $event ) {
		$type = $event['event_type'];
		if ( ! empty( $event['event_data']['is_bot'] ) ) {
			++$coverage['bot_rows_excluded'];
			continue;
		}

		if ( in_array( $type, array( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT, EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE ), true ) ) {
			$status = extrachill_analytics_experiment_summary_contract_status( $event, $options );
			if ( 'other_experiment' === $status ) {
				++$coverage['other_experiment_rows'];
				continue;
			}
			if ( 'invalid_contract' === $status ) {
				++$coverage['invalid_contract_rows'];
				continue;
			}
			$data                        = $event['event_data'];
			$stored_version              = $data['definition_version'] ?? null;
			$versions[ $stored_version ] = (int) ( $versions[ $stored_version ] ?? 0 ) + 1;
			if ( 'other_definition_version' === $status ) {
				++$coverage['other_definition_version_rows'];
				continue;
			}
			$person = extrachill_analytics_experiment_person_key( $event, $visitor_to_user );
			if ( '' === $person ) {
				$coverage['unidentified_rows'][ $type ] = (int) ( $coverage['unidentified_rows'][ $type ] ?? 0 ) + 1;
				continue;
			}
			$variant                               = (string) $data['variant'];
			$surfaces[ (string) $data['surface'] ] = true;
			$policies[ (string) $data['assignment_policy'] ] = true;
			if ( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT === $type ) {
				++$buckets[ $variant ]['assignment_events'];
				if ( isset( $assignments[ $person ] ) ) {
					++$coverage['duplicate_assignment_events'];
					if (
						$variant !== $assignments[ $person ]['variant']
						|| $stored_version !== $assignments[ $person ]['definition_version']
						|| (string) $data['assignment_policy'] !== $assignments[ $person ]['assignment_policy']
						|| (string) $data['surface'] !== $assignments[ $person ]['surface']
					) {
						++$coverage['conflicting_assignment_events'];
					}
					continue;
				}
				$assignments[ $person ] = array(
					'variant'            => $variant,
					'definition_version' => $stored_version,
					'assignment_policy'  => (string) $data['assignment_policy'],
					'surface'            => (string) $data['surface'],
					'ts'                 => $event['ts'],
					'id'                 => $event['id'],
					'session_last_ts'    => $event['ts'],
					'session_stage'      => 'same_session',
				);
				++$buckets[ $variant ]['assigned_people'];
				continue;
			}
			if (
				! isset( $assignments[ $person ] )
				|| $variant !== $assignments[ $person ]['variant']
				|| $stored_version !== $assignments[ $person ]['definition_version']
				|| (string) $data['assignment_policy'] !== $assignments[ $person ]['assignment_policy']
				|| (string) $data['surface'] !== $assignments[ $person ]['surface']
				|| ! extrachill_analytics_experiment_is_after( $event, $assignments[ $person ] )
			) {
				++$coverage['unattributed_exposure_events'];
				continue;
			}
			++$buckets[ $variant ]['exposure_events'];
			if ( isset( $exposures[ $person ] ) ) {
				++$coverage['duplicate_exposure_events'];
				continue;
			}
			$exposures[ $person ] = array(
				'variant'         => $variant,
				'ts'              => $event['ts'],
				'id'              => $event['id'],
				'session_last_ts' => $event['ts'],
				'session_stage'   => 'same_session',
			);
			++$buckets[ $variant ]['exposed_people'];
			continue;
		}
		$person = extrachill_analytics_experiment_person_key( $event, $visitor_to_user );
		if ( '' === $person ) {
			$coverage['unidentified_rows'][ $type ] = (int) ( $coverage['unidentified_rows'][ $type ] ?? 0 ) + 1;
			continue;
		}

		if ( EC_ANALYTICS_EVENT_PAGEVIEW === $type ) {
			foreach ( array( 'assignment', 'exposure' ) as $lens ) {
				$anchor = 'assignment' === $lens ? ( $assignments[ $person ] ?? null ) : ( $exposures[ $person ] ?? null );
				if ( null === $anchor || ! extrachill_analytics_experiment_is_after( $event, $anchor ) ) {
					continue;
				}
				if ( $event['ts'] - $anchor['session_last_ts'] > $options['session_gap_mins'] * MINUTE_IN_SECONDS ) {
					$anchor['session_stage'] = 'later_session';
				}
				$anchor['session_last_ts'] = $event['ts'];
				if ( 'assignment' === $lens ) {
					$assignments[ $person ] = $anchor;
				} else {
					$exposures[ $person ] = $anchor;
				}
			}
			continue;
		}

		if ( ! in_array( $type, $options['outcome_event_types'], true ) ) {
			continue;
		}
		if ( ! isset( $assignments[ $person ] ) || ! extrachill_analytics_experiment_is_after( $event, $assignments[ $person ] ) ) {
			if ( isset( $cohort[ $person ] ) ) {
				++$coverage['pre_assignment_outcome_events'];
			} else {
				++$coverage['unattributed_outcome_events'];
			}
			continue;
		}
		foreach ( array( 'assignment', 'exposure' ) as $lens ) {
			$anchor = 'assignment' === $lens ? ( $assignments[ $person ] ?? null ) : ( $exposures[ $person ] ?? null );
			if (
				null === $anchor
				|| ! extrachill_analytics_experiment_is_after( $event, $anchor )
				|| $event['ts'] - $anchor['ts'] > $options['attribution_window_days'] * DAY_IN_SECONDS
				|| isset( $buckets[ $anchor['variant'] ]['outcomes'][ $type ][ $lens ][ $person ] )
			) {
				continue;
			}
			$stage = $event['ts'] - $anchor['session_last_ts'] > $options['session_gap_mins'] * MINUTE_IN_SECONDS ? 'later_session' : $anchor['session_stage'];
			$buckets[ $anchor['variant'] ]['outcomes'][ $type ][ $lens ][ $person ] = $stage;
		}
	}

	ksort( $coverage['unidentified_rows'] );
	ksort( $versions );
	$instrumented     = array_values( array_unique( array_map( 'strval', $options['instrumented_types'] ?? array() ) ) );
	$assignment_ready = in_array( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT, $instrumented, true );
	$has_cohort       = ! empty( $assignments );
	$state            = $has_cohort ? ( $coverage['truncated'] ? 'truncated' : 'measured' ) : ( $assignment_ready ? 'no_data' : 'not_instrumented' );
	$control_bucket   = $buckets[ $options['control_variant'] ];
	$variant_rows     = array();
	foreach ( $options['variants'] as $variant ) {
		$bucket   = $buckets[ $variant ];
		$assigned = (int) $bucket['assigned_people'];
		$exposed  = (int) $bucket['exposed_people'];
		$outcomes = array();
		foreach ( $options['outcome_event_types'] as $type ) {
			$outcome_instrumented = in_array( $type, $instrumented, true );
			$ready                = $has_cohort && $outcome_instrumented;
			$outcome_coverage     = ! $outcome_instrumented ? 'not_instrumented' : ( $has_cohort ? ( $coverage['truncated'] ? 'truncated' : 'measured' ) : 'no_data' );
			$assignment_map       = $bucket['outcomes'][ $type ]['assignment'];
			$exposure_map         = $bucket['outcomes'][ $type ]['exposure'];
			$successes            = count( $assignment_map );
			$exposed_hits         = count( $exposure_map );
			$control_hits         = count( $control_bucket['outcomes'][ $type ]['assignment'] );
			$outcomes[ $type ]    = array(
				'coverage_status'  => $outcome_coverage,
				'after_assignment' => $ready ? array(
					'people'               => $successes,
					'rate'                 => $assigned > 0 ? round( $successes / $assigned, 4 ) : null,
					'rate_status'          => $assigned > 0 ? 'measured' : 'zero_denominator',
					'rate_ci_95'           => extrachill_analytics_experiment_wilson_interval( $successes, $assigned ),
					'same_session_people'  => count( array_filter( $assignment_map, static fn( $stage ) => 'same_session' === $stage ) ),
					'later_session_people' => count( array_filter( $assignment_map, static fn( $stage ) => 'later_session' === $stage ) ),
					'lift_vs_control'      => extrachill_analytics_experiment_lift( $successes, $assigned, $control_hits, $control_bucket['assigned_people'], $variant === $options['control_variant'] ),
				) : null,
				'after_exposure'   => $ready && in_array( EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE, $instrumented, true ) ? array(
					'people'               => $exposed_hits,
					'rate'                 => $exposed > 0 ? round( $exposed_hits / $exposed, 4 ) : null,
					'rate_status'          => $exposed > 0 ? 'measured' : 'zero_denominator',
					'rate_ci_95'           => extrachill_analytics_experiment_wilson_interval( $exposed_hits, $exposed ),
					'same_session_people'  => count( array_filter( $exposure_map, static fn( $stage ) => 'same_session' === $stage ) ),
					'later_session_people' => count( array_filter( $exposure_map, static fn( $stage ) => 'later_session' === $stage ) ),
					'analysis_lens'        => 'descriptive_exposure_conditioned',
				) : null,
			);
		}
		$variant_rows[] = array(
			'variant'    => $variant,
			'assignment' => array(
				'people'          => $assignment_ready && $has_cohort ? $assigned : null,
				'stored_events'   => $assignment_ready && $has_cohort ? (int) $bucket['assignment_events'] : null,
				'coverage_status' => $assignment_ready && $has_cohort ? ( $coverage['truncated'] ? 'truncated' : 'measured' ) : ( $assignment_ready ? 'no_data' : 'not_instrumented' ),
			),
			'exposure'   => array(
				'people'          => $assignment_ready && $has_cohort && in_array( EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE, $instrumented, true ) ? $exposed : null,
				'stored_events'   => $assignment_ready && $has_cohort && in_array( EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE, $instrumented, true ) ? (int) $bucket['exposure_events'] : null,
				'rate'            => $assigned > 0 && in_array( EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE, $instrumented, true ) ? round( $exposed / $assigned, 4 ) : null,
				'rate_status'     => ! in_array( EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE, $instrumented, true ) ? 'not_instrumented' : ( $assigned > 0 ? 'measured' : 'zero_denominator' ),
				'rate_ci_95'      => in_array( EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE, $instrumented, true ) ? extrachill_analytics_experiment_wilson_interval( $exposed, $assigned ) : null,
				'coverage_status' => ! in_array( EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE, $instrumented, true ) ? 'not_instrumented' : ( $has_cohort ? ( $coverage['truncated'] ? 'truncated' : 'measured' ) : 'no_data' ),
				'analysis_lens'   => 'descriptive',
			),
			'outcomes'   => $outcomes,
		);
	}

	return array(
		'experiment_key'      => $options['experiment_key'],
		'definition_version'  => $options['definition_version'],
		'control_variant'     => $options['control_variant'],
		'state'               => $state,
		'variants'            => $variant_rows,
		'version_diagnostics' => array(
			'observed_event_rows_by_version' => $versions,
			'mixed_versions_observed'        => count( $versions ) > 1,
			'requested_version'              => $options['definition_version'],
			'surfaces'                       => array_values( array_keys( $surfaces ) ),
			'assignment_policies'            => array_values( array_keys( $policies ) ),
		),
		'coverage'            => array_merge(
			$coverage,
			array(
				'identified_assignment_people' => count( $assignments ),
				'identified_exposure_people'   => count( $exposures ),
				'unambiguous_identity_bridges' => count( $visitor_to_user ),
				'gpc_dnt'                      => 'Privacy-excluded requests emit no assignment or exposure and may omit visitor identity from other rows; their number is intentionally not observable from stored analytics.',
				'unidentified'                 => 'Rows without a usable visitor_id or user_id are diagnostics only and are never attributed.',
				'no_data_semantics'            => 'Counts and rates are null when assignment or requested outcome instrumentation is absent; zero denominators never become zero rates.',
			)
		),
		'contract'            => array(
			'assignment' => 'The first valid assignment ordered by created_at then ID fixes a person variant and version. Duplicate/conflicting rows are diagnostics and never reassign.',
			'exposure'   => 'Exposure requires a matching variant/version event strictly after assignment and is never inferred. Exposure-conditioned outcomes are descriptive and may be selection-biased.',
			'outcomes'   => 'Each requested canonical outcome counts once per person and lens, strictly after its anchor and within the attribution window. Pre-assignment outcomes never attribute.',
			'identity'   => 'Bot rows are excluded before identity observation. Payload user_id, stored user_id, then visitor_id are used; visitors stitch only when exactly one user is observed.',
			'statistics' => 'Rates use assignment or observed-exposure people as denominators. Rate intervals are 95% Wilson score intervals; absolute lift uses the Newcombe-Wilson hybrid-score difference interval; relative lift uses a Katz log risk-ratio interval only when both arms have nonzero successes and failures. Nulls carry explicit status. No winner is selected.',
		),
		'window'              => array(
			'since'                   => (string) $options['since'],
			'as_of'                   => (string) $options['as_of'],
			'days'                    => (int) $options['days'],
			'attribution_window_days' => (int) $options['attribution_window_days'],
			'session_gap_mins'        => (int) $options['session_gap_mins'],
		),
		'query'               => array(
			'event_types'   => array_values( array_unique( array_merge( array( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT, EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE, EC_ANALYTICS_EVENT_PAGEVIEW ), $options['outcome_event_types'] ) ) ),
			'order'         => 'created_at ASC, id ASC',
			'cursor'        => '(created_at, id) > (cursor_created_at, cursor_id)',
			'page_size'     => 500,
			'max_events'    => (int) $options['max_events'],
			'bounded_state' => 'Variants are capped at 8, outcomes at 10, and retained rows at max_events plus one truncation sentinel; all identity and outcome maps derive from that bounded set.',
		),
	);
}
