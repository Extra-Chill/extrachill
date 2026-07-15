<?php
/**
 * Contract tests for outbound-click visitor identity.
 *
 * @package ExtraChill\API\Tests
 */

use PHPUnit\Framework\TestCase;

/**
 * Ensures the browser adapter trusts only the first-party request cookie.
 */
final class OutboundClickIdentityTest extends TestCase {
	/**
	 * Pageviews also leave identity resolution to the Analytics ability.
	 */
	public function test_pageview_route_does_not_accept_client_visitor_id(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/inc/routes/analytics/view-count.php' );

		$this->assertNotFalse( $source );
		$this->assertStringNotContainsString( "\$request->get_param( 'visitor_id' )", $source );
		$this->assertStringNotContainsString( "'visitor_id' => array(", $source );
		$this->assertStringNotContainsString( "'visitor_id' => \$visitor_id", $source );
	}

	/**
	 * Client input must not expose or control visitor identity.
	 */
	public function test_route_does_not_accept_client_visitor_id(): void {
		$source = $this->get_route_source();

		$this->assertStringNotContainsString( "\$request->get_param( 'visitor_id' )", $source );
		$this->assertStringNotContainsString( "'visitor_id'        => array(", $source );
	}

	/**
	 * Outbound events use the Analytics-owned cookie reader without minting.
	 */
	public function test_route_reads_existing_cookie_identity(): void {
		$source = $this->get_route_source();

		$this->assertStringContainsString( 'extrachill_analytics_read_visitor_id()', $source );
		$this->assertStringNotContainsString( 'extrachill_analytics_get_or_mint_visitor_id()', $source );
	}

	/**
	 * The outbound event receives the read-only Analytics resolver result.
	 */
	public function test_outbound_event_uses_cookie_resolver_result(): void {
		$source = $this->get_route_source();

		$this->assertStringContainsString(
			"\$visitor_id = function_exists( 'extrachill_analytics_read_visitor_id' )",
			$source
		);
		$this->assertStringContainsString(
			"'visitor_id' => \$visitor_id",
			$source
		);
	}

	/**
	 * Read the click route source.
	 *
	 * @return string
	 */
	private function get_route_source() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/inc/routes/analytics/click.php' );

		$this->assertNotFalse( $source );

		return $source;
	}
}
