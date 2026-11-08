<?php
/**
 * Standalone tests for the Link Page editor hint bounce.
 *
 * @package ExtraChillNetwork
 */

declare( strict_types=1 );

// phpcs:disable -- WordPress standalone mocks intentionally share one file.

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['hint_hooks']   = array();
$GLOBALS['hint_filters'] = array( 'ec_link_page_public_host' => 'Extrachill.Link' );

function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hint_hooks'][ $hook ] = array( $callback, $priority ); }
function apply_filters( $hook, $value ) { return $GLOBALS['hint_filters'][ $hook ] ?? $value; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_unslash( $value ) { return $value; }

require dirname( __DIR__ ) . '/inc/link-pages/editor-hint.php';

$failures = 0;
function check( bool $ok, string $label ): void {
	global $failures;
	if ( ! $ok ) {
		++$failures;
		echo "FAIL: {$label}\n";
	}
}

$artist = array(
	'method'       => 'GET',
	'frontend'     => true,
	'public_host'  => false,
	'primed'       => false,
	'logged_in'    => true,
	'artist_count' => 1,
);
check( ec_network_link_editor_hint_should_prime( $artist ), 'Signed-in artist on a network page view is primed once.' );
check( ! ec_network_link_editor_hint_should_prime( array( 'primed' => true ) + $artist ), 'Already primed browsers are not bounced again.' );
check( ! ec_network_link_editor_hint_should_prime( array( 'logged_in' => false ) + $artist ), 'Anonymous visitors are never bounced.' );
check( ! ec_network_link_editor_hint_should_prime( array( 'artist_count' => 0 ) + $artist ), 'Signed-in fans and admins without an artist are never bounced.' );
check( ! ec_network_link_editor_hint_should_prime( array( 'method' => 'POST' ) + $artist ), 'Form submissions are never bounced.' );
check( ! ec_network_link_editor_hint_should_prime( array( 'frontend' => false ) + $artist ), 'Admin, REST, ajax, feeds and previews are never bounced.' );
check( ! ec_network_link_editor_hint_should_prime( array( 'public_host' => true ) + $artist ), 'The public Link Page host never bounces to itself.' );
check( ! ec_network_link_editor_hint_should_prime( array() ), 'Missing facts default to no bounce.' );

check( 'extrachill.link' === ec_network_link_editor_hint_host(), 'Public host comes from the Link Pages host filter, lowercased.' );
$_SERVER['HTTP_HOST'] = 'extrachill.link:443';
check( ec_network_link_editor_hint_on_public_host(), 'Port is ignored when matching the public host.' );
$_SERVER['HTTP_HOST'] = 'artist.extrachill.com';
check( ! ec_network_link_editor_hint_on_public_host(), 'Network sites are not the public host.' );
$GLOBALS['hint_filters']['ec_link_page_public_host'] = '';
$_SERVER['HTTP_HOST'] = '';
check( ! ec_network_link_editor_hint_on_public_host(), 'No configured host never matches an empty host.' );

check( 'ec_network_link_editor_hint_maybe_prime' === ( $GLOBALS['hint_hooks']['template_redirect'][0] ?? '' ), 'Bounce runs on template_redirect.' );
check( isset( $GLOBALS['hint_hooks']['admin_post_nopriv_ec_link_editor_hint'], $GLOBALS['hint_hooks']['admin_post_ec_link_editor_hint'] ), 'Public host endpoint handles signed-out and signed-in requests.' );
check( isset( $GLOBALS['hint_hooks']['set_logged_in_cookie'], $GLOBALS['hint_hooks']['clear_auth_cookie'] ), 'Login and logout reset the primed marker.' );

$source = (string) file_get_contents( dirname( __DIR__ ) . '/inc/link-pages/editor-hint.php' );
check( false !== strpos( $source, 'wp_safe_redirect( wp_validate_redirect( $return, $fallback )' ), 'Return URL goes through wp_safe_redirect (no open redirect).' );
check( 1 === preg_match( "/EC_LINK_EDITOR_HINT_COOKIE, '1', time\\(\\) \\+ YEAR_IN_SECONDS, '', false \\)/", $source ), 'Hint cookie is host-only and readable by the public page script.' );

if ( $failures ) {
	exit( 1 );
}
echo "link editor hint smoke: PASS\n";
