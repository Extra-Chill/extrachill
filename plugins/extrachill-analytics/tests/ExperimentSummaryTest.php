<?php
/**
 * Tests for generic bounded experiment summaries.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/event-types.php';
require_once dirname( __DIR__ ) . '/inc/core/experiment-reporting.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/get-experiment-summary.php';

/** Protect generic experiment attribution, statistics, and bounds. */
final class ExperimentSummaryTest extends TestCase {
	/**
	 * Multiple keys, versions, surfaces, variants, identities, and lossy rows stay honest.
	 */
	public function test_generic_fixture_matrix(): void {
		$rows      = array(
			$this->row( 1, 'newsletter_signup', 90, 'visitor-control' ),
			$this->row( 2, 'experiment_assignment', 100, 'visitor-control', 0, $this->experiment( 'copy-test', 1, 'control', 'hero' ) ),
			$this->row( 3, 'experiment_assignment', 101, 'visitor-control', 0, $this->experiment( 'copy-test', 1, 'control', 'hero' ) ),
			$this->row( 4, 'experiment_exposure', 110, 'visitor-control', 0, $this->experiment( 'copy-test', 1, 'control', 'hero' ) ),
			$this->row( 5, 'newsletter_signup', 120, 'visitor-control', 10, array( 'user_id' => 10 ) ),
			$this->row( 6, 'newsletter_signup', 121, 'visitor-control', 10, array( 'user_id' => 10 ) ),
			$this->row( 7, 'experiment_assignment', 130, 'visitor-control-2', 0, $this->experiment( 'copy-test', 1, 'control', 'footer' ) ),
			$this->row( 8, 'experiment_assignment', 200, 'visitor-treatment', 0, $this->experiment( 'copy-test', 1, 'treatment', 'hero' ) ),
			$this->row( 9, 'experiment_exposure', 210, 'visitor-treatment', 0, $this->experiment( 'copy-test', 1, 'treatment', 'hero' ) ),
			$this->row( 10, 'newsletter_signup', 220, 'visitor-treatment' ),
			$this->row( 11, 'experiment_assignment', 230, 'visitor-treatment-2', 0, $this->experiment( 'copy-test', 1, 'treatment', 'footer' ) ),
			$this->row( 12, 'pageview', 4000, 'visitor-treatment-2' ),
			$this->row( 13, 'newsletter_signup', 4010, 'visitor-treatment-2' ),
			$this->row( 14, 'experiment_assignment', 240, 'visitor-other', 0, $this->experiment( 'other-test', 1, 'control', 'hero' ) ),
			$this->row( 15, 'experiment_assignment', 250, 'visitor-v2', 0, $this->experiment( 'copy-test', 2, 'control', 'hero' ) ),
			$this->row( 16, 'experiment_assignment', 260, 'visitor-bot', 0, array_merge( $this->experiment( 'copy-test', 1, 'control', 'hero' ), array( 'is_bot' => true ) ) ),
			$this->row( 17, 'experiment_assignment', 270, '', 0, $this->experiment( 'copy-test', 1, 'control', 'hero' ) ),
			$this->row( 18, 'experiment_assignment', 280, 'visitor-ambiguous', 0, $this->experiment( 'copy-test', 1, 'control', 'hero' ) ),
			$this->row( 19, 'newsletter_signup', 290, 'visitor-ambiguous', 70, array( 'user_id' => 70 ) ),
			$this->row( 20, 'newsletter_signup', 291, 'visitor-ambiguous', 71, array( 'user_id' => 71 ) ),
		);
		$report    = extrachill_analytics_build_experiment_summary( $rows, $this->options( 1 ) );
		$control   = $report['variants'][0];
		$treatment = $report['variants'][1];

		$this->assertSame( 'measured', $report['state'] );
		$this->assertSame( 3, $control['assignment']['people'] );
		$this->assertSame( 4, $control['assignment']['stored_events'] );
		$this->assertSame( 1, $control['exposure']['people'] );
		$this->assertSame( 0.3333, $control['exposure']['rate'] );
		$this->assertSame( 2, $treatment['assignment']['people'] );
		$this->assertSame( 1, $treatment['exposure']['people'] );
		$this->assertSame( 1, $control['outcomes']['newsletter_signup']['after_assignment']['people'] );
		$this->assertSame( 2, $treatment['outcomes']['newsletter_signup']['after_assignment']['people'] );
		$this->assertSame( 1, $treatment['outcomes']['newsletter_signup']['after_assignment']['later_session_people'] );
		$this->assertSame( 1.0, $treatment['outcomes']['newsletter_signup']['after_assignment']['rate'] );
		$this->assertSame( 0.6667, $treatment['outcomes']['newsletter_signup']['after_assignment']['lift_vs_control']['absolute'] );
		$this->assertSame( 2.0, $treatment['outcomes']['newsletter_signup']['after_assignment']['lift_vs_control']['relative'] );
		$this->assertNotNull( $treatment['outcomes']['newsletter_signup']['after_assignment']['rate_ci_95'] );
		$this->assertNull( $treatment['outcomes']['newsletter_signup']['after_assignment']['lift_vs_control']['relative_ci_95'] );
		$this->assertSame( 'insufficient_data', $treatment['outcomes']['newsletter_signup']['after_assignment']['lift_vs_control']['status'] );
		$this->assertSame( 1, $report['coverage']['duplicate_assignment_events'] );
		$this->assertSame( 1, $report['coverage']['pre_assignment_outcome_events'] );
		$this->assertSame( 1, $report['coverage']['bot_rows_excluded'] );
		$this->assertSame( 1, $report['coverage']['ambiguous_visitor_ids'] );
		$this->assertSame( 1, $report['coverage']['other_experiment_rows'] );
		$this->assertSame( 1, $report['coverage']['other_definition_version_rows'] );
		$this->assertTrue( $report['version_diagnostics']['mixed_versions_observed'] );
		$this->assertSame( array( 'hero', 'footer' ), $report['version_diagnostics']['surfaces'] );
		$this->assertStringContainsString( 'not observable', $report['coverage']['gpc_dnt'] );
	}

