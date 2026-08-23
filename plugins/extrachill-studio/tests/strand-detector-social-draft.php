<?php
/**
 * Focused social-draft detector regression harness.
 *
 * Run with: php tests/strand-detector-social-draft.php
 */

namespace ExtraChillStudio {
	const META_PLATFORMS   = '_studio_social_platforms';
	const META_CAPTION     = '_studio_social_caption';
	const META_IMAGES      = '_studio_social_images';
	const META_DELIVERY_REF = '_studio_social_delivery_ref';
}

namespace {
	define( 'ABSPATH', __DIR__ );

	$GLOBALS['strand_meta'] = array();

	function metadata_exists( $type, $post_id, $key ) {
		unset( $type );
		return array_key_exists( $key, $GLOBALS['strand_meta'][ $post_id ] ?? array() );
	}

	function get_post_meta( $post_id, $key, $single ) {
		unset( $single );
		return $GLOBALS['strand_meta'][ $post_id ][ $key ] ?? '';
	}

	require dirname( __DIR__ ) . '/inc/compose/strand-detector.php';

	$post_id = 42;
	$GLOBALS['strand_meta'][ $post_id ][ ExtraChillStudio\META_DELIVERY_REF ] = 'dop_' . str_repeat( 'a', 64 );
	if ( ! ec_studio_post_looks_like_social_draft( $post_id ) ) {
		throw new RuntimeException( 'A queued delivery reference must identify a social draft.' );
	}

	$GLOBALS['strand_meta'][ $post_id ][ ExtraChillStudio\META_DELIVERY_REF ] = '';
	if ( ec_studio_post_looks_like_social_draft( $post_id ) ) {
		throw new RuntimeException( 'An empty delivery reference must not identify a social draft.' );
	}

	echo "PASS: strand detector recognizes current delivery references\n";
}
