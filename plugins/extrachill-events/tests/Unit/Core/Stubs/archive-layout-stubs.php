<?php
/**
 * File-level WP doubles owned by the archive-layout-unit suite.
 *
 * Kept local to this suite (see the comment in
 * phpunit-archive-layout-unit.xml.dist and the near-me-unit precedent):
 * sibling pure suites define the same symbols with their own expectations,
 * and whichever doubles file loads first in a process wins. Local doubles
 * keep this suite's behavior pinned regardless of edits to wp-stubs.php.
 *
 * Every double is guarded: when the managed suite loads real WordPress,
 * these definitions are skipped entirely.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $name, $value ) {
		unset( $name );
		return $value;
	}
}

if ( ! function_exists( 'do_blocks' ) ) {
	function do_blocks( $content ) {
		return $content;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return (string) json_encode( $data );
	}
}

if ( ! function_exists( 'is_tax' ) ) {
	function is_tax( $taxonomies = array() ) {
		unset( $taxonomies );
		return (bool) ( $GLOBALS['test_is_tax'] ?? false );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
		$GLOBALS['test_enqueued_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'in_footer' );
	}
}
