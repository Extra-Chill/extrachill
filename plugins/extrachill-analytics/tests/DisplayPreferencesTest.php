<?php
/**
 * Display preferences write admission and reporting behavior.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/** Exercise real ability execution and stored pageview rows. */
final class DisplayPreferencesTest extends Extrachill_Analytics_TestCase {
	/** Set a first-party browser request. */
	public function set_up(): void {
		parent::set_up();
		$this->set_ext_object_cache( true );
		$this->set_request( 'example.org', 'GET' );
		$_SERVER['HTTP_ORIGIN']     = 'http://localhost';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		$_SERVER['REMOTE_ADDR']     = '203.0.113.20';
	}

	/** Valid booleans (including false) and enums survive to JSON. */
	public function test_valid_preferences_persist(): void {
		foreach ( array(
			array(
				'color_scheme'   => 'dark',
				'reduced_motion' => true,
				'contrast'       => 'more',
			),
			array(
				'color_scheme'   => 'light',
				'reduced_motion' => false,
				'contrast'       => 'less',
			),
			array( 'contrast' => 'no-preference' ),
		) as $preferences ) {
			$data = $this->record( $preferences );
			foreach ( $preferences as $key => $value ) {
				$this->assertSame( $value, $data[ $key ] );
			}
		}
	}

	/** Invalid values never reject a pageview or get coerced into storage. */
	public function test_invalid_and_missing_preferences_are_dropped(): void {
		foreach ( array( null, 0, 1, 'false', 'true', array(), 'DARK', 'invalid' ) as $invalid ) {
			$data = $this->record(
				array(
					'color_scheme'   => $invalid,
					'reduced_motion' => $invalid,
					'contrast'       => $invalid,
				)
			);
			$this->assertArrayNotHasKey( 'color_scheme', $data );
			$this->assertArrayNotHasKey( 'reduced_motion', $data );
			$this->assertArrayNotHasKey( 'contrast', $data );
		}
		$this->assertArrayNotHasKey( 'color_scheme', $this->record( array() ) );
	}

	/** REST normalization must precede Core's boolean coercion. */
	public function test_rest_drops_invalid_preferences_before_schema_coercion(): void {
		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/extrachill/track-page-view/run' );
		$request->set_param(
			'input',
			array(
				'reduced_motion' => 'false',
				'color_scheme'   => 'dark',
				'contrast'       => 1,
			)
		);
		extrachill_analytics_normalize_pageview_rest_input( null, rest_get_server(), $request );
		$this->assertSame( array( 'color_scheme' => 'dark' ), $request->get_param( 'input' ) );
	}

	/**
	 * Real REST execution tolerates malformed prefs without coercing them.
	 *
	 * @dataProvider rest_motion_preferences
	 * @param mixed $motion Raw reduced-motion value.
	 */
	public function test_rest_execution_records_without_invalid_preferences( $motion ): void {
		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/extrachill/track-page-view/run' );
		$request->set_param(
			'input',
			array(
				'source_path'    => '/events/',
				'route_family'   => 'directory',
				'proof'          => extrachill_analytics_pageview_proof( 0, '/events/', 'directory', extrachill_analytics_public_write_source_host() ),
				'reduced_motion' => $motion,
				'color_scheme'   => array( 'dark' ),
				'contrast'       => 'more',
			)
		);
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $request->get_param( 'input' ) ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( array( 'recorded' => true ), $response->get_data() );
		global $wpdb;
		$data = json_decode( $wpdb->get_var( 'SELECT event_data FROM ' . extrachill_analytics_events_table() . ' ORDER BY id DESC LIMIT 1' ), true );
		if ( is_bool( $motion ) ) {
			$this->assertSame( $motion, $data['reduced_motion'] );
		} else {
			$this->assertArrayNotHasKey( 'reduced_motion', $data );
		}
		$this->assertArrayNotHasKey( 'color_scheme', $data );
		$this->assertSame( 'more', $data['contrast'] );
	}

	/** Only native JSON booleans may survive REST schema sanitization. */
	public static function rest_motion_preferences(): array {
		return array(
			'reduce'       => array( true ),
			'no reduce'    => array( false ),
			'string false' => array( 'false' ),
			'string true'  => array( 'true' ),
			'zero'         => array( 0 ),
			'one'          => array( 1 ),
			'null'         => array( null ),
			'array'        => array( array( true ) ),
		);
	}

	/** Read-side permissions and schemas match sibling report abilities. */
	public function test_report_registration(): void {
		$ability = wp_get_ability( 'extrachill/get-display-preferences' );
		$this->assertNotNull( $ability );
		$this->assertFalse( $ability->get_meta()['show_in_rest'] );
		$this->assertNotTrue( $ability->check_permissions( array() ) );
		$schema = wp_get_ability( 'extrachill/track-page-view' )->get_input_schema();
		$this->assertSame( array( 'dark', 'light' ), $schema['properties']['color_scheme']['enum'] );
		$this->assertSame( 'boolean', $schema['properties']['reduced_motion']['type'] );
	}

