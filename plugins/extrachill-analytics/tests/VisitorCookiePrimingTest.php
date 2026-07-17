<?php
/**
 * Tests for the visitor-cookie priming request boundary.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

/**
 * Verify anonymous identity is minted only on eligible frontend requests.
 */
final class VisitorCookiePrimingTest extends TestCase {
	/**
	 * Load the request-boundary helpers.
	 */
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/inc/core/assets.php';
	}

	/**
	 * Set a normal first-party browser request before each test.
	 */
	protected function setUp(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'extrachill.com';
	}

	/**
	 * Restore request fixtures after each test.
	 */
	protected function tearDown(): void {
		unset(
			$_SERVER['REQUEST_METHOD'],
			$_SERVER['HTTP_HOST'],
			$_SERVER['HTTP_SEC_GPC'],
			$_SERVER['HTTP_DNT'],
			$GLOBALS['extrachill_analytics_test_is_preview'],
			$GLOBALS['extrachill_analytics_test_is_admin'],
			$GLOBALS['extrachill_analytics_test_doing_ajax'],
			$GLOBALS['extrachill_analytics_test_doing_cron']
		);
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
		$_SERVER['HTTP_HOST']      = $host;
		$_SERVER['REQUEST_METHOD'] = $method;

		$this->assertTrue( extrachill_analytics_should_prime_visitor_cookie() );
	}

	/**
	 * First-party public route fixtures.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function eligible_frontend_route_provider() {
		return array(
			'network homepage' => array( 'extrachill.com', 'GET' ),
			'archive'          => array( 'newsletter.extrachill.com', 'GET' ),
			'login/register'   => array( 'community.extrachill.com', 'GET' ),
			'head request'     => array( 'events.extrachill.com', 'HEAD' ),
		);
	}

	/**
	 * Preview and non-template runtimes cannot mint identity.
	 *
	 * @dataProvider ineligible_runtime_provider
	 *
	 * @param string $fixture Runtime fixture global.
	 */
	public function test_preview_admin_ajax_and_cron_requests_are_ineligible( $fixture ): void {
		$GLOBALS[ $fixture ] = true;

		$this->assertFalse( extrachill_analytics_should_prime_visitor_cookie() );
	}

	/**
	 * Runtime fixture globals.
	 *
	 * @return array<string,array{string}>
	 */
	public function ineligible_runtime_provider() {
		return array(
			'preview' => array( 'extrachill_analytics_test_is_preview' ),
			'admin'   => array( 'extrachill_analytics_test_is_admin' ),
			'ajax'    => array( 'extrachill_analytics_test_doing_ajax' ),
			'cron'    => array( 'extrachill_analytics_test_doing_cron' ),
		);
	}

	/**
	 * REST and CLI guards remain explicit in the request boundary.
	 */
	public function test_rest_and_cli_requests_are_explicitly_ineligible(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/assets.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source fixture.

		$this->assertStringContainsString( "defined( 'REST_REQUEST' ) && REST_REQUEST", $source );
		$this->assertStringContainsString( "defined( 'WP_CLI' ) && WP_CLI", $source );
	}

	/**
	 * Unsafe methods and custom-domain requests cannot mint the network cookie.
	 */
	public function test_post_and_third_party_requests_are_ineligible(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertFalse( extrachill_analytics_should_prime_visitor_cookie() );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'artist.example';
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
