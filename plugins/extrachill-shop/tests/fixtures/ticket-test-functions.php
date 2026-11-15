<?php
/**
 * Global function stand-ins for ticket tests.
 *
 * @package ExtraChillShop
 */

function is_email( $email ) {
	return filter_var( $email, FILTER_VALIDATE_EMAIL );
}

function extrachill_shop_stripe_init() {
	return true;
}

function do_action( ...$args ) {
	$GLOBALS['ticket_actions'][] = $args;
}

// User meta doubles: multi-value rows stored as a list, compatible with ShopManagerTest's.
if ( ! function_exists( 'add_user_meta' ) ) {
	function add_user_meta( $user_id, $key, $value ) {
		$GLOBALS['test_user_meta'][ $user_id ][ $key ]   = (array) ( $GLOBALS['test_user_meta'][ $user_id ][ $key ] ?? array() );
		$GLOBALS['test_user_meta'][ $user_id ][ $key ][] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) {
		return $GLOBALS['test_user_meta'][ $user_id ][ $key ] ?? ( $single ? '' : array() );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text ) {
		return htmlspecialchars( (string) $text );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return (string) $url;
	}
}
