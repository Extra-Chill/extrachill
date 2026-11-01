<?php
// phpcs:disable -- Purpose-built WordPress runtime stubs must share one isolated fixture.
/**
 * Regression fixture for #899: a Link Pages runtime contract mismatch (any
 * reason `VenueLinkPagesProvider::validate_runtime()` fails) leaves
 * `\ExtraChillEvents\Core\VenueLinkPages` unloaded. Before the fix,
 * `blocks/venue-settings/render.php` referenced that class unconditionally
 * whenever `ec_link_page_editor_is_available()` was true, fataling
 * /venue-settings/ for every venue team member. This fixture reproduces that
 * exact precondition — the Link Pages editor is available, but
 * `\ExtraChillEvents\Core\VenueLinkPages` is deliberately never loaded — and
 * asserts render.php degrades the Link Page tab to 'unavailable' instead of
 * fataling.
 *
 * @package ExtraChillEvents\Tests
 */

namespace ExtraChillEvents\Core {
	final class VenueSchema {
		public static function is_ready(): bool {
			return true;
		}
	}
	final class VenueBookingEmbed {
		public static function booking_url( $term ) { return 'https://events.example/booking/' . $term->slug . '/'; }
	}
	final class VenueMembershipRepository {
		public function list_active_venue_ids() {
			return array( 30 );
		}
	}
	final class VenueAuthorization {
		public const ACTION_ACCESS_VENUE   = 'access_venue';
		public const ACTION_MANAGE_MEMBERS = 'manage_members';
		public function is_administrator( int $user_id ): bool {
			unset( $user_id );
			return true;
		}
		public function authorize( int $user_id, int $venue_term_id, string $action ) {
			unset( $user_id, $venue_term_id, $action );
			// Denied is sufficient: the fix under test is whether the render
			// completes at all, not the access-gated booking URL branch.
			return true;
		}
	}
	final class PromoterWorkspace {
		public function __construct( $promoters = null, $grants = null, $venues = null, bool $use_execution_principal = true ) {
			unset( $promoters, $grants, $venues, $use_execution_principal );
		}
		public function resolve_for_user( int $user_id, string $reference ) {
			unset( $user_id, $reference );
			return new \WP_Error( 'venue_workspace_unavailable' );
		}
	}
	// Deliberately absent: \ExtraChillEvents\Core\VenueLinkPages. This is the
	// exact state VenueLinkPagesProvider::initialize() leaves behind when
	// validate_runtime() fails (#899's root cause).
}

