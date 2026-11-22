<?php
/** WordPress function doubles for publication email tests (no class declarations). */

function ec_get_site_url( $site ) {
	return 'https://' . $site . '.example';
}

function add_query_arg( $args, $url ) {
	$parts = explode( '#', $url, 2 );
	return $parts[0] . ( str_contains( $parts[0], '?' ) ? '&' : '?' )
		. http_build_query( $args ) . ( isset( $parts[1] ) ? '#' . $parts[1] : '' );
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['event_mail_post_meta'][ $id ][ $key ] ?? ''; }
	function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['event_mail_user_meta'][ $id ][ $key ] ?? ''; }
	function get_userdata( $id ) { return $GLOBALS['event_mail_users'][ $id ] ?? false; }
	function get_the_title( $post ) { return $GLOBALS['event_mail_titles'][ $post->ID ] ?? ''; }
	function get_permalink( $post ) { return 'https://events.example/event/' . $post->ID . '/'; }
	function get_password_reset_key( $user ) { return 'one-time-key'; }
}

if ( ! function_exists( 'ec_users_build_account_claim_url' ) ) {
	function ec_users_build_account_claim_url( $user ) { return 'https://community.example/reset-password/?action=reset&key=one-time-key&login=' . rawurlencode( $user->user_login ); }
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return (string) $url; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) { return 'https://events.example' . $path; }
}
if ( ! function_exists( 'network_site_url' ) ) {
	function network_site_url( $path = '' ) { return 'https://network.example/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) { return rtrim( (string) $value, '/\\' ) . '/'; }
}
