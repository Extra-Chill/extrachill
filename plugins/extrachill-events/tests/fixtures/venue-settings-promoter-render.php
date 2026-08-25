<?php
/**
 * Standalone promoter-mode venue settings render fixture.
 *
 * @package ExtraChillEvents\Tests
 */

namespace ExtraChillEvents\Core {
	final class BookingSchema {
		public static function is_ready(): bool {
			return true;
		}
	}
	final class LocalSupportSchema {
		public static function is_ready(): bool {
			++$GLOBALS['venue_settings_render_fixture']['private_loader_calls'];
			return true;
		}
	}
	final class LocalSupportWorkspace {}
	final class VenueMembershipRepository {}
	final class VenueAuthorization {
		public function is_administrator( int $user_id ): bool {
			unset( $user_id );
			return true;
		}
	}
	final class PromoterWorkspace {
		public function __construct( $promoters = null, $grants = null, $venues = null, bool $use_execution_principal = true ) {
			unset( $promoters, $grants, $venues );
			$GLOBALS['venue_settings_render_fixture']['uses_principal'] = $use_execution_principal;
		}
		public function resolve_for_user( int $user_id, string $reference ) {
			$GLOBALS['venue_settings_render_fixture']['workspace_user_id'] = $user_id;
			if ( ! preg_match( '/^promoter:[1-9][0-9]{0,9}$/', $reference ) ) {
				return new \WP_Error( 'invalid_promoter_workspace_identity' );
			}
			return array(
				'actor'                  => array(
					'id'   => $user_id,
					'name' => 'Browser User',
				),
				'identities'             => array(),
				'selection'              => array(
					'reference' => $reference,
					'type'      => 'promoter',
					'id'        => 30,
					'state'     => 'active',
				),
				'promoter'               => null,
				'venue'                  => null,
				'granted_venues'         => array(),
				'promoter_relationships' => array(),
			);
		}
	}
	final class PromoterAuthorization {
		public static function effective_user_id(): int {
			return 99;
		}
	}
}

namespace {
	final class WP_Error {
		public $code;

		public function __construct( string $code ) {
			$this->code = $code;
		}
	}

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	$GLOBALS['venue_settings_render_fixture'] = array(
		'private_loader_calls' => 0,
		'workspace_user_id'    => 0,
		'uses_principal'       => true,
	);
	$_GET['identity']                         = $argv[1] ?? 'promoter:30';

	function get_block_wrapper_attributes(): string {
		return '';
	}
	function is_user_logged_in(): bool {
		return true;
	}
	function get_current_user_id(): int {
		return 2;
	}
	function wp_get_current_user() {
		return (object) array( 'display_name' => 'Browser User' );
	}
	function get_userdata( $user_id ) {
		return (object) array(
			'ID'           => $user_id,
			'display_name' => 'Browser User',
		);
	}
	function get_terms() {
		++$GLOBALS['venue_settings_render_fixture']['private_loader_calls'];
		return array();
	}
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
	function wp_unslash( $value ) {
		return $value;
	}
	function sanitize_text_field( $value ): string {
		return (string) $value;
	}
	function absint( $value ): int {
		return abs( (int) $value );
	}
	function home_url( $path = '' ): string {
		return 'https://events.example' . $path;
	}
	function wp_unique_id( $prefix = '' ): string {
		return $prefix . '1';
	}
	function wp_json_encode( $value, $flags = 0 ): string {
		return json_encode( $value, $flags );
	}
	function esc_attr( $value ): string {
		return (string) $value;
	}
	function esc_html_e( $value ): void {
		echo $value;
	}
	function esc_html( $value ): string {
		return (string) $value;
	}
	function esc_url( $value ): string {
		return (string) $value;
	}

	ob_start();
	include dirname( __DIR__, 2 ) . '/blocks/venue-settings/render.php';
	ob_end_clean();

	echo json_encode(
		array(
			'context' => $context,
			'calls'   => $GLOBALS['venue_settings_render_fixture'],
		)
	);
}
