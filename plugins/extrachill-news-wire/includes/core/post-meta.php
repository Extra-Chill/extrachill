<?php
/**
 * Festival Wire Post Meta
 *
 * Hides author name from Festival Wire post meta display.
 * Filters extrachill_post_meta_parts to remove 'author' element.
 *
 * @package ExtraChillNewsWire
 * @since 0.3.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'extrachill_post_meta_parts', 'festival_wire_hide_author_from_meta', 10, 3 );

function festival_wire_hide_author_from_meta( $parts, $post_id, $post_type ) {
	if ( 'festival_wire' === $post_type ) {
		$author_key = array_search( 'author', $parts, true );
		if ( false !== $author_key ) {
			unset( $parts[ $author_key ] );
		}
	}
	return $parts;
}
