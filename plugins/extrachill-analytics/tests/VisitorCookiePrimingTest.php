<?php
/**
 * Tests for the visitor-cookie priming request boundary.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Verify anonymous identity is minted only on eligible frontend requests.
 */
final class VisitorCookiePrimingTest extends Extrachill_Analytics_TestCase {
	/**
	 * Set a normal first-party browser request before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->set_request( 'example.org', 'GET' );
	}

	/**
	 * Singular pages remain eligible after widening the boundary.
	 */
	public function test_singular_frontend_request_is_eligible(): void {
		$this->assertTrue( extrachill_analytics_should_prime_visitor_cookie() );
	}

	/**
	 * The request boundary also covers network home, archive, and login routes.
	 *
	 * @dataProvider eligible_frontend_route_provider
	 *
	 * @param string $host   First-party network host.
	 * @param string $method Safe browser request method.
	 */
	public function test_non_singular_and_login_frontend_requests_are_eligible( $host, $method ): void {
		$this->set_request( $host, $method );

		$this->assertTrue( extrachill_analytics_should_prime_visitor_cookie() );
	}

	/**
	 * First-party public route fixtures.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function eligible_frontend_route_provider() {
		return array(
			'network homepage' => array( 'example.org', 'GET' ),
			'archive'          => array( 'newsletter.example.org', 'GET' ),
			'login/register'   => array( 'community.example.org', 'GET' ),
			'head request'     => array( 'events.example.org', 'HEAD' ),
		);
	}

	/**
	 * Preview and non-template runtimes cannot mint identity.
	 *
	 * @dataProvider ineligible_runtime_provider
	 *
	 * @param string $runtime Simulated runtime context.
	 */
	public function test_preview_admin_ajax_and_cron_requests_are_ineligible( $runtime ): void {
		if ( 'preview' === $runtime ) {
			$this->set_query_flags( array( 'is_preview' => true ) );
		} elseif ( 'admin' === $runtime ) {
			$this->set_admin_context();
		} elseif ( 'ajax' === $runtime ) {
			$this->set_doing_context( true, false );
		} elseif ( 'cron' === $runtime ) {
			$this->set_doing_context( false, true );
		}

		$this->assertFalse( extrachill_analytics_should_prime_visitor_cookie() );
	}

	/**
	 * Runtime fixtures.
	 *
	 * @return array<string,array{string}>
	 */
	public function ineligible_runtime_provider() {
		return array(
			'preview' => array( 'preview' ),
			'admin'   => array( 'admin' ),
			'ajax'    => array( 'ajax' ),
			'cron'    => array( 'cron' ),
		);
	}

	/**
	 * REST and CLI guards remain explicit in the request boundary.
	 */
	public function test_rest_and_cli_requests_are_explicitly_ineligible(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/assets.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source fixture.

		$this->assertStringContainsString( "defined( 'REST_REQUEST' ) && REST_REQUEST", $source );
		$this->assertStringContainsString( "defined( 'WP_CLI' )", $source );
	}

	/**
	 * Unsafe methods and custom-domain requests cannot mint the network cookie.
	 */
	public function test_post_and_third_party_requests_are_ineligible(): void {
		$this->set_request( 'example.org', 'POST' );
		$this->assertFalse( extrachill_analytics_should_prime_visitor_cookie() );

		$this->set_request( 'artist.example', 'GET' );
		$this->assertFalse( extrachill_analytics_should_prime_visitor_cookie() );
	}

	/**
	 * GPC and DNT prevent priming on otherwise eligible requests.
	 *
	 * @dataProvider privacy_header_provider
	 *
	 * @param string $header Privacy request header.
	 */
	public function test_privacy_opt_out_requests_are_ineligible( $header ): void {
		$_SERVER[ $header ] = '1';

		$this->assertFalse( extrachill_analytics_should_prime_visitor_cookie() );
	}

	/**
	 * Supported privacy headers.
	 *
	 * @return array<string,array{string}>
	 */
	public function privacy_header_provider() {
		return array(
			'global privacy control' => array( 'HTTP_SEC_GPC' ),
			'do not track'           => array( 'HTTP_DNT' ),
		);
	}
}