	/** Oversized stored versions cannot affect an unfiltered summary or its diagnostics. */
	public function test_unfiltered_oversized_version_is_fully_rejected(): void {
		$options                       = $this->options( null );
		$options['instrumented_types'] = array( 'experiment_assignment', 'experiment_exposure', 'newsletter_signup' );
		$rows                          = array(
			$this->row( 1, 'experiment_assignment', 100, 'visitor-control', 0, $this->experiment( 'copy-test', 1, 'control', 'hero' ) ),
			$this->row( 2, 'newsletter_signup', 110, 'visitor-control' ),
			$this->row( 3, 'experiment_assignment', 120, 'visitor-oversized', 0, $this->experiment( 'copy-test', 1000001, 'treatment', 'hero' ) ),
			$this->row( 4, 'experiment_exposure', 130, 'visitor-oversized', 0, $this->experiment( 'copy-test', 1000001, 'treatment', 'hero' ) ),
			$this->row( 5, 'newsletter_signup', 140, 'visitor-oversized' ),
		);
		$report                        = extrachill_analytics_build_experiment_summary( $rows, $options );
		$control                       = $report['variants'][0];
		$treatment                     = $report['variants'][1];

		$this->assertFalse( extrachill_analytics_experiment_summary_contract_matches( extrachill_analytics_experiment_normalize_event( $rows[2] ), $options ) );
		$this->assertSame( 1, $control['assignment']['people'] );
		$this->assertSame( 1, $control['outcomes']['newsletter_signup']['after_assignment']['people'] );
		$this->assertSame( 0, $treatment['assignment']['people'] );
		$this->assertSame( 0, $treatment['exposure']['people'] );
		$this->assertSame( 0, $treatment['outcomes']['newsletter_signup']['after_assignment']['people'] );
		$this->assertNull( $treatment['outcomes']['newsletter_signup']['after_assignment']['lift_vs_control']['absolute'] );
		$this->assertNull( $treatment['outcomes']['newsletter_signup']['after_assignment']['lift_vs_control']['relative'] );
		$this->assertSame( 'zero_denominator', $treatment['outcomes']['newsletter_signup']['after_assignment']['lift_vs_control']['status'] );
		$this->assertSame( 2, $report['coverage']['invalid_contract_rows'] );
		$this->assertSame( 1, $report['coverage']['unattributed_outcome_events'] );
		$this->assertSame( array( 1 => 1 ), $report['version_diagnostics']['observed_event_rows_by_version'] );
		$this->assertFalse( $report['version_diagnostics']['mixed_versions_observed'] );
		$this->assertArrayNotHasKey( 1000001, $report['version_diagnostics']['observed_event_rows_by_version'] );
		$this->assertSame( 1, $report['coverage']['identified_assignment_people'] );
		$this->assertSame( 0, $report['coverage']['identified_exposure_people'] );
	}

