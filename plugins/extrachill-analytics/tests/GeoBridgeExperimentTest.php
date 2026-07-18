<?php
/**
 * Tests for the bounded geographic bridge experiment report.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/event-types.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/get-conversion-map.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/get-geo-bridge-experiment.php';

/**
 * Protect attribution, identity, coverage, and query bounds.
 */
final class GeoBridgeExperimentTest extends TestCase {
	/**
	 * The full fixture keeps denominators, lossy events, identity, and time distinct.
	 */
	public function test_control_and_treatment_fixture_matrix(): void {
		$rows      = array(
			$this->row( 1, 'newsletter_signup', 90, 'visitor-control' ),
			$this->row( 2, 'experiment_assignment', 100, 'visitor-control', 0, 1, $this->experiment( 'control' ) ),
			$this->row( 3, 'experiment_assignment', 100, 'visitor-control', 0, 1, $this->experiment( 'control' ) ),
			$this->row( 4, 'pageview', 200, 'visitor-control', 0, 7, array( 'route_family' => 'homepage' ) ),
			$this->row( 5, 'bridge_click', 300, 'visitor-control' ),
			$this->row( 6, 'bridge_click', 301, 'visitor-control' ),
			$this->row( 7, 'newsletter_signup', 400, 'visitor-control', 10 ),
			$this->row( 8, 'newsletter_signup', 401, 'visitor-control', 10 ),
			$this->row( 9, 'experiment_assignment', 500, 'visitor-mixed', 0, 1, $this->experiment( 'control' ) ),
			$this->row( 10, 'user_registration', 600, 'visitor-mixed', 20, 1, array( 'user_id' => 20 ) ),
			$this->row( 11, 'experiment_assignment', 1000, 'visitor-treatment', 0, 1, $this->experiment( 'treatment' ) ),
			$this->row( 12, 'experiment_exposure', 1100, 'visitor-treatment', 0, 1, $this->experiment( 'treatment' ) ),
			$this->row( 13, 'experiment_exposure', 1101, 'visitor-treatment', 0, 1, $this->experiment( 'treatment' ) ),
			$this->row( 14, 'bridge_click', 1200, 'visitor-treatment', 0, 1, array( 'is_bot' => true ) ),
			$this->row( 15, 'bridge_click', 1201, 'visitor-treatment' ),
			$this->row( 16, 'bridge_click', 1202, 'visitor-treatment' ),
			$this->row( 17, 'pageview', 1250, 'visitor-treatment', 0, 1, array( 'route_family' => 'singular' ) ),
			$this->row( 18, 'pageview', 1300, 'visitor-treatment', 0, 7, array( 'route_family' => 'archive' ) ),
			$this->row( 19, 'pageview', 4000, 'visitor-treatment', 0, 7, array( 'route_family' => 'archive' ) ),
			$this->row( 20, 'onboarding_completed', 5000, 'visitor-treatment', 30, 1, array( 'user_id' => 30 ) ),
			$this->row( 21, 'experiment_assignment', 100, 'visitor-later', 0, 1, $this->experiment( 'treatment' ) ),
			$this->row( 22, 'experiment_exposure', 110, 'visitor-later', 0, 1, $this->experiment( 'treatment' ) ),
			$this->row( 23, 'pageview', 2000, 'visitor-later', 0, 7, array( 'route_family' => 'homepage' ) ),
			$this->row( 24, 'artist_profile_first_publish', 2100, 'visitor-later' ),
			$this->row( 25, 'experiment_assignment', 700, 'visitor-ambiguous', 0, 1, $this->experiment( 'control' ) ),
			$this->row( 26, 'user_registration', 800, 'visitor-ambiguous', 40, 1, array( 'user_id' => 40 ) ),
			$this->row( 27, 'user_registration', 801, 'visitor-ambiguous', 41, 1, array( 'user_id' => 41 ) ),
		);
		$report    = extrachill_analytics_build_geo_bridge_experiment_report( $rows, $this->options() );
		$control   = $report['variants'][0];
		$treatment = $report['variants'][1];

		$this->assertSame( 'measured', $report['state'] );
		$this->assertSame( 3, $control['assignment']['people'] );
		$this->assertSame( 4, $control['assignment']['stored_events'] );
		$this->assertSame( 0, $control['exposure']['people'] );
		$this->assertSame( 0.0, $control['exposure']['rate'] );
		$this->assertSame( 2, $control['network_engagement']['any_bridge_click_after_assignment']['events'] );
		$this->assertSame( 1, $control['network_engagement']['any_bridge_click_after_assignment']['people'] );
		$this->assertSame( 'intent_to_treat', $control['network_engagement']['any_bridge_click_after_assignment']['analysis_lens'] );
		$this->assertSame( 2, $treatment['assignment']['people'] );
		$this->assertSame( 2, $treatment['exposure']['people'] );
		$this->assertSame( 1.0, $treatment['exposure']['rate'] );
		$this->assertSame( 2, $treatment['network_engagement']['any_bridge_click_after_exposure']['events'] );
		$this->assertSame( 1.0, $treatment['network_engagement']['any_bridge_click_after_exposure']['events_per_exposure'] );
		$this->assertSame( 'descriptive_exposure_conditioned', $treatment['network_engagement']['any_bridge_click_after_exposure']['analysis_lens'] );
		$this->assertFalse( $treatment['network_engagement']['card_specific_click_instrumented'] );
		$this->assertSame( 'homepage', $control['route_transitions']['after_assignment'][0]['route_family'] );
		$this->assertSame( 'same_session', $control['route_transitions']['after_assignment'][0]['session_stage'] );
		$this->assertSame( 1, $this->transition_people( $treatment, 'after_assignment', 'archive', 'same_session' ) );
		$this->assertSame( 1, $this->transition_people( $treatment, 'after_assignment', 'homepage', 'later_session' ) );
		$this->assertSame( 1, $control['outcomes']['newsletter_signup']['after_assignment']['same_session'] );
		$this->assertSame( 1, $control['outcomes']['user_registration']['after_assignment']['same_session'] );
		$this->assertSame( 1, $treatment['outcomes']['onboarding_completed']['after_assignment']['later_session'] );
		$this->assertSame( 1, $treatment['outcomes']['artist_profile_first_publish']['after_exposure']['later_session'] );
		$this->assertSame( 1, $report['coverage']['duplicate_assignment_events'] );
		$this->assertSame( 1, $report['coverage']['duplicate_exposure_events'] );
		$this->assertSame( 1, $report['coverage']['bot_rows_excluded'] );
		$this->assertSame( 1, $report['coverage']['ambiguous_visitor_ids'] );
		$this->assertGreaterThanOrEqual( 1, $report['coverage']['pre_assignment_outcome_events'] );
	}

