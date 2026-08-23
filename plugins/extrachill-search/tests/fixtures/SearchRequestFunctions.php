<?php
/**
 * WordPress function stubs used by the standalone request regression.
 *
 * @package ExtraChill\Search
 */

function esc_url_raw( $url ) {
	return $url;
}

function wp_unslash( $value ) {
	return $value;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_strip_all_tags( $text ) {
	return strip_tags( $text );
}

function get_search_query() {
	global $query_vars;
	return $query_vars['s'] ?? '';
}

function get_query_var( $key ) {
	global $query_vars;
	return $query_vars[ $key ] ?? '';
}

function get_option( $key, $default = false ) {
	return 'posts_per_page' === $key ? 2 : $default;
}

function extrachill_search_scope_site_urls() {
	global $search_scope;
	return 'network' === $search_scope ? array() : array( 'site2.test' );
}

function get_the_title( $post_id = 0 ) {
	global $post;
	return $post->post_title;
}

function get_permalink( $post_id = 0 ) {
	global $current_blog_id, $post;
	return sprintf( 'https://site%d.test/%d', $current_blog_id, $post_id ?: $post->ID );
}

function get_taxonomies() {
	return array();
}

function get_post_thumbnail_id() {
	return 0;
}

function get_the_content() {
	global $post;
	return $post->post_content;
}

function has_excerpt() {
	global $post;
	return '' !== $post->post_excerpt;
}

function get_the_excerpt() {
	global $post;
	return $post->post_excerpt;
}

function wp_trim_words( $content ) {
	return $content;
}

function wp_reset_postdata() {}

function search_test_post( $id, $status = 'publish', $password = '' ) {
	return (object) array(
		'ID'            => $id,
		'post_title'    => 'Needlefest ' . $id,
		'post_content'  => 'Needlefest content ' . $id,
		'post_excerpt'  => '',
		'post_date'     => '2026-08-23 12:00:00',
		'post_modified' => '2026-08-23 12:00:00',
		'post_type'     => 'post',
		'post_name'     => 'needlefest-' . $id,
		'post_author'   => 1,
		'post_parent'   => 0,
		'post_status'   => $status,
		'post_password' => $password,
	);
}