	/** Invalid experiment rows cannot contaminate otherwise valid identity bridges. */
	public function test_oversized_assignment_cannot_create_cross_variant_identity_edge(): void {
		$options   = $this->options( null );
		$rows      = array(
			$this->row( 1, 'experiment_assignment', 100, 'visitor-control', 0, $this->experiment( 'copy-test', 1, 'control', 'hero' ) ),
			$this->row( 2, 'newsletter_signup', 110, 'visitor-control', 10, array( 'user_id' => 10 ) ),
			$this->row( 3, 'experiment_assignment', 120, 'visitor-treatment', 20, $this->experiment( 'copy-test', 1, 'treatment', 'hero' ) ),
			$this->row( 4, 'newsletter_signup', 130, 'visitor-treatment', 20, array( 'user_id' => 20 ) ),
			$this->row( 5, 'experiment_assignment', 140, 'visitor-control', 20, $this->experiment( 'copy-test', 1000001, 'treatment', 'hero' ) ),
		);
		$report    = extrachill_analytics_build_experiment_summary( $rows, $options );
		$control   = $report['variants'][0];
		$treatment = $report['variants'][1];

		$this->assertSame( 1, $control['assignment']['people'] );
		$this->assertSame( 1, $treatment['assignment']['people'] );
		$this->assertSame( 1, $control['outcomes']['newsletter_signup']['after_assignment']['people'] );
		$this->assertSame( 1, $treatment['outcomes']['newsletter_signup']['after_assignment']['people'] );
		$this->assertSame( 0.0, $treatment['outcomes']['newsletter_signup']['after_assignment']['lift_vs_control']['absolute'] );
		$this->assertSame( 0.0, $treatment['outcomes']['newsletter_signup']['after_assignment']['lift_vs_control']['relative'] );
		$this->assertSame( 0, $report['coverage']['ambiguous_visitor_ids'] );
		$this->assertSame( 2, $report['coverage']['unambiguous_identity_bridges'] );
		$this->assertSame( 1, $report['coverage']['invalid_contract_rows'] );
		$this->assertSame( 0, $report['coverage']['duplicate_assignment_events'] );
		$this->assertSame( 0, $report['coverage']['conflicting_assignment_events'] );
		$this->assertSame( array( 1 => 2 ), $report['version_diagnostics']['observed_event_rows_by_version'] );
		$this->assertFalse( $report['version_diagnostics']['mixed_versions_observed'] );
	}

	/** Zero denominators and absent instrumentation return null with explicit status. */
	public function test_zero_denominator_and_no_instrumentation_states(): void {
		$report    = extrachill_analytics_build_experiment_summary(
			array( $this->row( 1, 'experiment_assignment', 100, 'visitor-control', 0, $this->experiment( 'copy-test', 1, 'control', 'hero' ) ) ),
			$this->options( 1 )
		);
		$treatment = $report['variants'][1];

		$this->assertNull( $treatment['exposure']['rate'] );
		$this->assertNull( $treatment['outcomes']['newsletter_signup']['after_assignment']['rate'] );
		$this->assertSame( 'zero_denominator', $treatment['outcomes']['newsletter_signup']['after_assignment']['lift_vs_control']['status'] );

		$options                       = $this->options( 1 );
		$options['instrumented_types'] = array();
		$empty                         = extrachill_analytics_build_experiment_summary( array(), $options );
		$this->assertSame( 'not_instrumented', $empty['state'] );
		$this->assertNull( $empty['variants'][0]['assignment']['people'] );
		$this->assertNull( $empty['variants'][0]['outcomes']['newsletter_signup']['after_assignment'] );
	}

