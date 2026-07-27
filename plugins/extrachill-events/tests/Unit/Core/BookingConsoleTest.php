<?php
/**
 * Booking console route contract tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true;
	}
}
if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( $site ) {
		return 'events' === $site ? 7 : 1;
	}
}
if ( ! function_exists( 'get_home_url' ) ) {
	function get_home_url( $blog_id, $path = '' ) {
		return 7 === (int) $blog_id ? 'https://events.example' . $path : 'https://example.com' . $path;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.com' . $path;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url ) {
		return $url . '?' . http_build_query( $args );
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/booking-console.php';

final class BookingConsoleTest extends TestCase {
	public function test_booking_route_is_deterministic_and_calendar_first(): void {
		$base_url = get_home_url( (int) ec_get_blog_id( 'events' ), '/venue-settings/' );
		$this->assertSame(
			$base_url . '?venue_id=44&booking_id=91#tab-calendar',
			ec_events_get_booking_console_url( 44, 91 )
		);
	}

	public function test_manage_venue_route_does_not_emit_placeholder_ids(): void {
		$base_url = get_home_url( (int) ec_get_blog_id( 'events' ), '/venue-settings/' );
		$this->assertSame(
			$base_url . '#tab-calendar',
			ec_events_get_booking_console_url( 0 )
		);
	}
}