	/** Report historical unknowns, opt-outs, deduplication, and blog scope. */
	public function test_report_counts_and_unique_latest_observations(): void {
		$this->event( 1, 'a', 'dark' );
		$this->event( 1, 'a', 'dark' );
		$this->event( 2, 'a', 'light' );
		$this->event( 1, 'b', null );
		$this->event( 2, null, 'dark' );
		$this->event( 2, 'c', 'invalid' );
		$this->event( 3, null, null );
		$this->event( 1, 'outside', 'light', '2026-07-19 00:00:00' );
		$this->event( 1, 'before', 'light', '2026-07-17 23:59:59' );
		$report = $this->report();
		global $wpdb;
		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame(
			array(
				'dark'    => 3,
				'light'   => 1,
				'unknown' => 3,
			),
			$report['overall']['pageviews']['counts']
		);
		$this->assertSame(
			array(
				'dark'    => 0,
				'light'   => 1,
				'unknown' => 2,
			),
			$report['overall']['unique_visitors']['counts']
		);
		$this->assertSame( 0.3333, $report['overall']['unique_visitors']['shares']['light'] );
		$this->assertSame( 2, $report['by_blog'][1]['unique_visitors']['total'] );
		$this->assertSame( 1, $report['by_blog'][1]['unique_visitors']['counts']['dark'] );
		$this->assertSame( 0, $report['by_blog'][3]['unique_visitors']['total'] );
		$this->assertSame( 0.0, $report['by_blog'][3]['unique_visitors']['shares']['dark'] );
		$filtered = $this->report( array( 'blog_id' => 1 ) );
		$this->assertSame( 3, $filtered['overall']['pageviews']['total'] );
		$this->assertSame( 1, $filtered['overall']['unique_visitors']['counts']['dark'] );
		$this->assertCount( 1, $filtered['by_blog'] );
	}

	/** Latest unknown observations stay unknown instead of inheriting a scheme. */
	public function test_latest_unknown_and_null_visitors(): void {
		$this->event( 1, 'a', 'dark' );
		$this->event( 1, 'a', null, '2026-07-18 02:00:00' );
		$this->event( 1, '', 'light' );
		$this->event( 1, null, 'DARK' );
		$report = $this->report();
		$this->assertSame(
			array(
				'dark'    => 1,
				'light'   => 1,
				'unknown' => 2,
			),
			$report['overall']['pageviews']['counts']
		);
		$this->assertSame(
			array(
				'dark'    => 0,
				'light'   => 0,
				'unknown' => 1,
			),
			$report['overall']['unique_visitors']['counts']
		);
	}

	/** Observation time wins over insert order; other event types are ignored. */
	public function test_latest_observation_uses_pageview_timestamp(): void {
		$this->event( 1, 'a', 'dark', '2026-07-18 03:00:00' );
		$this->event( 1, 'a', 'light', '2026-07-18 01:00:00' );
		global $wpdb;
		$wpdb->insert(
			extrachill_analytics_events_table(),
			array(
				'event_type' => 'cta_click',
				'event_data' => wp_json_encode( array( 'color_scheme' => 'light' ) ),
				'blog_id'    => 1,
				'visitor_id' => 'a',
				'created_at' => '2026-07-18 04:00:00',
				'source_url' => '/events/',
			)
		);
		$report = $this->report();
		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( 2, $report['overall']['pageviews']['total'] );
		foreach ( array( $report['overall'], $report['by_blog'][1] ) as $scope ) {
			$this->assertSame( 1, $scope['unique_visitors']['total'] );
			$this->assertSame( 1, $scope['unique_visitors']['counts']['dark'] );
			$this->assertSame( 1.0, $scope['unique_visitors']['shares']['dark'] );
		}
	}

	/** Non-string JSON values are unknown, not coerced into display enums. */
	public function test_report_strict_scheme_types(): void {
		foreach ( array( true, false, 0, 1, array( 'dark' ), array( 'value' => 'light' ), 'DARK', 'Light' ) as $index => $scheme ) {
			$this->event( 1, 'visitor-' . $index, $scheme );
		}
		$report = $this->report();
		foreach ( array( 'pageviews', 'unique_visitors' ) as $metric ) {
			$this->assertSame( 8, $report['overall'][ $metric ]['total'] );
			$this->assertSame( 8, $report['overall'][ $metric ]['counts']['unknown'] );
			$this->assertSame( 1.0, $report['overall'][ $metric ]['shares']['unknown'] );
		}
	}

	/** Empty windows and invalid paired dates follow read-side conventions. */
	public function test_empty_and_invalid_date_ranges(): void {
		$report = $this->report();
		$this->assertSame( 0, $report['overall']['pageviews']['total'] );
		$this->assertSame( array(), $report['by_blog'] );
		$this->assertWPError( extrachill_analytics_ability_get_display_preferences( array( 'start_date' => '2026-07-18' ) ) );
	}

	/** @return array Recorded event JSON. */
	private function record( array $preferences ): array {
		$input          = array_merge(
			array(
				'source_path'  => '/events/',
				'route_family' => 'directory',
			),
			$preferences
		);
		$input['proof'] = extrachill_analytics_pageview_proof( 0, '/events/', 'directory', extrachill_analytics_public_write_source_host() );
		$ability        = wp_get_ability( 'extrachill/track-page-view' );
		$this->assertSame( array( 'recorded' => true ), $ability->execute( $input ) );
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . extrachill_analytics_events_table() . ' ORDER BY id DESC LIMIT 1' );
		$this->assertNotNull( $row );
		return json_decode( $row->event_data, true );
	}

	/** Insert deterministic raw rows, including legacy and anonymous ones. */
	private function event( $blog_id, $visitor, $scheme, $date = '2026-07-18 01:00:00' ): void {
		global $wpdb;
		$wpdb->insert(
			extrachill_analytics_events_table(),
			array(
				'event_type' => 'pageview',
				'event_data' => wp_json_encode( null === $scheme ? array() : array( 'color_scheme' => $scheme ) ),
				'blog_id'    => $blog_id,
				'visitor_id' => $visitor,
				'created_at' => $date,
				'source_url' => '/events/',
			)
		);
	}

	/** @return array Report for a fixed UTC day. */
	private function report( array $input = array() ): array {
		return extrachill_analytics_ability_get_display_preferences(
			array_merge(
				array(
					'start_date' => '2026-07-18',
					'end_date'   => '2026-07-18',
				),
				$input
			)
		);
	}
}
