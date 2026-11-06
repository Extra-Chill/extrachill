<?php
/**
 * Smoke test: email contact social type and invalid-type error detail.
 *
 * Run: php tests/email-social-type-smoke.php
 *
 * @package ExtraChillLinkPages
 */

define( 'ABSPATH', __DIR__ );

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = null ) {
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
	public function get_error_data() {
		return $this->data;
	}
}
function is_wp_error( $v ) {
	return $v instanceof WP_Error;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function sanitize_key( $k ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) );
}
function sanitize_text_field( $s ) {
	return trim( strip_tags( $s ) );
}
function wp_unslash( $s ) {
	return stripslashes( $s );
}
function sanitize_email( $e ) {
	return preg_replace( '/[^a-zA-Z0-9@._+\-]/', '', $e );
}
function is_email( $e ) {
	return false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false;
}
function esc_url_raw( $url, $protocols = null ) {
	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	return in_array( $scheme, (array) $protocols, true ) ? $url : '';
}
function esc_url( $url ) {
	return htmlspecialchars( $url, ENT_QUOTES );
}
function esc_attr( $s ) {
	return htmlspecialchars( $s, ENT_QUOTES );
}
function esc_html( $s ) {
	return htmlspecialchars( $s, ENT_QUOTES );
}

require dirname( __DIR__ ) . '/inc/social-types.php';

$failures = 0;
function check( $cond, $msg ) {
	global $failures;
	if ( ! $cond ) {
		++$failures;
		fwrite( STDERR, "FAIL: $msg\n" );
	}
}

$types = ec_social_link_types();
check( isset( $types['email'] ) && 'fas fa-envelope' === $types['email']['icon'], 'email type registered with envelope icon' );

$bare = ec_sanitize_social_links( array( array( 'type' => 'email', 'url' => 'chrisgardner@extrachill.com' ) ) );
check( ! is_wp_error( $bare ) && 'mailto:chrisgardner@extrachill.com' === $bare[0]['url'], 'bare address stored as mailto:' );

$mailto = ec_sanitize_social_links( array( array( 'type' => 'email', 'url' => 'mailto:chrisgardner@extrachill.com' ) ) );
check( ! is_wp_error( $mailto ) && 'mailto:chrisgardner@extrachill.com' === $mailto[0]['url'], 'mailto: kept' );

$bad = ec_sanitize_social_links( array( array( 'type' => 'email', 'url' => 'not-an-email' ) ) );
check( is_wp_error( $bad ) && 'invalid_social_url' === $bad->get_error_code(), 'invalid address rejected' );

$https = ec_sanitize_social_links( array( array( 'type' => 'email', 'url' => 'https://example.com' ) ) );
check( is_wp_error( $https ), 'web URL rejected for email type' );

$web = ec_sanitize_social_links( array( array( 'type' => 'website', 'url' => 'mailto:a@b.com' ) ) );
check( is_wp_error( $web ), 'mailto: still rejected for non-email types' );

$unknown = ec_sanitize_social_links( array( array( 'type' => 'myspace', 'url' => 'https://myspace.com/x' ) ) );
check( is_wp_error( $unknown ) && 'invalid_social_type' === $unknown->get_error_code(), 'unknown type rejected' );
check( is_wp_error( $unknown ) && false !== strpos( $unknown->get_error_message(), 'email' ) && false !== strpos( $unknown->get_error_message(), 'instagram' ), 'unknown type message lists valid types' );
$data = is_wp_error( $unknown ) ? $unknown->get_error_data() : array();
check( isset( $data['valid_types'] ) && in_array( 'email', $data['valid_types'], true ), 'unknown type error data lists valid types' );

$html = ec_render_social_links( $bare );
check( false !== strpos( $html, 'href="mailto:chrisgardner@extrachill.com"' ) && false !== strpos( $html, 'fas fa-envelope' ), 'email renders as mailto link with envelope icon' );

if ( $failures ) {
	exit( 1 );
}
echo "Email social type smoke passed.\n";
