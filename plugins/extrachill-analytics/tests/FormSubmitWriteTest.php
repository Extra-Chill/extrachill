<?php
/**
 * Tests for the form_submit write path (Extra-Chill/extrachill-analytics#293).
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Verify extrachill/track-form-submit admits only form/placement/route —
 * never a field value.
 */
final class FormSubmitWriteTest extends Extrachill_Analytics_TestCase {

	/**
	 * Establish a normal first-party browser request.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->set_ext_object_cache( true );
		$this->set_request( 'example.org', 'POST' );
		$_SERVER['HTTP_ORIGIN']     = 'http://localhost';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		$_SERVER['REMOTE_ADDR']     = '203.0.113.30';
	}

	/**
	 * Baseline valid input for a named newsletter form.
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	private function valid_input( array $overrides = array() ): array {
		return array_merge(
			array(
				'form'       => 'newsletter-signup',
				'placement'  => 'footer',
				'route'      => 'home',
				'source_url' => 'https://localhost/',
			),
			$overrides
		);
	}

	/**
	 * The ability is registered, REST-visible, and requires every dimension.
	 */
	public function test_ability_is_registered_and_rest_visible(): void {
		$ability = wp_get_ability( 'extrachill/track-form-submit' );
		$this->assertInstanceOf( WP_Ability::class, $ability );

		$meta = $ability->get_meta();
		$this->assertTrue( $meta['show_in_rest'] );

		$schema = $ability->get_input_schema();
		$this->assertSame(
			array( 'form', 'placement', 'route', 'source_url' ),
			$schema['required']
		);
	}

	/**
	 * A named form submission is recorded.
	 */
	public function test_named_form_submit_is_recorded(): void {
		$result = extrachill_analytics_ability_track_form_submit( $this->valid_input() );

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
		$this->assertSame( 1, $this->event_count( EC_ANALYTICS_EVENT_FORM_SUBMIT ) );

		$data = $this->event_data( $this->event_rows( EC_ANALYTICS_EVENT_FORM_SUBMIT )[0] );
		$this->assertSame(
			array( 'form', 'placement', 'route', 'is_bot' ),
			array_keys( $data )
		);
		$this->assertSame( 'newsletter-signup', $data['form'] );
		$this->assertSame( 'footer', $data['placement'] );
		$this->assertSame( 'home', $data['route'] );
	}

	/**
	 * A missing form identity is rejected rather than stored as an anonymous,
	 * indistinguishable row.
	 */
	public function test_missing_form_identity_is_rejected(): void {
		$result = extrachill_analytics_ability_track_form_submit( $this->valid_input( array( 'form' => '' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_form_submit', $result->get_error_code() );
		$this->assertSame( 0, $this->event_count() );
	}

	/**
	 * The closed schema never admits a field value — the security model this
	 * event type exists to guarantee.
	 */
	public function test_closed_schema_rejects_any_field_value(): void {
		$result = extrachill_analytics_validate_public_event_write(
			EC_ANALYTICS_EVENT_FORM_SUBMIT,
			array(
				'form'      => 'newsletter-signup',
				'placement' => 'footer',
				'route'     => 'home',
				'email'     => 'reader@example.test',
			),
			'/'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_event_field', $result->get_error_code() );
		$this->assertSame( 'email', $result->get_error_data( 'invalid_event_field' )['field'] );
	}

	/**
	 * The closed schema rejects an oversized form name.
	 */
	public function test_closed_schema_rejects_oversized_form_name(): void {
		$result = extrachill_analytics_validate_public_event_write(
			EC_ANALYTICS_EVENT_FORM_SUBMIT,
			array(
				'form'      => str_repeat( 'x', 81 ),
				'placement' => 'footer',
				'route'     => 'home',
			),
			'/'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_event_field', $result->get_error_code() );
	}
}
