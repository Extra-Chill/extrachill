<?php
/**
 * Tests for generic bounded experiment summaries.
 *
 * @package ExtraChill\Analytics
 */


require_once __DIR__ . '/class-extrachill-analytics-test-case.php';
require_once dirname( __DIR__ ) . '/inc/core/event-types.php';
require_once dirname( __DIR__ ) . '/inc/core/experiment-reporting.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/get-experiment-summary.php';

/** Protect generic experiment attribution, statistics, and bounds. */
final class ExperimentSummaryTest extends Extrachill_Analytics_TestCase {
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

	/** A budget exhausted before any assignment is truncated, never no_data. */
	public function test_truncation_before_any_assignment_is_not_no_data(): void {
		$options              = $this->options( 1 );
		$options['truncated'] = true;
		$report               = extrachill_analytics_build_experiment_summary(
			array( $this->row( 1, 'pageview', 50, 'visitor-early' ) ),
			$options
		);

		$this->assertSame( 'truncated', $report['state'] );
		$this->assertStringContainsString( 'before any matching assignment', $report['state_hint'] );
		$this->assertSame( 'truncated', $report['variants'][0]['assignment']['coverage_status'] );
		$this->assertSame( 'truncated', $report['variants'][0]['exposure']['coverage_status'] );
		$this->assertSame( 'truncated', $report['variants'][0]['outcomes']['newsletter_signup']['coverage_status'] );
		$this->assertNull( $report['variants'][0]['assignment']['people'] );

		$options['truncated'] = false;
		$empty                = extrachill_analytics_build_experiment_summary( array(), $options );
		$this->assertSame( 'no_data', $empty['state'] );
		$this->assertNull( $empty['state_hint'] );
	}

