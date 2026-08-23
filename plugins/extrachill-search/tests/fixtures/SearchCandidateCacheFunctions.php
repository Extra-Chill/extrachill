<?php
/**
 * Object-cache and post hydration stubs for standalone search tests.
 *
 * @package ExtraChill\Search
 */

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function wp_cache_get_last_changed() {
	global $current_blog_id, $posts_generation;
	return $posts_generation[ $current_blog_id ];
}

function wp_cache_get( $key, $group ) {
	global $object_cache;
	return $object_cache[ $group ][ $key ] ?? false;
}

function wp_cache_add( $key, $value, $group ) {
	global $cache_adds, $object_cache;
	$cache_adds[] = array( $group, $key );
	if ( isset( $object_cache[ $group ][ $key ] ) ) {
		return false;
	}
	$object_cache[ $group ][ $key ] = $value;
	return true;
}

function wp_cache_set( $key, $value, $group, $expiry = 0 ) {
	global $cache_expiries, $object_cache;
	$object_cache[ $group ][ $key ] = $value;
	$cache_expiries[ $group ][ $key ] = $expiry;
	return true;
}

function wp_cache_delete( $key, $group ) {
	global $object_cache;
	unset( $object_cache[ $group ][ $key ] );
	return true;
}

function get_post( $post_id ) {
	global $current_blog_id;
	if ( is_object( $post_id ) ) {
		return $post_id;
	}
	foreach ( WP_Query::$posts_by_blog[ $current_blog_id ] ?? array() as $post ) {
		if ( (int) $post_id === (int) $post->ID ) {
			return $post;
		}
	}
	return null;
}

function _prime_post_caches() {}

function setup_postdata( $post_data ) {
	$GLOBALS['post'] = $post_data;
}