	/** Truncation is retained next to observed values. */
	public function test_truncation_is_explicit(): void {
		$options              = $this->options( 1 );
		$options['truncated'] = true;
		$report               = extrachill_analytics_build_experiment_summary(
			array( $this->row( 1, 'experiment_assignment', 100, 'visitor-control', 0, $this->experiment( 'copy-test', 1, 'control', 'hero' ) ) ),
			$options
		);

		$this->assertSame( 'truncated', $report['state'] );
		$this->assertSame( 'truncated', $report['variants'][0]['assignment']['coverage_status'] );
	}

	/** Wilson intervals and normal lift math stay deterministic and never select a winner. */
	public function test_confidence_and_lift_math(): void {
		$this->assertSame(
			array(
				'lower' => 0.2366,
				'upper' => 0.7634,
			),
			extrachill_analytics_experiment_wilson_interval( 5, 10 )
		);
		$lift = extrachill_analytics_experiment_lift( 80, 100, 50, 100 );

		$this->assertSame( 0.3, $lift['absolute'] );
		$this->assertSame( 0.6, $lift['relative'] );
		$this->assertSame(
			array(
				'lower' => 0.1691,
				'upper' => 0.417,
			),
			$lift['absolute_ci_95']
		);
		$this->assertSame(
			array(
				'lower' => 0.2851,
				'upper' => 0.992,
			),
			$lift['relative_ci_95']
		);
		$this->assertSame( 'measured', $lift['status'] );
		$this->assertArrayNotHasKey( 'winner', $lift );
	}

	/**
	 * Newcombe absolute intervals remain valid while Katz fails closed at boundaries.
	 *
	 * @dataProvider lift_boundary_provider
	 *
	 * @param int   $successes         Variant successes.
	 * @param int   $total             Variant denominator.
	 * @param int   $control_successes Control successes.
	 * @param int   $control_total     Control denominator.
	 * @param array $expected          Exact expected lift output.
	 */
	public function test_lift_boundary_intervals( $successes, $total, $control_successes, $control_total, $expected ): void {
		$this->assertSame(
			$expected,
			extrachill_analytics_experiment_lift( $successes, $total, $control_successes, $control_total )
		);
	}

	/** Return saturated, zero-boundary, and small-sample lift fixtures. */
	public function lift_boundary_provider(): array {
		return array(
			'saturated 1/1 versus 1/1' => array(
				1,
				1,
				1,
				1,
				array(
					'absolute'       => 0.0,
					'relative'       => 0.0,
					'absolute_ci_95' => array(
						'lower' => -0.7935,
						'upper' => 0.7935,
					),
					'relative_ci_95' => null,
					'status'         => 'insufficient_data',
				),
			),
			'zero variant successes'   => array(
				0,
				10,
				2,
				10,
				array(
					'absolute'       => -0.2,
					'relative'       => -1.0,
					'absolute_ci_95' => array(
						'lower' => -0.5098,
						'upper' => 0.1124,
					),
					'relative_ci_95' => null,
					'status'         => 'insufficient_data',
				),
			),
			'zero control successes'   => array(
				2,
				10,
				0,
				10,
				array(
					'absolute'       => 0.2,
					'relative'       => null,
					'absolute_ci_95' => array(
						'lower' => -0.1124,
						'upper' => 0.5098,
					),
					'relative_ci_95' => null,
					'status'         => 'insufficient_data',
				),
			),
			'zero variant failures'    => array(
				10,
				10,
				8,
				10,
				array(
					'absolute'       => 0.2,
					'relative'       => 0.25,
					'absolute_ci_95' => array(
						'lower' => -0.1124,
						'upper' => 0.5098,
					),
					'relative_ci_95' => null,
					'status'         => 'insufficient_data',
				),
			),
			'zero control failures'    => array(
				8,
				10,
				10,
				10,
				array(
					'absolute'       => -0.2,
					'relative'       => -0.2,
					'absolute_ci_95' => array(
						'lower' => -0.5098,
						'upper' => 0.1124,
					),
					'relative_ci_95' => null,
					'status'         => 'insufficient_data',
				),
			),
			'small interior sample'    => array(
				2,
				5,
				1,
				5,
				array(
					'absolute'       => 0.2,
					'relative'       => 1.0,
					'absolute_ci_95' => array(
						'lower' => -0.3098,
						'upper' => 0.604,
					),
					'relative_ci_95' => array(
						'lower' => -0.744,
						'upper' => 14.6235,
					),
					'status'         => 'measured',
				),
			),
		);
	}

