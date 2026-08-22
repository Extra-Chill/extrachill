<?php
/**
 * Real Events archive template fixture for venue claim browser coverage.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- This isolated fixture intentionally declares WordPress and domain stubs.

namespace ExtraChillEvents\Core {
	/** Deterministic authorization policy for archive role scenarios. */
	final class VenueAuthorization {
		public const ACTION_ACCESS_VENUE = 'access_venue';

		/** Whether the fixture user is an administrator. */
		public function is_administrator( int $user_id ): bool {
			return 'administrator' === ( $GLOBALS['venue_archive_role'] ?? '' );
		}

		/** Whether the fixture user is an active venue member. */
		public function can( int $user_id, int $venue_term_id, string $action ): bool {
			return 'active_member' === ( $GLOBALS['venue_archive_role'] ?? '' );
		}
	}

	/** Public booking projection used by the real archive template. */
	final class VenueBookingConfig {
		/** Return an enabled public booking projection. */
		public function get_public_projection( int $venue_term_id ): array {
			return array( 'enabled' => true );
		}
	}

	/** Canonical booking anchor used by the real archive template. */
	final class VenueBookingEmbed {
		/** Return the fixture venue booking URL. */
		public static function booking_url( \WP_Term $venue ): string {
			return '/venue/the-room/#booking-inquiry';
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	final class WP_Term {
		public int $term_id = 44;
		public string $name = 'The Room';
		public string $description = 'An independent music room.';
	}

	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Isolated browser fixture mirrors WordPress globals.
	$GLOBALS['venue_archive_role'] = isset( $_GET['role'] ) && is_scalar( $_GET['role'] ) ? (string) $_GET['role'] : 'logged_out'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Deterministic fixture selector.
	$GLOBALS['venue_archive_hooks'] = array();
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

	function add_filter( $hook, $callback, $priority = 10 ): bool {
		return true;
	}

	function add_action( $hook, $callback, $priority = 10 ): bool {
		$GLOBALS['venue_archive_hooks'][ $hook ][ $priority ][] = $callback;
		return true;
	}

	function do_action( $hook ): void {
		$callbacks = $GLOBALS['venue_archive_hooks'][ $hook ] ?? array();
		ksort( $callbacks );
		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				call_user_func( $callback );
			}
		}
	}

	function get_header(): void {
		?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/calendar.css"><style>*{box-sizing:border-box}body{margin:0;font:16px/1.5 Arial,sans-serif;--spacing-sm:.5rem;--spacing-md:1rem;--spacing-lg:1.5rem;--font-size-sm:.875rem;--muted-text:#555;--link-color:#174ea6;--link-hover-color:#123b7d}.page-content{margin-inline:auto;max-width:70rem;padding:1rem}.button-1,.button-3{display:inline-block;padding:.65rem .9rem}</style><title>Venue Archive Evidence</title></head><body><?php
	}

	function get_footer(): void {
		echo '</body></html>';
	}

	function extrachill_breadcrumbs(): void {}
	function is_tax( $taxonomy = '' ): bool {
		return '' === $taxonomy || 'venue' === $taxonomy;
	}
	function get_queried_object(): WP_Term {
		return new WP_Term();
	}
	function data_machine_events_get_venue_data( int $venue_term_id ): array {
		return array( 'website' => 'https://theroom.example' );
	}
	function data_machine_events_get_venue_address( int $venue_term_id, array $venue_data ): string {
		return '44 Music Way, Charleston, SC';
	}
	function single_term_title(): void {
		echo 'The Room';
	}
	function extrachill_events_render_term_calendar_stats( string $taxonomy, int $term_id ): void {}
	function term_description(): string {
		return 'An independent music room.';
	}
	function wpautop( string $text ): string {
		return '<p>' . $text . '</p>';
	}
	function wp_kses_post( string $text ): string {
		return $text;
	}
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
	function esc_url( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
	function esc_html_e( string $text ): void {
		echo esc_html( $text );
	}
	function __( string $text ): string {
		return $text;
	}
	function absint( $value ): int {
		return abs( (int) $value );
	}
	function ec_get_blog_id( string $site ): int {
		return 7;
	}
	function get_home_url( int $blog_id, string $path = '' ): string {
		return 'https://events.example' . $path;
	}
	function home_url( string $path = '' ): string {
		return 'https://events.example' . $path;
	}
	function add_query_arg( array $args, string $url ): string {
		return $url . '?' . http_build_query( $args );
	}
	function wp_login_url( string $redirect ): string {
		return 'https://events.example/wp-login.php?redirect_to=' . rawurlencode( $redirect );
	}
	function is_user_logged_in(): bool {
		return 'logged_out' !== $GLOBALS['venue_archive_role'];
	}
	function get_current_user_id(): int {
		return is_user_logged_in() ? 2 : 0;
	}
	function is_wp_error( $value ): bool {
		return false;
	}
	function do_blocks( string $blocks ): string {
		if ( false !== strpos( $blocks, 'data-machine-events/calendar' ) ) {
			return '<section data-machine-events-calendar>Upcoming shows</section>';
		}
		return '<section id="booking-inquiry"><h2>Booking at The Room</h2></section>';
	}

	require_once dirname( __DIR__, 2 ) . '/inc/core/booking-console.php';
	require dirname( __DIR__, 2 ) . '/inc/templates/archive.php';
}