	/**
	 * Instrumented rates remain undefined when their denominator is absent.
	 */
	public function test_zero_denominators_return_null_rates(): void {
		$report    = extrachill_analytics_build_geo_bridge_experiment_report(
			array( $this->row( 1, 'experiment_assignment', 100, 'visitor-control', 0, 1, $this->experiment( 'control' ) ) ),
			$this->options()
		);
		$treatment = $report['variants'][1];

		$this->assertNull( $treatment['exposure']['rate'] );
		$this->assertNull( $treatment['network_engagement']['any_bridge_click_after_assignment']['events_per_assignment'] );
		$this->assertNull( $treatment['network_engagement']['any_bridge_click_after_exposure']['events_per_exposure'] );
	}

	/**
	 * Equal timestamps use IDs and exposure never appears without assignment.
	 */
	public function test_ordering_and_unattributed_exposure_are_explicit(): void {
		$rows   = array(
			$this->row( 8, 'experiment_exposure', 100, 'visitor-a', 0, 1, $this->experiment( 'control' ) ),
			$this->row( 9, 'experiment_assignment', 100, 'visitor-a', 0, 1, $this->experiment( 'control' ) ),
			$this->row( 10, 'newsletter_signup', 100, 'visitor-a' ),
			$this->row( 11, 'bridge_click', 101, 'visitor-a' ),
			$this->row( 12, 'bridge_click', 102, 'visitor-a' ),
		);
		$report = extrachill_analytics_build_geo_bridge_experiment_report( $rows, $this->options() );

		$this->assertSame( 1, $report['coverage']['unattributed_exposure_events'] );
		$this->assertSame( 0, $report['variants'][0]['exposure']['people'] );
		$this->assertSame( 1, $report['variants'][0]['outcomes']['newsletter_signup']['after_assignment']['same_session'] );
		$this->assertSame( 2.0, $report['variants'][0]['network_engagement']['any_bridge_click_after_assignment']['events_per_assignment'] );
	}

