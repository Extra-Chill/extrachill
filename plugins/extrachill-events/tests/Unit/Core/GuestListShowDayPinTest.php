<?php
/**
 * Show-day pin of the host guest-list panel (extrachill#1139).
 *
 * Covers extrachill_events_should_pin_guest_list(): host + show day + guest
 * list enabled pins the panel; non-hosts, other days, and a disabled guest
 * list do not; "show day" is the start date in the site timezone, not UTC.
 *
 * Shims share global keys with sibling files in the rsvp-perks-unit suite
 * (`event_management_test` posts/caps, `event_perk_test` meta,
 * `pass_card_test` dates), so load order doesn't matter.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP function shims with the test case class.

if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1024 * 1024 );
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {
		unset( $args );
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {
		unset( $args );
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default_value = false ) {
		return $GLOBALS['event_management_test']['options'][ $key ] ?? $default_value;
	}
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, $capability, ...$args ) {
		if ( 'edit_post' === $capability ) {
			return in_array( (int) ( $args[0] ?? 0 ), $GLOBALS['event_management_test']['editable_posts'][ $user_id ] ?? array(), true );
		}
		return in_array( $capability, $GLOBALS['event_management_test']['caps'][ $user_id ] ?? array(), true );
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		return $GLOBALS['event_management_test']['posts'][ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy ) {
		return $GLOBALS['event_management_test']['terms'][ $post_id ][ $taxonomy ] ?? array();
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		unset( $single );
		return $GLOBALS['event_perk_test']['meta'][ $post_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'datamachine_get_event_dates' ) ) {
	function datamachine_get_event_dates( int $post_id ): ?object {
		return $GLOBALS['pass_card_test']['dates'][ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		return new DateTimeZone( $GLOBALS['guest_list_pin_test']['timezone'] ?? 'UTC' );
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/admin/event-perks.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/event-management-authority.php';

// guest-list-page.php also defines helpers sibling files shim, so mirror its
// "guest list enabled" rule here instead of loading it.
if ( ! function_exists( 'extrachill_events_has_guest_list_page' ) ) {
	function extrachill_events_has_guest_list_page( int $event_id ): bool {
		return $event_id > 0
			&& extrachill_events_perk_enabled( $event_id )
			&& extrachill_events_perk_password_required( $event_id );
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/guest-list-management.php';

final class GuestListShowDayPinTest extends PHPUnit\Framework\TestCase {

	private const EVENT_ID = 569064;
	private const HOST_ID  = 11;
	private const ADMIN_ID = 12;
	private const GUEST_ID = 13;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['event_management_test'] = array(
			'options'        => array(),
			'caps'           => array( self::ADMIN_ID => array( 'manage_options' ) ),
			'editable_posts' => array( self::HOST_ID => array( self::EVENT_ID ) ),
			'posts'          => array(
				self::EVENT_ID => (object) array(
					'ID'        => self::EVENT_ID,
					'post_type' => 'data_machine_events',
				),
			),
			'terms'          => array(),
		);
		$GLOBALS['event_perk_test']       = array(
			'meta' => array(
				self::EVENT_ID => array(
					EXTRACHILL_EVENTS_PERK_ENABLED_META  => '1',
					EXTRACHILL_EVENTS_PERK_PASSWORD_META => 'hashed-password',
				),
			),
		);
		$GLOBALS['pass_card_test']        = array(
			'dates' => array(
				self::EVENT_ID => (object) array(
					'start_datetime' => '2026-11-05 20:00:00',
					'end_datetime'   => '2026-11-05 23:59:59',
				),
			),
		);
		$GLOBALS['guest_list_pin_test']   = array( 'timezone' => 'America/New_York' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['event_management_test'], $GLOBALS['event_perk_test'], $GLOBALS['pass_card_test'], $GLOBALS['guest_list_pin_test'] );
		parent::tearDown();
	}

	/** Unix timestamp for a wall-clock time in the site timezone. */
	private function local( string $datetime ): int {
		return ( new DateTimeImmutable( $datetime, new DateTimeZone( $GLOBALS['guest_list_pin_test']['timezone'] ) ) )->getTimestamp();
	}

	public function test_host_on_show_day_with_guest_list_enabled_pins(): void {
		$this->assertTrue( extrachill_events_should_pin_guest_list( self::EVENT_ID, self::HOST_ID, $this->local( '2026-11-05 18:00:00' ) ) );
		$this->assertTrue( extrachill_events_should_pin_guest_list( self::EVENT_ID, self::ADMIN_ID, $this->local( '2026-11-05 00:00:00' ) ) );
	}

	public function test_non_host_never_pins(): void {
		$now = $this->local( '2026-11-05 18:00:00' );
		$this->assertFalse( extrachill_events_should_pin_guest_list( self::EVENT_ID, self::GUEST_ID, $now ) );
		$this->assertFalse( extrachill_events_should_pin_guest_list( self::EVENT_ID, 0, $now ) );
	}

	public function test_host_on_other_days_does_not_pin(): void {
		$this->assertFalse( extrachill_events_should_pin_guest_list( self::EVENT_ID, self::HOST_ID, $this->local( '2026-11-04 23:59:59' ) ) );
		$this->assertFalse( extrachill_events_should_pin_guest_list( self::EVENT_ID, self::HOST_ID, $this->local( '2026-11-06 00:00:00' ) ) );
		$this->assertFalse( extrachill_events_should_pin_guest_list( self::EVENT_ID, self::HOST_ID, $this->local( '2026-10-01 18:00:00' ) ) );
	}

	public function test_disabled_guest_list_does_not_pin(): void {
		$now = $this->local( '2026-11-05 18:00:00' );

		// Perk on but no password: an RSVP perk, not a guest list.
		unset( $GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ][ EXTRACHILL_EVENTS_PERK_PASSWORD_META ] );
		$this->assertFalse( extrachill_events_should_pin_guest_list( self::EVENT_ID, self::HOST_ID, $now ) );

		// Perk off entirely.
		$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ] = array( EXTRACHILL_EVENTS_PERK_PASSWORD_META => 'hashed-password' );
		$this->assertFalse( extrachill_events_should_pin_guest_list( self::EVENT_ID, self::HOST_ID, $now ) );
	}

	public function test_show_day_uses_site_timezone_not_utc(): void {
		$GLOBALS['pass_card_test']['dates'][ self::EVENT_ID ]->start_datetime = '2026-11-05 23:30:00';

		// 23:00 local on show day is already Nov 6 in UTC: still show day.
		$late_local = $this->local( '2026-11-05 23:00:00' );
		$this->assertSame( '2026-11-06', gmdate( 'Y-m-d', $late_local ) );
		$this->assertTrue( extrachill_events_should_pin_guest_list( self::EVENT_ID, self::HOST_ID, $late_local ) );

		// Early evening Nov 4 local is already Nov 5 in UTC: not show day yet.
		$GLOBALS['guest_list_pin_test']['timezone'] = 'America/Los_Angeles';
		$eve_local                                  = $this->local( '2026-11-04 17:30:00' );
		$this->assertSame( '2026-11-05', gmdate( 'Y-m-d', $eve_local ) );
		$this->assertFalse( extrachill_events_should_pin_guest_list( self::EVENT_ID, self::HOST_ID, $eve_local ) );
	}

	public function test_event_without_start_date_does_not_pin(): void {
		unset( $GLOBALS['pass_card_test']['dates'][ self::EVENT_ID ] );
		$this->assertFalse( extrachill_events_should_pin_guest_list( self::EVENT_ID, self::HOST_ID, $this->local( '2026-11-05 18:00:00' ) ) );
	}
}
