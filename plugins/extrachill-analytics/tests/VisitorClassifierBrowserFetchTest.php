<?php
/**
 * Browser-initiated REST requests are human (#275, #302).
 *
 * Front-end forms (registration, newsletter signup) submit through REST. The
 * classifier used to treat every REST request as programmatic, so every real
 * `user_registration` event was stamped is_bot:true.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

final class VisitorClassifierBrowserFetchTest extends Extrachill_Analytics_TestCase {

	private const BROWSER_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15';

	public function tear_down(): void {
		unset( $_SERVER['HTTP_SEC_FETCH_SITE'] );
		parent::tear_down();
	}

	/**
	 * @param array $overrides Context overrides.
	 * @return array
	 */
	private function classify( array $overrides ): array {
		return extrachill_analytics_classify_request(
			array_merge(
				array(
					'user_agent'         => self::BROWSER_UA,
					'has_visitor_cookie' => true,
					'request_origin'     => 'rest',
					'is_authenticated'   => false,
				),
				$overrides
			)
		);
	}

	public function test_same_origin_browser_rest_request_is_human(): void {
		$verdict = $this->classify( array( 'is_browser_fetch' => true ) );

		$this->assertTrue( $verdict['is_human'] );
		$this->assertTrue( $verdict['signals']['is_browser_fetch'] );
	}

	public function test_rest_request_without_browser_fetch_stays_bot(): void {
		$this->assertTrue( $this->classify( array( 'is_browser_fetch' => false ) )['is_bot'] );
	}

	public function test_browser_fetch_still_requires_cookie_and_browser_ua(): void {
		$this->assertTrue( $this->classify( array( 'is_browser_fetch' => true, 'has_visitor_cookie' => false ) )['is_bot'] );
		$this->assertTrue( $this->classify( array( 'is_browser_fetch' => true, 'user_agent' => 'python-requests/2.31' ) )['is_bot'] );
	}

	public function test_browser_fetch_does_not_rescue_cli_or_cron(): void {
		$this->assertTrue( $this->classify( array( 'is_browser_fetch' => true, 'request_origin' => 'cli' ) )['is_bot'] );
		$this->assertTrue( $this->classify( array( 'is_browser_fetch' => true, 'request_origin' => 'cron' ) )['is_bot'] );
	}

	public function test_sec_fetch_site_header_drives_detection(): void {
		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
		$this->assertTrue( extrachill_analytics_is_browser_fetch() );

		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-site';
		$this->assertTrue( extrachill_analytics_is_browser_fetch() );

		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
		$this->assertFalse( extrachill_analytics_is_browser_fetch() );

		unset( $_SERVER['HTTP_SEC_FETCH_SITE'] );
		$this->assertFalse( extrachill_analytics_is_browser_fetch() );
	}

	public function test_registration_event_from_browser_rest_is_stamped_human(): void {
		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
		$_SERVER['HTTP_USER_AGENT']     = self::BROWSER_UA;

		$verdict = extrachill_analytics_classify_request(
			array(
				'has_visitor_cookie' => true,
				'request_origin'     => 'rest',
				'is_authenticated'   => false,
			)
		);

		$this->assertFalse( $verdict['is_bot'] );
	}
}