	/**
	 * Bot-stamped identity links cannot create visitor/user ambiguity.
	 */
	public function test_bot_identity_rows_are_excluded_before_stitching(): void {
		$rows   = array(
			$this->row( 1, 'experiment_assignment', 100, 'visitor-bot-link', 0, 1, $this->experiment( 'control' ) ),
			$this->row(
				2,
				'user_registration',
				110,
				'visitor-bot-link',
				91,
				1,
				array(
					'user_id' => 91,
					'is_bot'  => true,
				)
			),
			$this->row( 3, 'user_registration', 120, 'visitor-bot-link', 92, 1, array( 'user_id' => 92 ) ),
		);
		$report = extrachill_analytics_build_geo_bridge_experiment_report( $rows, $this->options() );

		$this->assertSame( 0, $report['coverage']['ambiguous_visitor_ids'] );
		$this->assertSame( 1, $report['coverage']['unambiguous_identity_bridges'] );
		$this->assertSame( 1, $report['coverage']['bot_rows_excluded'] );
		$this->assertSame( 1, $report['variants'][0]['outcomes']['user_registration']['after_assignment']['same_session'] );
	}

	/**
	 * Generic artist-card clicks remain broad intent-to-treat outcomes.
	 */
	public function test_unrelated_artist_click_is_never_labeled_geographic_card_attribution(): void {
		$rows       = array(
			$this->row( 1, 'experiment_assignment', 100, 'visitor-artist', 0, 1, $this->experiment( 'treatment' ) ),
			$this->row( 2, 'experiment_exposure', 110, 'visitor-artist', 0, 1, $this->experiment( 'treatment' ) ),
			$this->row(
				3,
				'bridge_click',
				120,
				'visitor-artist',
				0,
				1,
				array(
					'dest_site' => 'artist',
					'term'      => 'Unrelated Artist',
				)
			),
		);
		$report     = extrachill_analytics_build_geo_bridge_experiment_report( $rows, $this->options() );
		$treatment  = $report['variants'][1];
		$engagement = $treatment['network_engagement'];

		$this->assertSame( 1, $engagement['any_bridge_click_after_assignment']['events'] );
		$this->assertSame( 1, $engagement['any_bridge_click_after_exposure']['events'] );
		$this->assertFalse( $engagement['card_specific_click_instrumented'] );
		$this->assertStringContainsString( 'unrelated artist or festival cards', $engagement['caveat'] );
		$this->assertArrayNotHasKey( 'bridge_clicks', $treatment );
	}

	/**
	 * Missing production events return null metrics and an explicit state.
	 */
	public function test_absent_instrumentation_is_not_measured_zero(): void {
		$options                       = $this->options();
		$options['instrumented_types'] = array( 'pageview', 'bridge_click' );
		$report                        = extrachill_analytics_build_geo_bridge_experiment_report( array(), $options );

		$this->assertSame( 'not_instrumented', $report['state'] );
		$this->assertNull( $report['variants'][0]['assignment']['people'] );
		$this->assertNull( $report['variants'][0]['exposure']['rate'] );
		$this->assertNull( $report['variants'][0]['outcomes']['onboarding_completed']['after_assignment'] );
		$this->assertStringContainsString( 'not observable', $report['coverage']['gpc_dnt'] );
	}

	/**
	 * A bounded result retains observed values while disclosing truncation.
	 */
	public function test_truncation_is_propagated_to_machine_output(): void {
		$options              = $this->options();
		$options['truncated'] = true;
		$report               = extrachill_analytics_build_geo_bridge_experiment_report(
			array( $this->row( 1, 'experiment_assignment', 100, 'visitor-a', 0, 1, $this->experiment( 'control' ) ) ),
			$options
		);

		$this->assertSame( 'truncated', $report['state'] );
		$this->assertTrue( $report['coverage']['truncated'] );
		$this->assertSame( 'truncated', $report['variants'][0]['assignment']['coverage_status'] );
	}

	/**
	 * The SQL reader advances an equal-time keyset by ID and keeps one sentinel.
	 */
	public function test_reader_uses_stable_keyset_and_hard_bound(): void {
		$first = array();
		for ( $id = 1; $id <= 500; ++$id ) {
			$row             = $this->row( $id, 'pageview', 100, 'visitor-' . $id );
			$row->created_at = '2026-07-01 00:00:00';
			$first[]         = $row;
		}
		$sentinel             = $this->row( 501, 'pageview', 100, 'visitor-501' );
		$sentinel->created_at = '2026-07-01 00:00:00';
		$db                   = $this->install_database( array( $first, array( $sentinel ) ) );

		$result = extrachill_analytics_geo_bridge_read_events(
			extrachill_analytics_geo_bridge_event_types(),
			'2026-07-01 00:00:00',
			'2026-07-02 00:00:00',
			500
		);

		$this->assertTrue( $result['truncated'] );
		$this->assertCount( 500, $result['rows'] );
		$this->assertCount( 2, $db->prepared_queries );
		$this->assertStringContainsString( 'ORDER BY created_at ASC, id ASC', $db->prepared_queries[0]['query'] );
		$this->assertStringContainsString( 'id > %d', $db->prepared_queries[1]['query'] );
		$this->assertSame( 500, $db->prepared_queries[1]['args'][ count( $db->prepared_queries[1]['args'] ) - 2 ] );
	}

