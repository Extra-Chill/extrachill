<?php
/**
 * Tests for the public telemetry adapter trust boundary.
 *
 * @package ExtraChill\API\Tests
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/inc/routes/analytics/telemetry-validation.php';

/**
 * Exercises sanitized request fixtures without asserting Analytics semantics.
 */
class TelemetryValidationTest extends TestCase {
	/**
	 * Disable IP admission state for deterministic validation fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$_SERVER['HTTP_HOST'] = wp_parse_url( home_url(), PHP_URL_HOST );
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	/**
	 * First-party beacon sources become query-free paths.
	 */
	public function test_valid_beacon_normalizes_source_to_path() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/analytics/click' );
		$request->set_param( 'source_url', home_url( '/features/story/?utm_source=bridge&email=reader%40example.test' ) );
		$request->set_header( 'origin', home_url() );

		$source = extrachill_api_validate_telemetry_request( $request );

		$this->assertIsArray( $source );
		$this->assertSame( '/features/story/', $source['path'] );
	}

	/**
	 * A supplied browser origin cannot contradict the claimed source.
	 */
	public function test_cross_origin_spoofing_is_rejected() {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/analytics/impression' );
		$request->set_param( 'source_url', home_url( '/story/' ) );
		$request->set_header( 'origin', 'https://attacker.example' );

		$result = extrachill_api_validate_telemetry_request( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_telemetry_origin', $result->get_error_code() );
	}

	/**
	 * Sensitive query fields are removed while benign affiliate data remains.
	 */
	public function test_destination_query_pii_is_removed() {
		$result = extrachill_api_normalize_telemetry_destination(
			'https://tickets.example/show?affiliate=extrachill&email=reader%40example.test&access_token=secret'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'https://tickets.example/show?affiliate=extrachill', $result['url'] );
	}

	/**
	 * Encoded credential-shaped values do not survive under generic field names.
	 */
	public function test_encoded_payload_query_is_removed() {
		$result = extrachill_api_normalize_telemetry_destination(
			'https://artist.example/listen?payload=%257B%2522password%2522%253A%2522redacted%2522%257D&campaign=summer'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'https://artist.example/listen?campaign=summer', $result['url'] );
	}

	/**
	 * Scanner destinations are rejected rather than retained as click evidence.
	 *
	 * @dataProvider scanner_destination_provider
	 * @param string $url Scanner URL fixture.
	 */
	public function test_scanner_destinations_are_rejected( $url ) {
		$result = extrachill_api_normalize_telemetry_destination( $url );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsafe_destination_url', $result->get_error_code() );
	}

	/**
	 * Scanner URL fixtures.
	 *
	 * @return array
	 */
	public function scanner_destination_provider() {
		return array(
			'login probe'   => array( 'https://scanner.example/wp-login.php' ),
			'env probe'     => array( 'https://scanner.example/%252eenv' ),
			'encoded form'  => array( 'https://scanner.example/collect%253Fpassword%253Dredacted' ),
			'phpunit probe' => array( 'https://scanner.example/vendor/phpunit/' ),
		);
	}

	/**
	 * Older same-site clients that send a relative source remain valid.
	 */
	public function test_relative_source_remains_compatible() {
		$result = extrachill_api_normalize_telemetry_source( '/archive/page/2/?ref=legacy' );

		$this->assertIsArray( $result );
		$this->assertSame( '/archive/page/2/', $result['path'] );
	}

	/**
	 * Outbound compatibility derives a host when legacy clients omit dest_host.
	 */
	public function test_outbound_route_keeps_dest_host_optional() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract fixture.
		$source = file_get_contents( dirname( __DIR__, 4 ) . '/inc/routes/analytics/click.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( '$dest_host = $destination[\'host\'];', $source );
		$this->assertStringNotContainsString( 'dest_host or destination_url is required', $source );
	}

	/**
	 * Internal subdomains cannot be relabeled as outbound destinations.
	 */
	public function test_network_subdomain_is_not_outbound() {
		$this->assertTrue( extrachill_api_is_network_telemetry_host( 'media.extrachill.com', array( 'extrachill.com' ) ) );
		$this->assertFalse( extrachill_api_is_network_telemetry_host( 'extrachill.example', array( 'extrachill.com' ) ) );
	}
}