	/** Missing exposure instrumentation is distinct from a measured zero denominator. */
	public function test_absent_exposure_instrumentation_status(): void {
		$options                       = $this->options( 1 );
		$options['instrumented_types'] = array( 'experiment_assignment', 'newsletter_signup' );
		$report                        = extrachill_analytics_build_experiment_summary(
			array( $this->row( 1, 'experiment_assignment', 100, 'visitor-control', 0, $this->experiment( 'copy-test', 1, 'control', 'hero' ) ) ),
			$options
		);

		$this->assertNull( $report['variants'][0]['exposure']['rate'] );
		$this->assertNull( $report['variants'][0]['exposure']['rate_ci_95'] );
		$this->assertSame( 'not_instrumented', $report['variants'][0]['exposure']['rate_status'] );
		$this->assertSame( 'not_instrumented', $report['variants'][0]['exposure']['coverage_status'] );
	}

	/** Ability input is private, exact, bounded, and canonical-outcome-only. */
	public function test_ability_contract_and_input_validation(): void {
		extrachill_analytics_register_experiment_summary_ability();
		$ability = $GLOBALS['extrachill_analytics_registered_abilities']['extrachill/get-experiment-summary'];

		$this->assertFalse( $ability['meta']['show_in_rest'] );
		$this->assertFalse( $ability['input_schema']['additionalProperties'] );
		$this->assertSame( EC_ANALYTICS_EXPERIMENT_MAX_VARIANTS, $ability['input_schema']['properties']['variants']['maxItems'] );
		$this->assertSame( EC_ANALYTICS_EXPERIMENT_MAX_OUTCOMES, $ability['input_schema']['properties']['outcome_event_types']['maxItems'] );

		$invalid = extrachill_analytics_experiment_summary_options(
			array(
				'experiment_key'      => 'copy-test',
				'control_variant'     => 'control',
				'variants'            => array( 'control', 'treatment' ),
				'outcome_event_types' => array( 'free_form_event' ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $invalid );
		$this->assertSame( 'invalid_experiment_summary_outcome', $invalid->code );
	}

	/**
	 * Build canonical versioned metadata.
	 *
	 * @param string $key     Experiment key.
	 * @param int    $version Definition version.
	 * @param string $variant Variant.
	 * @param string $surface Surface.
	 * @return array Metadata.
	 */
	private function experiment( string $key, int $version, string $variant, string $surface ): array {
		return array(
			'experiment_key'     => $key,
			'definition_version' => $version,
			'assignment_policy'  => 'weighted_random',
			'variant'            => $variant,
			'surface'            => $surface,
		);
	}

	/**
	 * Build one stored fixture row.
	 *
	 * @param int    $id         Row ID.
	 * @param string $event_type Event type.
	 * @param int    $timestamp  UTC timestamp.
	 * @param string $visitor_id Visitor ID.
	 * @param int    $user_id    User ID.
	 * @param array  $event_data Event payload.
	 * @return object Stored row.
	 */
	private function row( int $id, string $event_type, int $timestamp, string $visitor_id = '', int $user_id = 0, array $event_data = array() ): object {
		return (object) array(
			'id'         => $id,
			'event_type' => $event_type,
			'event_data' => wp_json_encode( $event_data ),
			'source_url' => '',
			'blog_id'    => 1,
			'user_id'    => $user_id,
			'visitor_id' => $visitor_id,
			'created_at' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			'ts'         => $timestamp,
		);
	}

	/**
	 * Return deterministic report options.
	 *
	 * @param int|null $version Requested definition version.
	 * @return array Report options.
	 */
	private function options( ?int $version ): array {
		return array(
			'experiment_key'          => 'copy-test',
			'definition_version'      => $version,
			'control_variant'         => 'control',
			'variants'                => array( 'control', 'treatment' ),
			'outcome_event_types'     => array( 'newsletter_signup' ),
			'since'                   => '1970-01-01 00:00:00',
			'as_of'                   => '1970-01-02 00:00:00',
			'days'                    => 1,
			'attribution_window_days' => 1,
			'session_gap_mins'        => 30,
			'max_events'              => 50000,
			'truncated'               => false,
			'instrumented_types'      => array( 'experiment_assignment', 'experiment_exposure', 'pageview', 'newsletter_signup' ),
		);
	}
}
