<?php
/**
 * Concert stats public-profile render regression tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

// WordPress stubs intentionally share this test file with its test class.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.Security.EscapeOutput.OutputNotEscaped

if ( ! function_exists( 'is_page' ) ) {
	function is_page( $slug ) {
		return 'my-shows' === $slug;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return $GLOBALS['ec_test_current_user_id'];
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return $GLOBALS['ec_test_current_user_id'] > 0;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return 'manage_network_options' === $capability && ! empty( $GLOBALS['ec_test_is_network_admin'] );
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return in_array( (int) $user_id, $GLOBALS['ec_test_existing_user_ids'], true ) ? (object) array( 'ID' => (int) $user_id ) : false;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'get_block_wrapper_attributes' ) ) {
	function get_block_wrapper_attributes( $attributes = array() ) {
		$rendered = array();
		foreach ( $attributes as $name => $value ) {
			$rendered[] = $name . '="' . htmlspecialchars( (string) $value, ENT_QUOTES ) . '"';
		}
		return implode( ' ', $rendered );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text ) {
		echo htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://events.example' . $path;
	}
}

if ( ! function_exists( 'wp_registration_url' ) ) {
	function wp_registration_url() {
		return 'https://events.example/register/';
	}
}

if ( ! function_exists( 'wp_login_url' ) ) {
	function wp_login_url() {
		return 'https://events.example/login/';
	}
}

if ( ! function_exists( 'do_blocks' ) ) {
	function do_blocks( $content ) {
		return '<div class="embedded-block">' . htmlspecialchars( $content, ENT_QUOTES ) . '</div>';
	}
}

if ( ! function_exists( 'current_datetime' ) ) {
	function current_datetime() {
		return new DateTimeImmutable( '2026-07-19 12:00:00' );
	}
}

final class ConcertStatsPublicProfileRenderTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test_current_user_id']   = 0;
		$GLOBALS['ec_test_existing_user_ids'] = array( 12, 34 );
		$GLOBALS['ec_test_is_network_admin']  = false;
		$GLOBALS['test_is_user_logged_in']    = false;
		$_GET                                 = array();
	}

	protected function tearDown(): void {
		$_GET = array();
	}

	public function test_owner_gets_dashboard_and_owner_only_embeds(): void {
		$GLOBALS['ec_test_current_user_id'] = 12;
		$GLOBALS['test_is_user_logged_in']  = true;

		$output = $this->renderBlock();

		$this->assertStringContainsString( 'data-user-id="12"', $output );
		$this->assertStringContainsString( 'data-is-own="1"', $output );
		$this->assertStringContainsString( 'data-public-date-to=""', $output );
		$this->assertStringContainsString( 'data-has-calendar="1"', $output );
		$this->assertStringContainsString( 'data-has-map="1"', $output );
		$this->assertStringContainsString( 'ec-concert-stats__embedded-calendar', $output );
		$this->assertStringContainsString( 'ec-concert-stats__embedded-map', $output );
	}

	public function test_logged_in_viewer_gets_selected_users_public_history(): void {
		$GLOBALS['ec_test_current_user_id'] = 12;
		$GLOBALS['test_is_user_logged_in']  = true;
		$_GET['user_id']                    = '34';

		$output = $this->renderBlock();

		$this->assertStringContainsString( 'data-user-id="34"', $output );
		$this->assertStringContainsString( 'data-is-own="0"', $output );
		$this->assertStringContainsString( 'data-public-date-to="2026-07-18"', $output );
		$this->assertStringContainsString( 'data-has-calendar="0"', $output );
		$this->assertStringContainsString( 'data-has-map="0"', $output );
		$this->assertStringNotContainsString( 'ec-concert-stats__embedded-calendar', $output );
		$this->assertStringNotContainsString( 'ec-concert-stats__embedded-map', $output );
	}

	public function test_network_admin_does_not_receive_another_users_owner_ui(): void {
		$GLOBALS['ec_test_current_user_id']  = 12;
		$GLOBALS['ec_test_is_network_admin'] = true;
		$_GET['user_id']                     = '34';

		$output = $this->renderBlock();

		$this->assertStringContainsString( 'data-user-id="34"', $output );
		$this->assertStringContainsString( 'data-is-own="0"', $output );
		$this->assertStringContainsString( 'data-has-calendar="0"', $output );
		$this->assertStringContainsString( 'data-has-map="0"', $output );
		$this->assertStringNotContainsString( 'ec-concert-stats__embedded-calendar', $output );
		$this->assertStringNotContainsString( 'ec-concert-stats__embedded-map', $output );
	}

	public function test_logged_out_viewer_gets_selected_users_public_history(): void {
		$_GET['user_id'] = '34';

		$output = $this->renderBlock();

		$this->assertStringContainsString( 'data-user-id="34"', $output );
		$this->assertStringContainsString( 'data-is-own="0"', $output );
		$this->assertStringContainsString( 'data-public-date-to="2026-07-18"', $output );
		$this->assertStringNotContainsString( 'ec-concert-stats-shell--marketing', $output );
	}

	public function test_invalid_selection_does_not_fall_back_to_viewer(): void {
		$GLOBALS['ec_test_current_user_id'] = 12;
		$GLOBALS['test_is_user_logged_in']  = true;
		$_GET['user_id']                    = '999';

		$output = $this->renderBlock();

		$this->assertStringContainsString( 'This concert history could not be found.', $output );
		$this->assertStringNotContainsString( 'data-user-id="12"', $output );
		$this->assertStringNotContainsString( 'class="ec-concert-stats"', $output );
	}

	public function test_malformed_selection_fails_safely(): void {
		$GLOBALS['ec_test_current_user_id'] = 12;
		$GLOBALS['test_is_user_logged_in']  = true;
		$_GET['user_id']                    = array( '34' );

		$output = $this->renderBlock();

		$this->assertStringContainsString( 'This concert history could not be found.', $output );
		$this->assertStringNotContainsString( 'data-user-id="12"', $output );
	}

	private function renderBlock(): string {
		$attributes = array();

		ob_start();
		include dirname( __DIR__, 2 ) . '/blocks/concert-stats/render.php';
		return (string) ob_get_clean();
	}
}
