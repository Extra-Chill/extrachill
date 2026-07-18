<?php
/**
 * Regression tests for the outbound-click report (issue #135).
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/outbound-classifier.php';
require_once dirname( __DIR__ ) . '/inc/core/route-classifier.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/get-outbound-clicks.php';

/**
 * Verify canonical outbound event rows remain visible in the report.
 */
final class GetOutboundClicksTest extends TestCase {

	/**
	 * Clear fixture state after each test.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['extrachill_analytics_outbound_fixture_rows'] );
	}

	/**
	 * Human-stamped browser beacon rows produce destination rows.
	 */
	public function test_recorded_outbound_events_produce_destination_rows(): void {
		$GLOBALS['extrachill_analytics_outbound_fixture_rows'] = array(
			(object) array(
				'event_data' => array(
					'dest_host' => 'open.spotify.com',
					'category'  => 'spotify',
					'is_bot'    => false,
				),
				'source_url' => 'https://extrachill.com/artist-one/',
			),
			(object) array(
				'event_data' => array(
					'dest_host' => 'open.spotify.com',
					'category'  => 'spotify',
					'is_bot'    => false,
				),
				'source_url' => 'https://events.extrachill.com/events/show-one/',
			),
		);

		$report = extrachill_analytics_ability_get_outbound_clicks(
			array(
				'days'         => 0,
				'include_bots' => false,
			)
		);

		$this->assertSame( 2, $report['total'] );
		$this->assertSame(
			array(
				array(
					'dest_host' => 'open.spotify.com',
					'category'  => 'spotify',
					'clicks'    => 2,
				),
			),
			$report['by_destination']
		);
		$this->assertNull( $report['diagnostic'] );
	}

	/**
	 * Rows without destination dimensions must not be indistinguishable from an
	 * empty capture window.
	 */
	public function test_missing_destination_dimension_returns_diagnostic(): void {
		$GLOBALS['extrachill_analytics_outbound_fixture_rows'] = array(
			(object) array(
				'event_data' => array(
					'category' => 'other',
					'is_bot'   => false,
				),
				'source_url' => 'https://extrachill.com/legacy-page/',
			),
		);

		$report = extrachill_analytics_ability_get_outbound_clicks( array( 'days' => 0 ) );

		$this->assertSame( 1, $report['total'] );
		$this->assertSame( array(), $report['by_destination'] );
		$this->assertSame( 'missing_destination_dimensions', $report['diagnostic']['code'] );
		$this->assertSame( 1, $report['diagnostic']['rows_missing_dest_host'] );
		$this->assertStringContainsString( 'dest_host dimension', $report['diagnostic']['message'] );
	}

	/**
	 * Bot-stamped rows stay out of the default human report.
	 */
	public function test_synthetic_rows_are_excluded_by_default(): void {
		$GLOBALS['extrachill_analytics_outbound_fixture_rows'] = array(
			(object) array(
				'event_data' => array(
					'dest_host' => 'tickets.example',
					'category'  => 'ticketing',
					'is_bot'    => true,
				),
				'source_url' => 'https://extrachill.com/testing/',
			),
		);

		$report = extrachill_analytics_ability_get_outbound_clicks( array( 'days' => 0 ) );

		$this->assertSame( 0, $report['total'] );
		$this->assertSame( array(), $report['by_source'] );
	}

	/**
	 * Historical source values are minimized before report aggregation.
	 */
	public function test_report_never_returns_source_query_or_fragment(): void {
		$GLOBALS['extrachill_analytics_outbound_fixture_rows'] = array(
			(object) array(
				'event_data' => array(
					'dest_host' => 'tickets.example',
					'category'  => 'ticketing',
					'is_bot'    => false,
				),
				'source_url' => 'https://extrachill.com/contact/?access_token=fixture#response',
			),
		);

		$report = extrachill_analytics_ability_get_outbound_clicks( array( 'days' => 0 ) );

		$this->assertSame( 'https://extrachill.com/contact/', $report['by_source'][0]['source_url'] );
	}
}
