<?php
/**
 * IndexNow Integration
 *
 * Provides:
 * - URL pings on post publish/unpublish/delete
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'transition_post_status', __NAMESPACE__ . '\\ec_seo_indexnow_on_status_transition', 20, 3 );
add_action( 'deleted_post', __NAMESPACE__ . '\\ec_seo_indexnow_on_deleted_post', 20, 2 );
add_action( 'post_updated', __NAMESPACE__ . '\\ec_seo_indexnow_on_post_updated', 20, 3 );
add_filter( 'datamachine_indexnow_skip_auto_submit', __NAMESPACE__ . '\\ec_seo_indexnow_skip_generic_auto_submit', 10, 3 );

/**
 * Extra Chill SEO owns automatic submission policy while this module is active.
 */
function ec_seo_indexnow_skip_generic_auto_submit( $skip, $post_id = 0, $post = null ) {
	unset( $skip );
	unset( $post_id );
	unset( $post );
	return true;
}

/**
 * Applies external auto-submit suppression without this module's DMB guard.
 */
function ec_seo_indexnow_should_skip_auto_submit( $post_id, $post ) {
	if ( ! ec_seo_is_indexnow_enabled() ) {
		return true;
	}

	$callback = __NAMESPACE__ . '\\ec_seo_indexnow_skip_generic_auto_submit';

	remove_filter( 'datamachine_indexnow_skip_auto_submit', $callback, 10 );
	$skip = apply_filters( 'datamachine_indexnow_skip_auto_submit', false, $post_id, $post );
	add_filter( 'datamachine_indexnow_skip_auto_submit', $callback, 10, 3 );

	return $skip;
}

function ec_seo_indexnow_on_status_transition( $new_status, $old_status, $post ) {
	$post_id = isset( $post->ID ) ? (int) $post->ID : 0;
	if ( ! $post_id ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( ! ec_seo_indexnow_is_supported_post( $post ) ) {
		return;
	}

	if ( ec_seo_indexnow_should_skip_auto_submit( $post_id, $post ) ) {
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

function ec_seo_indexnow_on_deleted_post( $post_id, $post = null ) {
	if ( ! ( $post instanceof \WP_Post ) || 'publish' !== $post->post_status || ! ec_seo_indexnow_is_supported_post( $post ) ) {
		return;
	}

	if ( ec_seo_indexnow_should_skip_auto_submit( $post_id, $post ) ) {
		return;
	}

	$permalink = get_permalink( $post );
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

	if ( ! ec_seo_indexnow_is_supported_post( $post_after ) || ! ec_seo_indexnow_is_meaningful_update( $post_after, $post_before ) ) {
		return;
	}

	if ( ec_seo_indexnow_should_skip_auto_submit( $post_id, $post_after ) ) {
		return;
	}

	$permalink = get_permalink( $post_id );
	if ( $permalink ) {
		ec_seo_indexnow_submit_urls( array( $permalink ) );
	}
}

function ec_seo_indexnow_submit_urls( $urls ) {
	$urls = array_filter( array_map( 'esc_url_raw', (array) $urls ) );
	$urls = array_values( array_unique( $urls ) );

	if ( empty( $urls ) ) {
		return new \WP_Error( 'no_urls', __( 'No valid URLs to submit.', 'extrachill-seo' ) );
	}

	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/indexnow-submit' ) : null;
	if ( ! $ability ) {
		return new \WP_Error( 'indexnow_unavailable', __( 'The IndexNow submission ability is unavailable.', 'extrachill-seo' ) );
	}

	return $ability->execute( array( 'urls' => $urls ) );
}

function ec_seo_indexnow_is_supported_post( $post ) {
	if ( ! ( $post instanceof \WP_Post ) ) {
		return false;
	}

	$post_type_object = get_post_type_object( $post->post_type );

	return $post_type_object && ! empty( $post_type_object->publicly_queryable );
}

function ec_seo_indexnow_is_meaningful_update( $post_after, $post_before ) {
	$fields = array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_parent' );
	foreach ( $fields as $field ) {
		if ( $post_after->$field !== $post_before->$field ) {
			return true;
		}
	}

	return false;
}
