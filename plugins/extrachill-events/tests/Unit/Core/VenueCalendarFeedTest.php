<?php
/**
 * Venue calendar feed URL validation tests.
 *
 * Covers the pure validation surface of VenueCalendarFeed. The feed URL is
 * user-supplied and gets fetched server-side on a schedule, so the SSRF guard
 * is the security-critical part of this class and is asserted directly rather
 * than through the ability layer.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;

		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url, $protocols = null ) {
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		if ( is_array( $protocols ) && ! in_array( $scheme, $protocols, true ) ) {
			return '';
		}
		return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

require_once __DIR__ . '/../../../inc/Core/VenueCalendarFeed.php';

use ExtraChillEvents\Core\VenueCalendarFeed;

class VenueCalendarFeedTest extends TestCase {

	public function test_accepts_public_https_ics_url() {
		$result = VenueCalendarFeed::normalize_url( 'https://calendar.google.com/calendar/ical/abc%40group.calendar.google.com/public/basic.ics' );

		$this->assertIsString( $result );
		$this->assertStringStartsWith( 'https://', $result );
	}

	/**
	 * webcal:// is the subscription scheme Google and Apple hand out. It is
	 * https:// over the wire, so pasting it must work rather than being
	 * rejected as an unsupported scheme.
	 */
	public function test_normalizes_webcal_scheme_to_https() {
		$result = VenueCalendarFeed::normalize_url( 'webcal://calendar.google.com/calendar/ical/x/public/basic.ics' );

		$this->assertIsString( $result );
		$this->assertStringStartsWith( 'https://', $result );
		$this->assertStringNotContainsString( 'webcal', $result );
	}

	public function test_trims_surrounding_whitespace() {
		$result = VenueCalendarFeed::normalize_url( "  https://example.com/feed.ics \n" );

		$this->assertSame( 'https://example.com/feed.ics', $result );
	}

	public function test_rejects_empty_url() {
		$result = VenueCalendarFeed::normalize_url( '   ' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'venue_calendar_feed_url_empty', $result->get_error_code() );
	}

	/**
	 * @dataProvider non_http_schemes
	 */
	public function test_rejects_non_http_schemes( string $url ) {
		$result = VenueCalendarFeed::normalize_url( $url );

		$this->assertInstanceOf( WP_Error::class, $result, $url . ' must be rejected' );
	}

	public function non_http_schemes(): array {
		return array(
			'file'   => array( 'file:///etc/passwd' ),
			'ftp'    => array( 'ftp://example.com/feed.ics' ),
			'gopher' => array( 'gopher://example.com/feed.ics' ),
			'data'   => array( 'data:text/calendar;base64,QkVHSU4=' ),
		);
	}

	/**
	 * The bound URL is fetched server-side on a schedule. Private, loopback,
	 * and link-local destinations must be refused so the sync runner cannot be
	 * turned into an SSRF primitive. AWS/GCP metadata at 169.254.169.254 is the
	 * canonical target and is covered explicitly.
	 *
	 * @dataProvider private_hosts
	 */
	public function test_rejects_private_and_loopback_hosts( string $url ) {
		$result = VenueCalendarFeed::normalize_url( $url );

		$this->assertInstanceOf( WP_Error::class, $result, $url . ' must be rejected' );
		$this->assertSame( 'venue_calendar_feed_url_host_blocked', $result->get_error_code() );
	}

	public function private_hosts(): array {
		return array(
			'localhost name' => array( 'https://localhost/feed.ics' ),
			'loopback v4'    => array( 'https://127.0.0.1/feed.ics' ),
			'private 10/8'   => array( 'https://10.0.0.5/feed.ics' ),
			'private 172.16' => array( 'https://172.16.0.5/feed.ics' ),
			'private 192.168'=> array( 'https://192.168.1.10/feed.ics' ),
			'link local'     => array( 'https://169.254.169.254/latest/meta-data/' ),
			'loopback v6'    => array( 'https://[::1]/feed.ics' ),
		);
	}

	public function test_rejects_malformed_url() {
		$result = VenueCalendarFeed::normalize_url( 'not a url at all' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_is_safe_host_allows_public_hostname() {
		$this->assertTrue( VenueCalendarFeed::is_safe_host( 'calendar.google.com' ) );
	}

	/**
	 * A public URL can redirect to a private one, so the sync runner re-checks
	 * every hop with this method. It must reject the same set as bind time.
	 *
	 * @dataProvider unsafe_hosts
	 */
	public function test_is_safe_host_rejects_unsafe( string $host ) {
		$this->assertFalse( VenueCalendarFeed::is_safe_host( $host ), $host . ' must be unsafe' );
	}

	public function unsafe_hosts(): array {
		return array(
			'empty'       => array( '' ),
			'localhost'   => array( 'localhost' ),
			'uppercase'   => array( 'LOCALHOST' ),
			'loopback'    => array( '127.0.0.1' ),
			'private'     => array( '10.1.2.3' ),
			'link local'  => array( '169.254.169.254' ),
			'ipv6 loop'   => array( '::1' ),
			'underscore'  => array( 'bad_host!' ),
		);
	}

	public function test_statuses_are_stable() {
		$this->assertSame(
			array( 'active', 'paused', 'error' ),
			VenueCalendarFeed::statuses()
		);
	}

	/**
	 * The source name pairs with the ICS UID to form a source identity
	 * namespace distinct from every other ingestion path. That separation is
	 * what stops a feed sync from adopting or unpublishing a scraped or
	 * publicly submitted event, so the constant is asserted directly.
	 */
	public function test_source_name_is_distinct_and_stable() {
		$this->assertSame( 'venue_calendar_feed', VenueCalendarFeed::SOURCE_NAME );
	}

	/**
	 * A venue may bind a working calendar rather than a dedicated public shows
	 * calendar. Entries the author explicitly marked private must never be
	 * imported - not even into a review queue, since surfacing
	 * "Staff meeting - payroll" to a reviewer already leaks it.
	 *
	 * @dataProvider private_entries
	 */
	public function test_private_entries_are_not_importable( array $event ) {
		$this->assertFalse( VenueCalendarFeed::is_importable( $event ) );
	}

	public function private_entries(): array {
		return array(
			'private'            => array( array( 'class' => 'PRIVATE' ) ),
			'confidential'       => array( array( 'class' => 'CONFIDENTIAL' ) ),
			'lowercase private'  => array( array( 'class' => 'private' ) ),
			'padded private'     => array( array( 'class' => ' PRIVATE ' ) ),
			'tentative hold'     => array( array( 'eventStatus' => 'TENTATIVE' ) ),
			'cancelled show'     => array( array( 'eventStatus' => 'CANCELLED' ) ),
			'lowercase cancelled' => array( array( 'eventStatus' => 'cancelled' ) ),
		);
	}

	/**
	 * @dataProvider public_entries
	 */
	public function test_public_entries_are_importable( array $event ) {
		$this->assertTrue( VenueCalendarFeed::is_importable( $event ) );
	}

	public function public_entries(): array {
		return array(
			'explicitly public' => array( array( 'class' => 'PUBLIC' ) ),
			'confirmed'         => array( array( 'eventStatus' => 'CONFIRMED' ) ),
			'public confirmed'  => array(
				array(
					'class'       => 'PUBLIC',
					'eventStatus' => 'CONFIRMED',
				),
			),
			'no markers at all' => array( array( 'title' => 'Some Show' ) ),
			'empty markers'     => array(
				array(
					'class'       => '',
					'eventStatus' => '',
				),
			),
		);
	}

	/**
	 * An unmarked entry is importable, which is exactly why import is not the
	 * same as publication. A venue buyout carries no CLASS marker, so markers
	 * alone cannot be the only defense - the review step is.
	 */
	public function test_unmarked_private_sounding_entry_is_still_importable() {
		$this->assertTrue(
			VenueCalendarFeed::is_importable( array( 'title' => 'Private party (buyout)' ) )
		);
	}
}