	/** Pre-experiment rows beyond max_events cannot hide a live experiment. */
	public function test_pre_experiment_rows_do_not_spend_the_budget(): void {
		$start = time() - HOUR_IN_SECONDS;
		for ( $i = 0; $i < 150; $i++ ) {
			$this->insert_event( 'pageview', $start + $i, 'visitor-pre-' . $i );
		}
		// Other keys and versions before the real start never move the window.
		$this->insert_event( 'experiment_assignment', $start + 151, 'visitor-other', $this->experiment( 'other-test', 1, 'control', 'hero' ) );
		$this->insert_event( 'experiment_assignment', $start + 152, 'visitor-v2', $this->experiment( 'copy-test', 2, 'control', 'hero' ) );
		for ( $i = 0; $i < 150; $i++ ) {
			$this->insert_event( 'pageview', $start + 200 + $i, 'visitor-pre-b-' . $i );
		}
		$first = $start + 400;
		$this->insert_event( 'experiment_assignment', $first, 'visitor-control', $this->experiment( 'copy-test', 1, 'control', 'hero' ) );
		$this->insert_event( 'experiment_assignment', $first + 1, 'visitor-treatment', $this->experiment( 'copy-test', 1, 'treatment', 'hero' ) );
		$this->insert_event( 'experiment_exposure', $first + 2, 'visitor-treatment', $this->experiment( 'copy-test', 1, 'treatment', 'hero' ) );
		$this->insert_event( 'newsletter_signup', $first + 3, 'visitor-treatment' );

		$input  = array(
			'experiment_key'      => 'copy-test',
			'definition_version'  => 1,
			'control_variant'     => 'control',
			'variants'            => array( 'control', 'treatment' ),
			'outcome_event_types' => array( 'newsletter_signup' ),
			'days'                => 1,
			'max_events'          => 100,
		);
		$report = extrachill_analytics_ability_get_experiment_summary( $input );

		$this->assertIsArray( $report );
		$this->assertSame( 'measured', $report['state'] );
		$this->assertFalse( $report['coverage']['truncated'] );
		$this->assertSame( 4, $report['coverage']['loaded_rows'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $first ), $report['window']['since'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $first ), $report['window']['first_assignment_at'] );
		$this->assertSame( 1, $report['variants'][0]['assignment']['people'] );
		$this->assertSame( 1, $report['variants'][1]['assignment']['people'] );
		$this->assertSame( 1, $report['variants'][1]['exposure']['people'] );
		$this->assertSame( 1, $report['variants'][1]['outcomes']['newsletter_signup']['after_assignment']['people'] );

		// Without the version filter, the earlier version-2 row is the first assignment.
		unset( $input['definition_version'] );
		$any = extrachill_analytics_ability_get_experiment_summary( $input );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $start + 152 ), $any['window']['first_assignment_at'] );
		$this->assertSame( 'truncated', $any['state'] );
	}

	/** The page streamer hands rows over in order, one page at a time, with an exact ceiling. */
	public function test_each_event_page_streams_in_order_and_truncates(): void {
		$start = time() - HOUR_IN_SECONDS;
		for ( $i = 0; $i < 7; $i++ ) {
			$this->insert_event( 'pageview', $start + $i, 'visitor-stream-' . $i );
		}
		$since = gmdate( 'Y-m-d H:i:s', $start );
		$as_of = gmdate( 'Y-m-d H:i:s' );
		$read  = function ( int $max ) use ( $since, $as_of ): array {
			$sizes    = array();
			$visitors = array();
			$result   = extrachill_analytics_experiment_each_event_page(
				array( 'pageview' ),
				$since,
				$as_of,
				$max,
				static function ( $page ) use ( &$sizes, &$visitors ) {
					$sizes[] = count( $page );
					foreach ( $page as $row ) {
						$visitors[] = $row->visitor_id;
					}
				},
				3
			);
			return array( $result, $sizes, $visitors );
		};

		list( $all, $sizes, $visitors ) = $read( 7 );
		$this->assertSame( array( 3, 3, 1 ), $sizes );
		$this->assertSame( 'visitor-stream-0', $visitors[0] );
		$this->assertSame( 'visitor-stream-6', $visitors[6] );
		$this->assertSame( 7, $all['rows_read'] );
		$this->assertFalse( $all['truncated'] );

		list( $capped, $sizes ) = $read( 5 );
		$this->assertSame( array( 3, 2 ), $sizes );
		$this->assertSame( 5, $capped['rows_read'] );
		$this->assertTrue( $capped['truncated'] );

		list( $boundary, $sizes ) = $read( 6 );
		$this->assertSame( array( 3, 3 ), $sizes );
		$this->assertTrue( $boundary['truncated'], 'The sentinel row past an exact page boundary still reports truncation.' );
	}

	/**
	 * The streamed ability report matches the in-memory build of the same rows.
	 *
	 * The identity bridge is observed AFTER the assignment, so this also proves
	 * pass one sees the whole window before pass two attributes anything.
	 */
	public function test_streamed_report_matches_in_memory_build(): void {
		$start = time() - HOUR_IN_SECONDS;
		$this->insert_event( 'experiment_assignment', $start, 'visitor-a', $this->experiment( 'copy-test', 1, 'treatment', 'hero' ) );
		$this->insert_event( 'experiment_exposure', $start + 1, 'visitor-a', $this->experiment( 'copy-test', 1, 'treatment', 'hero' ) );
		$this->insert_event( 'experiment_assignment', $start + 2, 'visitor-b', $this->experiment( 'copy-test', 1, 'control', 'hero' ) );
		$this->insert_event( 'experiment_assignment', $start + 3, 'visitor-b', $this->experiment( 'copy-test', 1, 'control', 'hero' ) );
		$this->insert_event( 'pageview', $start + 4, 'visitor-a' );
		// visitor-c logs in as user 77 later; their earlier assignment must stitch to user:77.
		$this->insert_event( 'experiment_assignment', $start + 5, 'visitor-c', $this->experiment( 'copy-test', 1, 'treatment', 'hero' ) );
		$this->insert_event( 'newsletter_signup', $start + 6, 'visitor-a' );
		$this->insert_event( 'newsletter_signup', $start + 7, 'visitor-c', array( 'user_id' => 77 ) );
		$this->insert_event( 'newsletter_signup', $start + 8, 'visitor-b', array( 'is_bot' => true ) );

		$input  = array(
			'experiment_key'      => 'copy-test',
			'definition_version'  => 1,
			'control_variant'     => 'control',
			'variants'            => array( 'control', 'treatment' ),
			'outcome_event_types' => array( 'newsletter_signup' ),
			'days'                => 1,
		);
		$report = extrachill_analytics_ability_get_experiment_summary( $input );

		$options = extrachill_analytics_experiment_summary_options( $input );
		$options = array_merge(
			$options,
			array(
				'since'              => $report['window']['since'],
				'as_of'              => $report['window']['as_of'],
				'truncated'          => false,
				'instrumented_types' => array( 'experiment_assignment', 'experiment_exposure', 'pageview', 'newsletter_signup' ),
			)
		);
		$rows     = extrachill_analytics_experiment_read_events( array( 'experiment_assignment', 'experiment_exposure', 'pageview', 'newsletter_signup' ), $options['since'], $options['as_of'], 1000 );
		$expected = extrachill_analytics_build_experiment_summary( $rows['rows'], $options );

		$this->assertSame( $expected['variants'], $report['variants'] );
		$this->assertSame( $expected['coverage'], $report['coverage'] );
		$this->assertSame( 9, $report['coverage']['loaded_rows'] );
		$this->assertSame( 1, $report['coverage']['duplicate_assignment_events'] );
		$this->assertSame( 1, $report['coverage']['unambiguous_identity_bridges'] );
		$this->assertSame( 2, $report['variants'][1]['assignment']['people'] );
		$this->assertSame( 2, $report['variants'][1]['outcomes']['newsletter_signup']['after_assignment']['people'], 'visitor-c stitched to user:77 attributes their signup.' );
		$this->assertSame( 0, $report['variants'][0]['outcomes']['newsletter_signup']['after_assignment']['people'] );
	}

	/** The default row budget covers long windows instead of the old 100k cap. */
	public function test_default_row_budget_covers_long_windows(): void {
		$options = extrachill_analytics_experiment_summary_options(
			array(
				'experiment_key'      => 'copy-test',
				'control_variant'     => 'control',
				'variants'            => array( 'control', 'treatment' ),
				'outcome_event_types' => array( 'newsletter_signup' ),
			)
		);
		$this->assertSame( 1000000, $options['max_events'] );
		$this->assertSame( 2000000, extrachill_analytics_experiment_summary_options( array_merge( array( 'max_events' => 9000000 ), array( 'experiment_key' => 'copy-test', 'control_variant' => 'control', 'variants' => array( 'control', 'treatment' ), 'outcome_event_types' => array( 'newsletter_signup' ) ) ) )['max_events'] );
	}

	/**
	 * Insert one stored analytics event.
	 *
	 * @param string $type       Event type.
	 * @param int    $timestamp  UTC timestamp.
	 * @param string $visitor_id Visitor ID.
	 * @param array  $data       Event payload.
	 */
	private function insert_event( string $type, int $timestamp, string $visitor_id, array $data = array() ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-owned fixture table.
			extrachill_analytics_events_table(),
			array(
				'event_type' => $type,
				'event_data' => wp_json_encode( $data ),
				'source_url' => '/',
				'blog_id'    => 1,
				'user_id'    => 0,
				'visitor_id' => $visitor_id,
				'created_at' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
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
		$ability = wp_get_ability( 'extrachill/get-experiment-summary' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$schema = $ability->get_input_schema();

		$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ) );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( EC_ANALYTICS_EXPERIMENT_MAX_VARIANTS, $schema['properties']['variants']['maxItems'] );
		$this->assertSame( EC_ANALYTICS_EXPERIMENT_MAX_OUTCOMES, $schema['properties']['outcome_event_types']['maxItems'] );

		$invalid = extrachill_analytics_experiment_summary_options(
			array(
				'experiment_key'      => 'copy-test',
				'control_variant'     => 'control',
				'variants'            => array( 'control', 'treatment' ),
				'outcome_event_types' => array( 'free_form_event' ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $invalid );
		$this->assertSame( 'invalid_experiment_summary_outcome', $invalid->get_error_code() );
	}

	/** #1363: cross-site traversal is derived from pageviews on a different site than the anchor. */
	public function test_cross_site_pageview_outcome(): void {
		$options                        = $this->options( 1 );
		$options['outcome_event_types'] = array( 'network_click', 'cross_site_pageview' );
		$assign                         = fn( string $variant ) => $this->experiment( 'copy-test', 1, $variant, 'hero' );
		$rows                           = array(
			// Control A: crosses to blog 2 in-session after assignment on blog 7.
			$this->row( 1, 'experiment_assignment', 100, 'c-a', 0, $assign( 'control' ), 7 ),
			$this->row( 2, 'pageview', 110, 'c-a', 0, array(), 7 ),
			$this->row( 3, 'pageview', 120, 'c-a', 0, array(), 2 ),
			$this->row( 4, 'pageview', 130, 'c-a', 0, array(), 1 ),
			// Control B: same-site pageviews only.
			$this->row( 5, 'experiment_assignment', 100, 'c-b', 0, $assign( 'control' ), 7 ),
			$this->row( 6, 'pageview', 140, 'c-b', 0, array(), 7 ),
			// Control C: crossed BEFORE assignment; never attributes.
			$this->row( 7, 'pageview', 90, 'c-c', 0, array(), 2 ),
			$this->row( 8, 'experiment_assignment', 100, 'c-c', 0, $assign( 'control' ), 7 ),
			// Treatment A: exposed on blog 1, crosses to blog 11 in a later session.
			$this->row( 9, 'experiment_assignment', 100, 't-a', 0, $assign( 'treatment' ), 1 ),
			$this->row( 10, 'experiment_exposure', 105, 't-a', 0, $assign( 'treatment' ), 1 ),
			$this->row( 11, 'pageview', 105 + 2 * HOUR_IN_SECONDS, 't-a', 0, array(), 11 ),
			// Treatment B: crosses outside the 1-day attribution window.
			$this->row( 12, 'experiment_assignment', 100, 't-b', 0, $assign( 'treatment' ), 1 ),
			$this->row( 13, 'pageview', 100 + 2 * DAY_IN_SECONDS, 't-b', 0, array(), 2 ),
			// Bot crossing is excluded.
			$this->row( 14, 'experiment_assignment', 100, 't-bot', 0, array_merge( $assign( 'treatment' ), array( 'is_bot' => true ) ), 1 ),
			$this->row( 15, 'pageview', 120, 't-bot', 0, array( 'is_bot' => true ), 2 ),
		);
		$report    = extrachill_analytics_build_experiment_summary( $rows, $options );
		$control   = $report['variants'][0]['outcomes']['cross_site_pageview'];
		$treatment = $report['variants'][1]['outcomes']['cross_site_pageview'];

		$this->assertSame( 'measured', $control['coverage_status'] );
		$this->assertSame( 1, $control['after_assignment']['people'] );
		$this->assertSame( 0.3333, $control['after_assignment']['rate'] );
		$this->assertSame( 1, $control['after_assignment']['same_session_people'] );
		$this->assertSame( 1, $treatment['after_assignment']['people'] );
		$this->assertSame( 1, $treatment['after_assignment']['later_session_people'] );
		$this->assertSame( 1, $treatment['after_exposure']['people'] );
		$this->assertSame( 0, $control['after_exposure']['people'] );
		$this->assertNotContains( 'cross_site_pageview', $report['query']['event_types'] );
		$this->assertArrayHasKey( 'cross_site', $report['contract'] );
		// Stored outcome without rows is honest, not conflated with the derived one.
		$this->assertSame( 'not_instrumented', $report['variants'][0]['outcomes']['network_click']['coverage_status'] );

		// Without pageview instrumentation the derived outcome is not_instrumented.
		$options['instrumented_types'] = array( 'experiment_assignment', 'experiment_exposure' );
		$bare                          = extrachill_analytics_build_experiment_summary( $rows, $options );
		$this->assertSame( 'not_instrumented', $bare['variants'][0]['outcomes']['cross_site_pageview']['coverage_status'] );
		$this->assertNull( $bare['variants'][0]['outcomes']['cross_site_pageview']['after_assignment'] );
	}

	/** #1363: the derived outcome is admitted by input validation; unknown names still are not. */
	public function test_cross_site_pageview_is_an_admitted_outcome(): void {
		$base    = array(
			'experiment_key'  => 'copy-test',
			'control_variant' => 'control',
			'variants'        => array( 'control', 'treatment' ),
		);
		$options = extrachill_analytics_experiment_summary_options( array_merge( $base, array( 'outcome_event_types' => array( 'cross_site_pageview', 'network_click' ) ) ) );
		$this->assertIsArray( $options );
		$this->assertSame( array( 'cross_site_pageview', 'network_click' ), $options['outcome_event_types'] );
		$this->assertWPError( extrachill_analytics_experiment_summary_options( array_merge( $base, array( 'outcome_event_types' => array( 'cross_site_made_up' ) ) ) ) );
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
	 * @param int    $blog_id    Network site ID.
	 * @return object Stored row.
	 */
	private function row( int $id, string $event_type, int $timestamp, string $visitor_id = '', int $user_id = 0, array $event_data = array(), int $blog_id = 1 ): object {
		return (object) array(
			'id'         => $id,
			'event_type' => $event_type,
			'event_data' => wp_json_encode( $event_data ),
			'source_url' => '',
			'blog_id'    => $blog_id,
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
