<?php
/**
 * Calendar query browser admission and reporting tests.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/** Verify the calendar query event contract through the public adapter. */
final class CalendarQueryWriteTest extends Extrachill_Analytics_TestCase {

	/** Set up a same-origin browser request with a first-party visitor ID. */
	public function set_up(): void {
		parent::set_up();
		$this->set_ext_object_cache( true );
		$this->set_request( 'example.org', 'POST' );
		$_SERVER['HTTP_ORIGIN']     = 'http://localhost';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		$_SERVER['REMOTE_ADDR']     = '203.0.113.20';
		$_COOKIE['ec_vid']          = '123e4567-e89b-42d3-a456-426614174000';
	}

	/**
	 * Build a valid calendar query with optional dimension overrides.
	 *
	 * @param array $overrides Event dimension overrides.
	 * @return array Ability input.
	 */
	private function input( array $overrides = array() ): array {
		return array(
			'source_url' => 'http://localhost/calendar/',
			'event_data' => array_merge(
				array(
					'trigger'        => 'search',
					'scope'          => 'tonight',
					'has_date_range' => false,
					'search_term'    => 'Jazz',
					'result_count'   => 0,
					'route'          => 'other',
				),
				$overrides
			),
		);
	}

	/** Only the fixed calendar event is exposed by this REST ability. */
	public function test_registration(): void {
		$this->assertSame( 'calendar_query', EC_ANALYTICS_EVENT_CALENDAR_QUERY );
		$this->assertContains( EC_ANALYTICS_EVENT_CALENDAR_QUERY, extrachill_analytics_public_browser_event_types() );
		$ability = wp_get_ability( 'extrachill/track-calendar-query' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertTrue( $ability->get_meta()['show_in_rest'] );
		$this->assertFalse( $ability->get_input_schema()['additionalProperties'] );
		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/extrachill/track-calendar-query/run' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $this->input() ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 1, $this->event_count( EC_ANALYTICS_EVENT_CALENDAR_QUERY ) );
	}

	/** Accepted writes sanitize terms, resolve taxonomy IDs, and stamp identity. */
	public function test_admits_and_normalizes_calendar_query(): void {
		register_taxonomy( 'calendar_test_location', 'data_machine_events' );
		try {
			$term = self::factory()->term->create( array(
				'taxonomy' => 'calendar_test_location',
				'slug'     => 'charleston',
			) );
			$id   = extrachill_analytics_ability_track_calendar_query(
				$this->input(
					array(
						'search_term'      => '<em>Jazz</em> ' . str_repeat( 'x', 250 ),
						'taxonomies'       => array( 'calendar_test_location' => array( $term ) ),
						'archive_taxonomy' => 'calendar_test_location',
						'archive_term'     => $term,
						'is_bot'           => true,
					)
				)
			);
			$this->assertGreaterThan( 0, $id );
			$row  = $this->event_rows( EC_ANALYTICS_EVENT_CALENDAR_QUERY )[0];
			$data = $this->event_data( $row );
			$this->assertSame( 'Jazz ' . str_repeat( 'x', 195 ), $data['search_term'] );
			$this->assertSame( array( 'calendar_test_location' => array( 'charleston' ) ), $data['taxonomies'] );
			$this->assertSame( 'charleston', $data['archive_term'] );
			$this->assertFalse( $data['is_bot'] );
			$this->assertSame( $_COOKIE['ec_vid'], $row->visitor_id );
		} finally {
			unregister_taxonomy( 'calendar_test_location' );
		}
	}

	/** Off-site writes and unsupported dimensions fail public admission. */
	public function test_rejects_invalid_origin_and_data(): void {
		$_SERVER['HTTP_ORIGIN'] = 'https://other.example';
		$error                  = extrachill_analytics_ability_track_calendar_query( $this->input() );
		$this->assertWPError( $error );
		$this->assertSame( 'invalid_event_origin', $error->get_error_code() );

		$_SERVER['HTTP_ORIGIN'] = 'http://localhost';
		$error                  = extrachill_analytics_ability_track_calendar_query( $this->input( array( 'result_count' => -1 ) ) );
		$this->assertWPError( $error );
		$this->assertSame( 'invalid_calendar_query', $error->get_error_code() );
		$error = extrachill_analytics_ability_track_calendar_query( $this->input( array( 'latitude' => 32.8 ) ) );
		$this->assertWPError( $error );
		$this->assertSame( 'invalid_calendar_query', $error->get_error_code() );
		$this->assertSame( 0, $this->event_count() );
	}

	/** Encoded probes are classified before text sanitization. */
	public function test_calendar_search_payload_is_routed_to_search_attack(): void {
		$id = extrachill_analytics_ability_track_calendar_query( $this->input( array( 'search_term' => '%252Fetc%252Fshells' ) ) );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 0, $this->event_count( EC_ANALYTICS_EVENT_CALENDAR_QUERY ) );
		$this->assertSame( 1, $this->event_count( EC_ANALYTICS_EVENT_SEARCH_ATTACK ) );
	}

	/** The report counts distinct visitors and excludes bot queries. */
	public function test_report_breakdown(): void {
		extrachill_analytics_ability_track_calendar_query( $this->input() );
		extrachill_analytics_ability_track_calendar_query( $this->input( array( 'result_count' => 9 ) ) );
		$_SERVER['HTTP_USER_AGENT'] = 'Googlebot';
		extrachill_analytics_ability_track_calendar_query( $this->input() );

		$report = extrachill_analytics_calendar_query_report( 30, get_current_blog_id(), 15 );
		$this->assertSame( array(
			array(
				'trigger'               => 'search',
				'queries'               => 2,
				'unique_human_visitors' => 1,
			),
		), $report['triggers'] );
		$this->assertSame( 1, $report['zero_results'] );
		$this->assertSame( array(
			array(
				'value'   => 'tonight',
				'queries' => 2,
			),
		), $report['scopes'] );
		$this->assertSame( array(
			array(
				'value'   => 'Jazz',
				'queries' => 2,
			),
		), $report['search_terms'] );
	}
}