	/**
	 * Ability registration remains private, read-only, and fixed to one report.
	 */
	public function test_ability_contract_is_private_and_bounded(): void {
		extrachill_analytics_register_geo_bridge_experiment_ability();
		$ability = $GLOBALS['extrachill_analytics_registered_abilities']['extrachill/get-geo-bridge-experiment'];

		$this->assertFalse( $ability['meta']['show_in_rest'] );
		$this->assertTrue( $ability['meta']['annotations']['readonly'] );
		$this->assertArrayHasKey( 'max_events', $ability['input_schema']['properties'] );
		$this->assertArrayNotHasKey( 'experiment_key', $ability['input_schema']['properties'] );
	}

	/**
	 * Build canonical experiment metadata.
	 *
	 * @param string $variant Variant.
	 * @return array Metadata.
	 */
	private function experiment( string $variant ): array {
		return array(
			'experiment_key' => 'geo-bridge-holdout',
			'variant'        => $variant,
			'surface'        => 'single-post-bridge',
		);
	}

	/**
	 * Build a stored event fixture.
	 *
	 * @param int    $id         ID.
	 * @param string $event_type Event name.
	 * @param int    $timestamp  UTC timestamp.
	 * @param string $visitor_id Visitor identity.
	 * @param int    $user_id    User identity.
	 * @param int    $blog_id    Blog ID.
	 * @param array  $event_data Payload.
	 * @return object Stored row.
	 */
	private function row( int $id, string $event_type, int $timestamp, string $visitor_id = '', int $user_id = 0, int $blog_id = 1, array $event_data = array() ): object {
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
	 * Return report fixture options.
	 *
	 * @return array Options.
	 */
	private function options(): array {
		return array(
			'since'              => '1970-01-01 00:00:00',
			'as_of'              => '1970-01-02 00:00:00',
			'days'               => 1,
			'session_gap_mins'   => 30,
			'max_events'         => 50000,
			'truncated'          => false,
			'instrumented_types' => extrachill_analytics_geo_bridge_event_types(),
		);
	}

	/**
	 * Find one transition fixture count.
	 *
	 * @param array  $variant Variant output.
	 * @param string $lens    Transition lens.
	 * @param string $route   Route family.
	 * @param string $stage   Session stage.
	 * @return int People count.
	 */
	private function transition_people( array $variant, string $lens, string $route, string $stage ): int {
		foreach ( $variant['route_transitions'][ $lens ] as $row ) {
			if ( $route === $row['route_family'] && $stage === $row['session_stage'] ) {
				return (int) $row['people'];
			}
		}
		return 0;
	}

	/**
	 * Install ordered database pages.
	 *
	 * @param array $pages Result pages.
	 * @return object Database fixture.
	 */
	private function install_database( array $pages ): object {
		$db              = new class( $pages ) {
			/**
			 * Captured prepared queries.
			 *
			 * @var array
			 */
			public $prepared_queries = array();

			/**
			 * Result pages.
			 *
			 * @var array
			 */
			private $pages;

			/**
			 * Current page.
			 *
			 * @var int
			 */
			private $index = 0;

			/**
			 * Set pages.
			 *
			 * @param array $pages Result pages.
			 */
			public function __construct( $pages ) {
				$this->pages = $pages;
			}

			/**
			 * Capture prepared SQL.
			 *
			 * @param string $query SQL.
			 * @param mixed  ...$args Values.
			 * @return string SQL.
			 */
			public function prepare( $query, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$this->prepared_queries[] = array(
					'query' => $query,
					'args'  => $args,
				);
				return $query;
			}

			/**
			 * Return next page.
			 *
			 * @param string $query SQL.
			 * @return array Rows.
			 */
			public function get_results( $query ) {
				unset( $query );
				return $this->pages[ $this->index++ ] ?? array();
			}
		};
		$GLOBALS['wpdb'] = $db;
		return $db;
	}
}
