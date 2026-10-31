<?php
/**
 * RSVP verify page — page-cache exemption (#883 review finding).
 *
 * Pure-PHP unit test matching this repo's established pure-suite convention
 * (see RsvpPassQrUrlsTest.php): the handful of WordPress functions this
 * file's target touches are stubbed faithfully for the exact call shapes
 * used, not the full WP test framework.
 *
 * Why this test exists: extrachill-cache's page cache
 * (wp-content/plugins/extrachill-cache/inc/page-cache.php) stores anonymous
 * 200 GET responses keyed by full URL including the query string, for a
 * day. /rsvp-verify/?code=XYZ's entire output depends on who is viewing and
 * which code is in the query string — without an explicit opt-out, the
 * first logged-out visit to a given ?code= URL (e.g. an unauthorized scan,
 * or a real attendee checking their own pass while logged out) would be
 * cached and served to every later anonymous visitor of that exact URL for
 * the cache's TTL. This test pins that
 * extrachill_events_rsvp_verify_answer_uncached() defines DONOTCACHEPAGE
 * (the constant extrachill-cache's page-cache.php actually checks before
 * storing a response) and calls nocache_headers(). The route-matching that
 * decides *whether* to call it (extrachill_events_rsvp_verify_pre_handle_404(),
 * which takes a real \WP_Query) is intentionally not exercised here — the
 * split exists precisely so the cache-exemption behavior is testable
 * without a \WP_Query stand-in.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP function shims with the test case class.

if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers(): void {
		++$GLOBALS['ec_rsvp_verify_cache_test']['nocache_headers_calls'];
	}
}

if ( ! function_exists( 'status_header' ) ) {
	function status_header( $code ): void {
		$GLOBALS['ec_rsvp_verify_cache_test']['status_header_calls'][] = $code;
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/rsvp-verify-page.php';

final class RsvpVerifyPageCacheTest extends PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ec_rsvp_verify_cache_test'] = array(
			'nocache_headers_calls' => 0,
			'status_header_calls'   => array(),
		);
	}

	public function test_answering_uncached_defines_donotcachepage_and_sends_nocache_headers(): void {
		$before_calls = $GLOBALS['ec_rsvp_verify_cache_test']['nocache_headers_calls'];

		$result = extrachill_events_rsvp_verify_answer_uncached();

		$this->assertTrue( $result );
		$this->assertTrue(
			defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE,
			'DONOTCACHEPAGE must be defined and truthy — this is the constant extrachill-cache\'s page-cache.php checks before storing a response.'
		);
		$this->assertSame(
			$before_calls + 1,
			$GLOBALS['ec_rsvp_verify_cache_test']['nocache_headers_calls'],
			'nocache_headers() must be called exactly once.'
		);
		$this->assertContains(
			200,
			$GLOBALS['ec_rsvp_verify_cache_test']['status_header_calls'],
			'The verify route must still respond 200, not the 404 it would otherwise preempt to.'
		);
	}

	public function test_calling_it_twice_does_not_redefine_the_constant(): void {
		// define() fatals if called on an already-defined constant without
		// the source's own `if ( ! defined( ... ) )` guard — a real request
		// only calls this once, but a persistent test/CLI process (or any
		// future second call site) must not trip that guard's absence.
		extrachill_events_rsvp_verify_answer_uncached();
		$result = extrachill_events_rsvp_verify_answer_uncached();

		$this->assertTrue( $result );
	}
}
