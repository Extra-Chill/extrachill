<?php
/**
 * RSVP pass QR / verify URL builders (slice 2, #877).
 *
 * Pure-PHP unit test matching this repo's qualify-v2-unit convention: the
 * handful of WordPress URL-building functions these two helpers call are
 * stubbed faithfully to their real behavior for the exact call shapes used,
 * not the full WP test framework (the actual QR-serving endpoint,
 * extrachill_events_serve_rsvp_pass_qr(), calls exit() on every path and is
 * therefore not safely callable from a pure-PHP test process — verified by
 * code review instead; see this file's own docblock and the PR body).
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP URL-function shims with the test case class.

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://events.extrachill.com' . $path;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://events.extrachill.com/wp-admin/' . $path;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Faithful-enough stub for the two call shapes this file's helpers use:
	 * add_query_arg( $key, $value, $url ) and add_query_arg( $args, $url ).
	 */
	function add_query_arg( ...$args ): string {
		if ( 2 === count( $args ) && is_array( $args[0] ) ) {
			$params = $args[0];
			$url    = $args[1];
			$pairs  = array();
			foreach ( $params as $k => $v ) {
				$pairs[] = $k . '=' . $v;
			}
			$sep = false === strpos( $url, '?' ) ? '?' : '&';
			return $url . $sep . implode( '&', $pairs );
		}
		list( $key, $value, $url ) = $args;
		$sep                       = false === strpos( $url, '?' ) ? '?' : '&';
		return $url . $sep . $key . '=' . $value;
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/rsvp-pass-qr.php';

final class RsvpPassQrUrlsTest extends PHPUnit\Framework\TestCase {

	public function test_verify_url_encodes_the_code_and_targets_the_verify_route(): void {
		$url = extrachill_events_rsvp_verify_url( 'ABCDE-FGH2J-K3LMN' );

		$this->assertSame(
			'https://events.extrachill.com/rsvp-verify/?code=ABCDE-FGH2J-K3LMN',
			$url
		);
	}

	public function test_verify_url_rawurlencodes_special_characters(): void {
		$url = extrachill_events_rsvp_verify_url( 'A B&C' );

		$this->assertStringContainsString( 'code=A%20B%26C', $url );
	}

	public function test_qr_url_targets_admin_post_with_the_dedicated_action(): void {
		$url = extrachill_events_rsvp_pass_qr_url( 'ABCDE-FGH2J-K3LMN' );

		$this->assertStringStartsWith( 'https://events.extrachill.com/wp-admin/admin-post.php?', $url );
		$this->assertStringContainsString( 'action=ec_rsvp_pass_qr', $url );
		$this->assertStringContainsString( 'code=ABCDE-FGH2J-K3LMN', $url );
	}

	public function test_qr_url_with_an_empty_code_is_a_valid_base_for_client_side_concatenation(): void {
		// assets/js/rsvp-pass.js appends encodeURIComponent(code) to this
		// base URL after a live pass refresh; the base itself must end in
		// `code=` with nothing after it.
		$url = extrachill_events_rsvp_pass_qr_url( '' );

		$this->assertStringEndsWith( 'code=', $url );
	}
}
