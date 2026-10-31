<?php
/**
 * Standalone tests for canonical cross-site user profile links.
 *
 * @package ExtraChillNetwork
 */

declare( strict_types=1 );

// phpcs:disable -- WordPress standalone mocks intentionally share one file.

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['upl_blog_id']        = 1;
$GLOBALS['upl_blog_stack']     = array();
$GLOBALS['upl_bbp_calls']      = array();
$GLOBALS['upl_bbp_return_url'] = true;
$GLOBALS['upl_escaped_urls']   = array();
$GLOBALS['upl_users']          = array(
	2 => array(
		44 => (object) array(
			'ID'            => 44,
			'user_email'    => 'chris@example.com',
			'user_nicename' => 'chris-huber',
			'display_name'  => 'Chris Huber',
		),
	),
);

function __( string $text ): string {
	return $text;
}

function absint( $value ): int {
	return abs( (int) $value );
}

function ec_get_blog_id( string $site ): int {
	return 'community' === $site ? 2 : 0;
}

function ec_get_site_url( string $site ): string {
	return 'community' === $site ? 'https://community.extrachill.com' : '';
}

function switch_to_blog( $blog_id ): bool {
	$GLOBALS['upl_blog_stack'][] = $GLOBALS['upl_blog_id'];
	$GLOBALS['upl_blog_id']      = (int) $blog_id;
	return true;
}

function restore_current_blog(): bool {
	$GLOBALS['upl_blog_id'] = (int) array_pop( $GLOBALS['upl_blog_stack'] );
	return true;
}

function get_userdata( $user_id ) {
	return $GLOBALS['upl_users'][ $GLOBALS['upl_blog_id'] ][ (int) $user_id ] ?? false;
}

function get_user_by( string $field, string $value ) {
	foreach ( $GLOBALS['upl_users'][ $GLOBALS['upl_blog_id'] ] ?? array() as $user ) {
		if ( 'email' === $field && $user->user_email === $value ) {
			return $user;
		}
	}

	return false;
}

function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/';
}

function user_trailingslashit( string $value ): string {
	return 2 === $GLOBALS['upl_blog_id'] ? trailingslashit( $value ) : untrailingslashit( $value );
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

function bbp_get_user_profile_edit_url( int $user_id, string $user_nicename ): string|false {
	$GLOBALS['upl_bbp_calls'][] = array(
		'blog_id'       => $GLOBALS['upl_blog_id'],
		'user_id'       => $user_id,
		'user_nicename' => $user_nicename,
	);

	if ( ! $GLOBALS['upl_bbp_return_url'] ) {
		return false;
	}

	return user_trailingslashit( ec_get_site_url( 'community' ) . '/u/' . $user_nicename . '/edit' );
}

function add_filter( string $hook, $callback ): void {}

function is_user_logged_in(): bool {
	return true;
}

function wp_get_current_user(): object {
	return $GLOBALS['upl_users'][2][44];
}

function wp_logout_url( string $redirect ): string {
	return 'https://extrachill.com/wp-login.php?action=logout&redirect_to=' . rawurlencode( $redirect );
}

function home_url(): string {
	return 'https://extrachill.com';
}

function esc_url( string $url ): string {
	$GLOBALS['upl_escaped_urls'][] = $url;
	return str_replace( '&', '&#038;', $url );
}

require_once dirname( __DIR__ ) . '/inc/cross-site-links/entity-links.php';

function upl_assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

function upl_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$public_url = extrachill_get_user_community_profile_url( 44 );
$edit_url   = extrachill_get_user_community_profile_edit_url( 44 );

upl_assert_same( 'https://community.extrachill.com/u/chris-huber', $public_url, 'The existing public profile contract remains unchanged.' );
upl_assert_same( 'https://community.extrachill.com/u/chris-huber/edit/', $edit_url, 'A valid user resolves to the canonical Community editor.' );
upl_assert_same( trailingslashit( $public_url ) . 'edit/', $edit_url, 'The edit URL extends the canonical public profile URL.' );
upl_assert_same( 2, $GLOBALS['upl_bbp_calls'][0]['blog_id'], 'The bbPress URL API runs in Community blog context.' );
upl_assert_same( 1, $GLOBALS['upl_blog_id'], 'Profile resolution restores the caller blog.' );

upl_assert_same( '', extrachill_get_user_community_profile_edit_url( 999 ), 'An invalid user returns an empty URL.' );
upl_assert_same( 1, count( $GLOBALS['upl_bbp_calls'] ), 'Invalid users never reach bbPress URL generation.' );

$GLOBALS['upl_blog_id'] = 7;
$email_url              = extrachill_get_user_community_profile_edit_url( 999, 'chris@example.com' );
upl_assert_same( 'https://community.extrachill.com/u/chris-huber/edit/', $email_url, 'Email fallback resolves the canonical Community user.' );
upl_assert_same( 7, $GLOBALS['upl_blog_id'], 'A switched-blog caller is restored after resolution.' );
upl_assert_same( 44, $GLOBALS['upl_bbp_calls'][1]['user_id'], 'bbPress receives the resolved Community user ID.' );

$GLOBALS['upl_bbp_return_url'] = false;
$fallback_url                  = extrachill_get_user_community_profile_edit_url( 44 );
upl_assert_same( 'https://community.extrachill.com/u/chris-huber/edit/', $fallback_url, 'The fallback preserves bbPress canonical route shape.' );

$defaults = extrachill_customize_comment_form_logged_in( array() );
upl_assert( str_contains( $defaults['logged_in_as'], 'href="https://community.extrachill.com/u/chris-huber/edit/"' ), 'Comment UI uses the escaped canonical profile editor URL.' );
upl_assert( in_array( 'https://community.extrachill.com/u/chris-huber/edit/', $GLOBALS['upl_escaped_urls'], true ), 'The profile editor URL is escaped at output.' );

echo "User profile link tests passed.\n";
