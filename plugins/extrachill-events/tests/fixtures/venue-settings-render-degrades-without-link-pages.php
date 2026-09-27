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
	final class BookingSchema {
		public static function is_ready(): bool {
			return true;
		}
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
			return new \WP_Error( 'venue_action_forbidden' );
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
		return true; }
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

	ob_start();
	include dirname( __DIR__, 2 ) . '/blocks/venue-settings/render.php';
	ob_end_clean();

	echo json_encode(
		array(
			'link_page_status' => $context['venues'][0]['link_page']['status'] ?? null,
			'venue_count'       => count( $context['venues'] ?? array() ),
		)
	);
}
