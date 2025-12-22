<?php
/**
 * IndexNow Integration
 *
 * Provides:
 * - `/{indexnow_key}.txt` endpoint
 * - Simple URL pings on post publish/update/trash
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', __NAMESPACE__ . '\\ec_seo_register_indexnow_key_rewrite' );
add_action( 'template_redirect', __NAMESPACE__ . '\\ec_seo_maybe_output_indexnow_key_file' );

add_action( 'transition_post_status', __NAMESPACE__ . '\\ec_seo_indexnow_on_status_transition', 20, 3 );
add_action( 'deleted_post', __NAMESPACE__ . '\\ec_seo_indexnow_on_deleted_post', 20, 1 );

function ec_seo_register_indexnow_key_rewrite() {
	add_rewrite_rule( '^([^/]+)\\.txt$', 'index.php?ec_indexnow_key=$matches[1]', 'top' );
	add_rewrite_tag( '%ec_indexnow_key%', '([^&]+)' );
}

function ec_seo_maybe_output_indexnow_key_file() {
	$key_request = get_query_var( 'ec_indexnow_key' );
	if ( empty( $key_request ) ) {
		return;
	}

	$indexnow_key = ec_seo_get_indexnow_key();
	if ( empty( $indexnow_key ) || $key_request !== $indexnow_key ) {
		status_header( 404 );
		nocache_headers();
		exit;
	}

	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo esc_html( $indexnow_key );
	exit;
}

function ec_seo_indexnow_on_status_transition( $new_status, $old_status, $post ) {
	$post_id = isset( $post->ID ) ? (int) $post->ID : 0;
	if ( ! $post_id ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( 'publish' === $new_status && 'publish' !== $old_status ) {
		ec_seo_indexnow_submit_urls( array( get_permalink( $post_id ) ) );
		return;
	}

	if ( 'publish' === $old_status && 'publish' !== $new_status ) {
		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			ec_seo_indexnow_submit_urls( array( $permalink ) );
		}
	}
}

function ec_seo_indexnow_on_deleted_post( $post_id ) {
	$permalink = get_permalink( $post_id );
	if ( $permalink ) {
		ec_seo_indexnow_submit_urls( array( $permalink ) );
	}
}

function ec_seo_indexnow_submit_urls( $urls ) {
	$indexnow_key = ec_seo_get_indexnow_key();
	if ( empty( $indexnow_key ) ) {
		return;
	}

	$urls = array_filter( array_map( 'esc_url_raw', (array) $urls ) );
	$urls = array_values( array_unique( $urls ) );

	if ( empty( $urls ) ) {
		return;
	}

	$payload = array(
		'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
		'key'         => $indexnow_key,
		'keyLocation' => home_url( '/' . rawurlencode( $indexnow_key ) . '.txt' ),
		'urlList'     => $urls,
	);

	wp_remote_post(
		'https://api.indexnow.org/indexnow',
		array(
			'timeout' => 5,
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode( $payload ),
		)
	);
}
