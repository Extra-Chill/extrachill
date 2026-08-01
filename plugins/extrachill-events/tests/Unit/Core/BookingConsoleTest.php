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
if ( ! function_exists( '__' ) ) {
	/** Return untranslated test text. */
	function __( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'wp_login_url' ) ) {
	/** Build a deterministic test login URL. */
	function wp_login_url( $redirect ) {
		return home_url( '/wp-login.php?redirect_to=' . rawurlencode( $redirect ) );
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

	/**
	 * Verify each authorization state resolves its intended action.
	 *
	 * @dataProvider venue_workspace_states
	 * @param string $state Authorization state.
	 * @param string $label Expected action label.
	 * @param string $url   Expected action URL.
	 */
	public function test_venue_workspace_action_matches_authorization_state( string $state, string $label, string $url ): void {
		$action = ec_events_get_venue_workspace_action_for_state( 44, $state );
		$this->assertSame(
			array(
				'url'   => $url,
				'label' => $label,
			),
			$action
		);

		if ( 'logged_out' === $state ) {
			parse_str( (string) parse_url( $action['url'], PHP_URL_QUERY ), $query );
			$this->assertSame( $this->expected_workspace_url(), $query['redirect_to'] ?? '' );
		}
	}

	/** Provide member, non-member, logged-out, and administrator states. */
	public function venue_workspace_states(): array {
		$workspace = $this->expected_workspace_url();
		return array(
			'active member' => array( 'member', 'Manage Venue', $workspace ),
			'non-member'    => array( 'non_member', 'Claim or request access', $workspace ),
			'logged out'    => array( 'logged_out', 'Sign in to claim or manage', wp_login_url( $workspace ) ),
			'administrator' => array( 'administrator', 'Review venue claims', str_replace( '#tab-calendar', '#tab-claims', $workspace ) ),
		);
	}

	/** Build the expected workspace URL from the active WordPress environment. */
	private function expected_workspace_url(): string {
		$base_url = get_home_url( (int) ec_get_blog_id( 'events' ), '/venue-settings/' );
		return add_query_arg( array( 'venue_id' => 44 ), $base_url ) . '#tab-calendar';
	}

	/** Ensure all requested navigation surfaces remain registered. */
	public function test_navigation_surfaces_keep_existing_avatar_link_and_condition_homepage_card(): void {
		$plugin_root = dirname( __DIR__, 3 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local source contracts.
		$booking_console = file_get_contents( $plugin_root . '/inc/core/booking-console.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local source contracts.
		$feature_cards = file_get_contents( $plugin_root . '/inc/home/feature-cards.php' );

		$this->assertStringContainsString( "add_filter( 'ec_avatar_menu_items', 'ec_events_add_manage_venue_avatar_item'", $booking_console );
		$this->assertStringContainsString( "add_filter( 'extrachill_secondary_header_items', 'ec_events_add_venue_workspace_header_item'", $booking_console );
		$this->assertStringContainsString( 'ec_events_user_has_active_venue_membership( get_current_user_id() )', $feature_cards );
		$this->assertStringContainsString( 'Review inquiries, manage holds, and keep your venue calendar up to date.', $feature_cards );
	}
}
