<?php
/**
 * IndexNow Integration
 *
 * Provides:
 * - URL pings on post publish/unpublish/delete
 *
 * Note: The IndexNow key file must be hosted as a static `/{key}.txt` at the domain root.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'transition_post_status', __NAMESPACE__ . '\\ec_seo_indexnow_on_status_transition', 20, 3 );
add_action( 'deleted_post', __NAMESPACE__ . '\\ec_seo_indexnow_on_deleted_post', 20, 1 );
add_action( 'post_updated', __NAMESPACE__ . '\\ec_seo_indexnow_on_post_updated', 20, 3 );

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

function ec_seo_indexnow_on_post_updated( $post_id, $post_after, $post_before ) {
	$post_id = (int) $post_id;
	if ( ! $post_id ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( ! ( $post_after instanceof \WP_Post ) || ! ( $post_before instanceof \WP_Post ) ) {
		return;
	}

	if ( 'publish' !== $post_after->post_status || 'publish' !== $post_before->post_status ) {
		return;
	}

	$post_type_object = get_post_type_object( $post_after->post_type );
	if ( ! $post_type_object || empty( $post_type_object->publicly_queryable ) ) {
		return;
	}

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