namespace {
	final class WP_Error {
		public $code;
		public function __construct( string $code ) {
			$this->code = $code;
		}
	}
	final class WP_Term {
		public $term_id;
		public $name;
		public $slug;
		public function __construct( int $term_id, string $name, string $slug ) {
			$this->term_id = $term_id;
			$this->name    = $name;
			$this->slug    = $slug;
		}
	}

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );

	function get_block_wrapper_attributes(): string {
		return ''; }
	function is_user_logged_in(): bool {
		return $GLOBALS['logged_in']; }
	function get_current_user_id(): int {
		return 10; }
	function wp_get_current_user() {
		return (object) array( 'display_name' => 'Venue Owner' ); }
	function get_userdata( $user_id ) {
		return (object) array(
			'ID'           => $user_id,
			'display_name' => 'Venue Owner',
		);
	}
	function get_terms() {
		return array( new WP_Term( 30, 'The Royal American', 'the-royal-american' ) ); }
	function get_term( $term_id, $taxonomy = '' ) {
		unset( $taxonomy );
		return 30 === (int) $term_id ? new WP_Term( 30, 'The Royal American', 'the-royal-american' ) : null;
	}
	function get_term_link( $term ) {
		return 'https://events.example/venue/' . $term->slug . '/'; }
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error; }
	function wp_unslash( $value ) {
		return $value; }
	function sanitize_text_field( $value ): string {
		return (string) $value; }
	function absint( $value ): int {
		return abs( (int) $value ); }
	function home_url( $path = '' ): string {
		return 'https://events.example' . $path; }
	function wp_unique_id( $prefix = '' ): string {
		return $prefix . '1'; }
	function wp_json_encode( $value, $flags = 0 ): string {
		return json_encode( $value, $flags ); }
	function esc_attr( $value ): string {
		return (string) $value; }
	function esc_html_e( $value ): void {
		echo $value; }
	function esc_html( $value ): string {
		return (string) $value; }
	function esc_url( $value ): string {
		return (string) $value; }
	function add_action( ...$args ) { return true; }
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ): bool {
		$GLOBALS['filters'][$hook][] = array( $callback, $args );
		return true;
	}
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['filters'][$hook] ?? array() as [$callback, $count] ) {
			$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $count ) );
		}
		return $value;
	}
	function wp_login_url( $url ) { return 'https://events.example/login?redirect_to=' . rawurlencode( $url ); }
	function add_query_arg( $args, $url ) { return $url . '&' . http_build_query( $args ); }
	function ec_events_get_venue_settings_url( $id, $tab = '' ) { return 'https://events.example/venue-settings/?venue_id=' . $id . '&tab=' . $tab; }
	require_once dirname( __DIR__, 2 ) . '/inc/core/booking-console.php';
	function get_site_option( $name, $default = false ) {
		unset( $name );
		return $default; }

	// Load the real network blog-id contract (ec_get_site_url()). Homeboy
	// exports EXTRACHILL_NETWORK_PATH when extrachill-network is a validation
	// dependency; otherwise fall back to the monorepo sibling checkout.
	$ec_network_path = getenv( 'EXTRACHILL_NETWORK_PATH' );
	if ( ! is_string( $ec_network_path ) || '' === $ec_network_path ) {
		$ec_network_path = dirname( __DIR__, 3 ) . '/extrachill-network';
	}
	require_once rtrim( $ec_network_path, '/' ) . '/inc/core/blog-ids.php';

	// The exact production precondition from #899: the Link Pages plugin's own
	// editor assets are available (its runtime loaded fine), independent of
	// whether Events' own VenueLinkPagesProvider successfully validated and
	// loaded VenueLinkPages against it.
	function ec_link_page_editor_is_available(): bool {
		return true; }
	function ec_get_link_page_id_for_owner( $reference ) {
		unset( $reference );
		// Must never actually run: class_exists() guards this call. If render.php
		// regresses to calling VenueLinkPages unconditionally again, PHP fatals
		// on the class-not-found error before this stub is ever reached.
		return 999;
	}

	function render_page( $path ) {
		ob_start();
		include $path;
		return ob_get_clean();
	}
	foreach ( array( false, true ) as $logged_in ) {
		foreach ( array( array(), array( 'venue_id' => 30 ), array( 'booking_id' => 42, 'booking_venue_id' => 30 ), array( 'venue_id' => 30, 'booking_id' => 42 ), array( 'identity' => 'promoter:5', 'booking_id' => 42 ) ) as $query ) {
			$_GET = $query;
			$GLOBALS['filters'] = array();
			$before = render_page( __DIR__ . '/venue-settings-render-p1d-before.php' );
			require_once dirname( __DIR__, 2 ) . '/inc/core/booking-venue-settings.php';
			$GLOBALS['filters'] = array();
			foreach ( array( 'login_redirect' => 2, 'venue_data' => 2, 'boot_data' => 3 ) as $suffix => $count ) {
				add_filter( 'extrachill_events_venue_settings_' . $suffix, 'ExtraChillEvents\\Booking\\venue_settings_' . $suffix, 10, $count );
			}
			$after = render_page( dirname( __DIR__, 2 ) . '/blocks/venue-settings/render.php' );
			if ( $before !== $after ) {
				fwrite( STDERR, "Render bytes changed: " . json_encode( array( $logged_in, $query ) ) . "\n" );
				exit( 1 );
			}
		}
	}
	echo "Venue settings render bytes unchanged.\n";
}
