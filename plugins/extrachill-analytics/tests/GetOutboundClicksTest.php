<?php
/**
 * Regression tests for the outbound-click report (issue #135).
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Verify canonical outbound event rows remain visible in the report.
 */
final class GetOutboundClicksTest extends Extrachill_Analytics_TestCase {

	/**
	 * Insert one outbound_click event row into the real events table.
	 *
	 * @param array $event_data Event dimensions.
	 * @param strine $source_url Source URL.
	 * @return int Row ID.
	 */
	private function outbound_event( $event_data, $source_url ) {
		global $wpdb;
		$wpdb->insert(
			extrachill_analytics_events_table(),
			array(
				'event_type' => EC_ANALYTICS_EVENT_OUTBOUND_CLICK,
				'event_data' => wp_json_encode( $event_data ),
				'source_url' => $source_url,
				'blog_id'    => 1,
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Human-stamped browser beacon rows produce destination rows.
	 */
	public function test_recorded_outbound_events_produce_destination_rows(): void {
		$this->outbound_event(
			array(
				'dest_host' => 'open.spotify.com',
				'category'  => 'spotify',
				'is_bot'    => false,
			),
			'https://extrachill.com/artist-one/'
		);
		$this->outbound_event(
			array(
				'dest_host' => 'open.spotify.com',
				'category'  => 'spotify',
				'is_bot'    => false,
			),
			'https://events.extrachill.com/events/show-one/'
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
		$this->outbound_event(
			array(
				'category' => 'other',
				'is_bot'   => false,
			),
			'https://extrachill.com/legacy-page/'
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
		$this->outbound_event(
			array(
				'dest_host' => 'tickets.example',
				'category'  => 'ticketing',
				'is_bot'    => true,
			),
			'https://extrachill.com/testing/'
		);

		$report = extrachill_analytics_ability_get_outbound_clicks( array( 'days' => 0 ) );

		$this->assertSame( 0, $report['total'] );
		$this->assertSame( array(), $report['by_source'] );
	}

	/**
	 * Historical source values are minimized before report aggregation.
	 */
	public function test_report_never_returns_source_query_or_fragment(): void {
		$this->outbound_event(
			array(
				'dest_host' => 'tickets.example',
				'category'  => 'ticketing',
				'is_bot'    => false,
			),
			'https://extrachill.com/contact/?access_token=fixture#response'
		);

		$report = extrachill_analytics_ability_get_outbound_clicks( array( 'days' => 0 ) );

		$this->assertSame( 'https://extrachill.com/contact/', $report['by_source'][0]['source_url'] );
	}
}
