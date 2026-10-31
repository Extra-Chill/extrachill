<?php
/**
 * Photo contest entry QR generation with a temporary, URL-scoped grant.
 *
 * @package ExtraChillContentBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate a QR for a visible entry's canonical share URL, never a client URL.
 *
 * @param int    $post_id    Contest post ID.
 * @param string $contest_id Contest block instance ID.
 * @param int    $entry_id   Entry attachment ID.
 * @return array|WP_Error Base64 image and MIME type, or status-coded error.
 */
function extrachill_content_blocks_photo_contest_entry_qr( $post_id, $contest_id, $entry_id ) {
	if ( $post_id <= 0 || '' === (string) $contest_id || $entry_id <= 0 ) {
		return extrachill_content_blocks_photo_contest_error( 'invalid_entry_input', __( 'A post, contest, and entry ID are required.', 'extrachill-content-blocks' ), 400 );
	}

	if ( null === extrachill_content_blocks_photo_contest_get_settings( $post_id, $contest_id ) ) {
		return extrachill_content_blocks_photo_contest_error( 'contest_not_found', __( 'Contest not found.', 'extrachill-content-blocks' ), 404 );
	}

	if ( ! extrachill_content_blocks_photo_contest_is_valid_entry( $post_id, $contest_id, $entry_id ) ) {
		return extrachill_content_blocks_photo_contest_error( 'entry_not_found', __( 'Entry not found.', 'extrachill-content-blocks' ), 404 );
	}

	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/generate-qr-code' ) : null;
	if ( ! $ability ) {
		return extrachill_content_blocks_photo_contest_error( 'qr_unavailable', __( 'QR generation is unavailable.', 'extrachill-content-blocks' ), 503 );
	}

	$share_url = add_query_arg( 'entry', (int) $entry_id, (string) get_permalink( $post_id ) );

	// The QR for a URL never changes: generate once, serve from cache.
	$cache_key = 'ec_pc_qr_' . md5( $share_url );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) && ! empty( $cached['image'] ) && ! empty( $cached['mime_type'] ) ) {
		return $cached;
	}
	$grant     = static function ( $permission, $ability_name, $input = null ) use ( $share_url ) {
		if ( 'extrachill/generate-qr-code' === $ability_name && is_array( $input ) && ( $input['url'] ?? null ) === $share_url ) {
			return true;
		}
		return $permission;
	};

	add_filter( 'wp_ability_permission_result', $grant, 10, 3 );
	try {
		$result = $ability->execute( array( 'url' => $share_url, 'size' => 600 ) );
	} catch ( \Throwable $error ) {
		$result = null;
	} finally {
		remove_filter( 'wp_ability_permission_result', $grant, 10 );
	}

	if ( ! is_array( $result ) || empty( $result['image'] ) || empty( $result['mime_type'] ) ) {
		return extrachill_content_blocks_photo_contest_error( 'qr_generation_failed', __( 'Could not generate QR code.', 'extrachill-content-blocks' ), 503 );
	}

	$qr = array( 'image' => $result['image'], 'mime_type' => $result['mime_type'] );
	set_transient( $cache_key, $qr, WEEK_IN_SECONDS );
	return $qr;
}
