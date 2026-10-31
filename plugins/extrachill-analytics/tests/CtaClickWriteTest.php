<?php
/**
 * Tests for the cta_click write path (Extra-Chill/extrachill-analytics#293).
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Verify extrachill/track-cta-click normalizes, ids, and admits cta_click events.
 */
final class CtaClickWriteTest extends Extrachill_Analytics_TestCase {

	/**
	 * Establish a normal first-party browser request.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->set_ext_object_cache( true );
		$this->set_request( 'example.org', 'POST' );
		$_SERVER['HTTP_ORIGIN']     = 'http://localhost';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		$_SERVER['REMOTE_ADDR']     = '203.0.113.20';
	}

	/**
	 * Baseline valid input for a design-system button click.
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	private function valid_input( array $overrides = array() ): array {
		return array_merge(
			array(
				'label'      => 'Join the Scene',
				'dest'       => 'localhost/power/join',
				'placement'  => 'main',
				'route'      => 'singular',
				'source_url' => 'https://localhost/power/',
			),
			$overrides
		);
	}

	/**
	 * The ability is registered, REST-visible, and its own schema is closed
	 * on the required dimensions.
	 */
	public function test_ability_is_registered_and_rest_visible(): void {
		$ability = wp_get_ability( 'extrachill/track-cta-click' );
		$this->assertInstanceOf( WP_Ability::class, $ability );

		$meta = $ability->get_meta();
		$this->assertTrue( $meta['show_in_rest'] );

		$schema = $ability->get_input_schema();
		$this->assertSame(
			array( 'label', 'dest', 'placement', 'route', 'source_url' ),
			$schema['required']
		);
	}

	/**
	 * A design-system button click with no explicit id gets a valid, stored
	 * cta_click row that passes the write gate.
	 */
	public function test_automatic_cta_click_is_recorded(): void {
		$result = extrachill_analytics_ability_track_cta_click( $this->valid_input() );

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
		$this->assertSame( 1, $this->event_count( EC_ANALYTICS_EVENT_CTA_CLICK ) );

		$data = $this->event_data( $this->event_rows( EC_ANALYTICS_EVENT_CTA_CLICK )[0] );
		$this->assertSame( 'Join the Scene', $data['label'] );
		$this->assertSame( 'localhost/power/join', $data['dest'] );
		$this->assertSame( 'main', $data['placement'] );
		$this->assertSame( 'singular', $data['route'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}$/', $data['cta'] );
	}

	/**
	 * The same route + label + dest always produces the same automatic id —
	 * the button survives re-renders and repeat page loads.
	 */
	public function test_automatic_cta_id_is_stable_across_page_loads(): void {
		$first  = extrachill_analytics_ability_track_cta_click( $this->valid_input() );
		$second = extrachill_analytics_ability_track_cta_click( $this->valid_input() );

		$this->assertGreaterThan( 0, $first );
		$this->assertGreaterThan( 0, $second );

		$rows = $this->event_rows( EC_ANALYTICS_EVENT_CTA_CLICK );
		$this->assertCount( 2, $rows );
		$this->assertSame(
			$this->event_data( $rows[0] )['cta'],
			$this->event_data( $rows[1] )['cta']
		);
	}

	/**
	 * A different label or destination on the same route changes the id.
	 */
	public function test_automatic_cta_id_changes_with_label_or_dest(): void {
		extrachill_analytics_ability_track_cta_click( $this->valid_input() );
		extrachill_analytics_ability_track_cta_click( $this->valid_input( array( 'label' => 'Something Else' ) ) );

		$rows = $this->event_rows( EC_ANALYTICS_EVENT_CTA_CLICK );
		$this->assertCount( 2, $rows );
		$this->assertNotSame(
			$this->event_data( $rows[0] )['cta'],
			$this->event_data( $rows[1] )['cta']
		);
	}

	/**
	 * data-ec-track overrides the automatic id.
	 */
	public function test_explicit_data_ec_track_overrides_automatic_id(): void {
		$result = extrachill_analytics_ability_track_cta_click(
			$this->valid_input( array( 'cta_override' => 'power-join' ) )
		);

		$this->assertGreaterThan( 0, $result );
		$data = $this->event_data( $this->event_rows( EC_ANALYTICS_EVENT_CTA_CLICK )[0] );
		$this->assertSame( 'power-join', $data['cta'] );
	}

	/**
	 * The explicit override is bounded to the same 80-character cta ceiling.
	 */
	public function test_explicit_override_is_bounded(): void {
		$result = extrachill_analytics_ability_track_cta_click(
			$this->valid_input( array( 'cta_override' => str_repeat( 'x', 200 ) ) )
		);

		$this->assertGreaterThan( 0, $result );
		$data = $this->event_data( $this->event_rows( EC_ANALYTICS_EVENT_CTA_CLICK )[0] );
		$this->assertSame( 80, strlen( $data['cta'] ) );
	}

	/**
	 * Query strings and fragments never reach the stored destination.
	 */
	public function test_dest_strips_query_string_and_fragment(): void {
		extrachill_analytics_ability_track_cta_click(
			$this->valid_input( array( 'dest' => 'https://open.spotify.com/artist/123?utm_source=fixture#top' ) )
		);

		$data = $this->event_data( $this->event_rows( EC_ANALYTICS_EVENT_CTA_CLICK )[0] );
		$this->assertSame( 'open.spotify.com/artist/123', $data['dest'] );
	}

	/**
	 * An unknown route family falls back to 'other' rather than failing closed.
	 */
	public function test_unknown_route_family_falls_back_to_other(): void {
		$result = extrachill_analytics_ability_track_cta_click( $this->valid_input( array( 'route' => 'not-a-real-route' ) ) );

		$this->assertGreaterThan( 0, $result );
		$data = $this->event_data( $this->event_rows( EC_ANALYTICS_EVENT_CTA_CLICK )[0] );
		$this->assertSame( 'other', $data['route'] );
	}

	/**
	 * A missing label or destination is rejected before it ever reaches the
	 * write gate.
	 */
	public function test_missing_required_field_is_rejected(): void {
		$result = extrachill_analytics_ability_track_cta_click( $this->valid_input( array( 'label' => '' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_cta_click', $result->get_error_code() );
		$this->assertSame( 0, $this->event_count() );
	}

	/**
	 * The closed schema rejects a field this event type does not define.
	 */
	public function test_closed_schema_rejects_unknown_field(): void {
		$result = extrachill_analytics_validate_public_event_write(
			EC_ANALYTICS_EVENT_CTA_CLICK,
			array(
				'cta'         => 'power-join',
				'label'       => 'Join',
				'dest'        => 'example.org/power/',
				'placement'   => 'main',
				'route'       => 'singular',
				'input_value' => 'never',
			),
			'/power/'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_event_field', $result->get_error_code() );
		$this->assertSame( 'input_value', $result->get_error_data( 'invalid_event_field' )['field'] );
	}

	/**
	 * The closed schema rejects an oversized value on a bounded field.
	 */
	public function test_closed_schema_rejects_oversized_value(): void {
		$result = extrachill_analytics_validate_public_event_write(
			EC_ANALYTICS_EVENT_CTA_CLICK,
			array(
				'cta'       => str_repeat( 'x', 81 ),
				'label'     => 'Join',
				'dest'      => 'example.org/power/',
				'placement' => 'main',
				'route'     => 'singular',
			),
			'/power/'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_event_field', $result->get_error_code() );
	}
}
