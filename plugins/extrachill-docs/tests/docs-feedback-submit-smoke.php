<?php
/**
 * Standalone smoke coverage for the feedback form and ability.
 *
 * Run with: php tests/docs-feedback-submit-smoke.php
 *
 * @package ExtraChillDocs
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_Post {
	public function __construct( public int $ID, public int $post_parent, public string $post_status = 'publish', public string $post_type = 'page', public string $post_password = '' ) {}
}

class WP_Error {
	public function __construct( public string $code, public string $message, public array $data ) {}
}

$posts = array(
	1 => new WP_Post( 1, 0 ),
	2 => new WP_Post( 2, 1 ),
	3 => new WP_Post( 3, 1, 'draft' ),
	4 => new WP_Post( 4, 1, 'publish', 'post' ),
	5 => new WP_Post( 5, 1, 'publish', 'page', 'secret' ),
	6 => new WP_Post( 6, 1, 'private' ),
);
$blog_id       = 10;
$queried       = 2;
$user          = (object) array( 'ID' => 0 );
$options       = array( 'admin_email' => 'docs-admin@example.test' );
$override      = null;
$mail          = array();
$mail_ok       = true;
$cache         = array();
$verified      = array();
$render_widget = true;

function add_action( $hook, $callback ) { $GLOBALS['hooks'][ $hook ][] = $callback; }
function wp_register_ability( $name, $args ) { $GLOBALS['abilities'][ $name ] = $args; }
function __( $text, $domain ) { return $text; }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_html_e( $text, $domain ) { echo esc_attr( $text ); }
function esc_attr_e( $text, $domain ) { echo esc_attr( $text ); }
function esc_url( $url ) { return esc_attr( $url ); }
function esc_url_raw( $url ) { return $url; }
function trailingslashit( $text ) { return rtrim( $text, '/' ) . '/'; }
function rest_url( $path ) { return 'https://docs.example.test/wp-json/' . $path; }
function wp_create_nonce( $action ) { return 'nonce-for-' . $action; }
function ec_get_blog_id( $site ) { return 'docs' === $site ? 10 : 2; }
function ec_get_site_url( $site ) { return 'https://' . $site . '.example.test'; }
function get_current_blog_id() { return $GLOBALS['blog_id']; }
function get_option( $key ) { return $GLOBALS['options'][ $key ] ?? false; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function is_admin() { return false; }
function is_front_page() { return 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) === get_queried_object_id(); }
function is_singular( $type ) { return $type === ( get_queried_object()->post_type ?? null ); }
function get_queried_object_id() { return $GLOBALS['queried']; }
function get_queried_object() { return get_post( get_queried_object_id() ); }
function wp_get_current_user() { return $GLOBALS['user']; }
function is_user_logged_in() { return 0 !== wp_get_current_user()->ID; }
function extrachill_get_user_profile_url( $id ) { return 'https://community.example.test/u/' . $id; }
function get_the_title( $post ) { return 'Connect <b>your account</b>'; }
function get_permalink( $post ) { return 'https://docs.example.test/account/connect/'; }
function sanitize_textarea_field( $text ) { return trim( strip_tags( $text ) ); }
function sanitize_text_field( $text ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( $text ) ) ); }
function is_email( $email ) { return filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function sanitize_email( $email ) { return $email; }
function wp_salt() { return 'smoke-test-salt'; }
function wp_unslash( $text ) { return stripslashes( $text ); }
function get_transient( $key ) { return $GLOBALS['cache'][ $key ]['value'] ?? false; }
function set_transient( $key, $value, $ttl ) {
	$GLOBALS['cache'][ $key ] = array( 'value' => $value, 'ttl' => $ttl );
	return true;
}
function ec_render_turnstile_widget( $args ) {
	return $GLOBALS['render_widget'] ? '<div class="cf-turnstile" id="' . esc_attr( $args['id'] ) . '"></div>' : '';
}
function ec_verify_turnstile_response( $token ) {
	$GLOBALS['verified'][] = $token;
	return 'good-token' === $token;
}
function apply_filters( $hook, $default, $post_id ) {
	if ( 'extrachill_docs_feedback_recipient' === $hook ) {
		$GLOBALS['filtered_post_id'] = $post_id;
		return $GLOBALS['override'] ?? $default;
	}
	return $default;
}
function wp_mail( $to, $subject, $body, $headers ) {
	$GLOBALS['mail'][] = compact( 'to', 'subject', 'body', 'headers' );
	return $GLOBALS['mail_ok'];
}

$dir = dirname( __DIR__ );
require $dir . '/inc/core/feedback.php';
require $dir . '/inc/abilities/docs-feedback-submit.php';

function check_feedback( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}
function check_feedback_error( $result, $code, $status ) {
	check_feedback( $result instanceof WP_Error && $code === $result->code && $status === $result->data['status'], "expected {$status} {$code}" );
}

foreach ( $hooks['wp_abilities_api_init'] as $callback ) {
	$callback();
}
$ability = $abilities['extrachill/docs-feedback-submit'];
check_feedback( true === $ability['meta']['show_in_rest'], 'ability is REST callable' );
$submit = $ability['execute_callback'];
$input  = array( 'post_id' => 2, 'message' => "<b>Where</b> do I connect?\nOn mobile?", 'turnstile_response' => 'good-token' );

foreach ( array( 0, 1, 3, 4, 5, 6, 999 ) as $not_article ) {
	check_feedback_error( $submit( array_replace( $input, array( 'post_id' => $not_article ) ) ), 'docs_feedback_invalid_article', 404 );
	$queried = $not_article;
	check_feedback( '' === extrachill_docs_feedback_markup(), "no feedback form for post {$not_article}" );
}
$queried = 2;
$blog_id = 2;
check_feedback_error( $submit( $input ), 'docs_feedback_invalid_article', 404 );
check_feedback( '' === extrachill_docs_feedback_markup(), 'form does not render on a different site' );
$blog_id = 10;
$options['show_on_front'] = 'page';
$options['page_on_front'] = 2;
check_feedback_error( $submit( $input ), 'docs_feedback_invalid_article', 404 );
check_feedback( '' === extrachill_docs_feedback_markup(), 'no form on the homepage even if it is a child' );
unset( $options['show_on_front'], $options['page_on_front'] );

foreach ( array( '', '   ', '<b></b>', str_repeat( 'x', 2001 ) ) as $bad_message ) {
	check_feedback_error( $submit( array_replace( $input, array( 'message' => $bad_message ) ) ), 'docs_feedback_invalid_message', 400 );
}
foreach ( array( 'not-an-email', "visitor@example.test\r\nBcc: victim@example.test" ) as $bad_email ) {
	check_feedback_error( $submit( $input + array( 'email' => $bad_email ) ), 'docs_feedback_invalid_email', 400 );
}
check_feedback( ! $verified && ! $mail, 'validation rejects before CAPTCHA and mail' );

$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
check_feedback_error( $submit( array_replace( $input, array( 'turnstile_response' => '' ) ) ), 'docs_feedback_turnstile_failed', 403 );
check_feedback_error( $submit( array_replace( $input, array( 'turnstile_response' => 'bad-token' ) ) ), 'docs_feedback_turnstile_failed', 403 );
check_feedback( array( 'bad-token' ) === $verified && ! $mail, 'only nonempty tokens reach Turnstile; failed verification never sends mail' );

check_feedback( array( 'success' => true ) === $submit( $input ), 'guest submission succeeds without email' );
check_feedback( 'docs-admin@example.test' === $mail[0]['to'] && 2 === $filtered_post_id, 'recipient defaults to the docs admin and filter receives the article ID' );
check_feedback( array( 'Content-Type: text/plain; charset=UTF-8' ) === $mail[0]['headers'], 'anonymous mail has no Reply-To' );
check_feedback( 'Docs feedback: Connect your account' === $mail[0]['subject'], 'subject uses the sanitized article title' );
check_feedback( "Page: Connect your account\nURL: https://docs.example.test/account/connect/\n\nWhere do I connect?\nOn mobile?" === $mail[0]['body'], 'body uses server-derived article details and sanitized multiline feedback' );

$override = 'maintainer@example.test';
$submit( $input + array( 'email' => 'visitor@example.test' ) );
check_feedback( $override === $mail[1]['to'] && in_array( 'Reply-To: visitor@example.test', $mail[1]['headers'], true ), 'recipient override and guest Reply-To are honored' );

$mail_ok = false;
check_feedback_error( $submit( $input ), 'docs_feedback_mail_failed', 500 );
$mail_ok = true;
check_feedback_error( $submit( $input ), 'docs_feedback_rate_limited', 429 );
check_feedback( 3 === count( $mail ) && 4 === count( $verified ), 'sixth attempt is blocked before Turnstile and mail' );
$entry = reset( $cache );
check_feedback( $entry['ttl'] > 0 && $entry['ttl'] <= HOUR_IN_SECONDS, 'rate limit expires within an hour' );
$key = array_key_first( $cache );
$cache[ $key ]['value']['expires'] = time() - 1;
check_feedback( array( 'success' => true ) === $submit( $input ), 'expired rate limit resets' );
$_SERVER['REMOTE_ADDR'] = '192.0.2.2';
check_feedback( array( 'success' => true ) === $submit( $input ), 'another guest IP has its own allowance' );

$guest_markup = extrachill_docs_feedback_markup();
check_feedback( str_contains( $guest_markup, 'data-docs-feedback-reply="no" hidden' ) && str_contains( $guest_markup, '<form' ), 'No form is initially hidden on a child article' );
check_feedback( str_contains( $guest_markup, 'required maxlength="2000"' ) && str_contains( $guest_markup, 'name="email" autocomplete' ), 'guest form has a bounded required message and optional email' );
check_feedback( str_contains( $guest_markup, 'cf-turnstile' ) && str_contains( $guest_markup, '/wp-abilities/v1/abilities/extrachill/docs-feedback-submit/run' ), 'form renders the network widget and targets core REST' );
check_feedback( ! str_contains( $guest_markup, 'nonce-for-' ), 'guests do not receive a REST nonce' );

$user = (object) array( 'ID' => 7, 'display_name' => '<b>Docs Reader</b>', 'user_email' => 'account@example.test' );
check_feedback( array( 'success' => true ) === $submit( $input + array( 'email' => 'ignored@example.test' ) ), 'logged-in submission succeeds' );
$last_mail = end( $mail );
check_feedback( in_array( 'Reply-To: account@example.test', $last_mail['headers'], true ), 'account address overrides supplied email' );
check_feedback( str_contains( $last_mail['body'], "From: Docs Reader\nProfile: https://community.example.test/u/7" ), 'logged-in name and profile link are included' );
$member_markup = extrachill_docs_feedback_markup();
check_feedback( ! str_contains( $member_markup, 'name="email"' ) && str_contains( $member_markup, 'data-nonce="nonce-for-wp_rest"' ), 'member form hides email and carries a REST nonce' );

$render_widget = false;
$fallback = extrachill_docs_feedback_markup();
check_feedback( ! str_contains( $fallback, '<form' ) && str_contains( $fallback, '/r/tech-support' ), 'unconfigured Turnstile leaves the support link instead of a dead form' );

// The source may only use existing theme classes. The rendered widget adds
// its own behavioral class, which is owned by the network plugin, not the theme.
$allowed_classes = array( 'button-1', 'button-2', 'button-small', 'ec-form-stack', 'notice', 'notice-success', 'notice-error' );
$source = file_get_contents( $dir . '/inc/core/feedback.php' );
preg_match_all( '/\bclass="([^"]+)"/', $source, $classes );
check_feedback( ! empty( $classes[1] ), 'class allow-list matched the source markup' );
foreach ( $classes[1] as $class_list ) {
	foreach ( preg_split( '/\s+/', trim( $class_list ) ) as $class ) {
		check_feedback( in_array( $class, $allowed_classes, true ), "unexpected class {$class}" );
	}
}

echo "Docs feedback submission smoke passed.\n";
