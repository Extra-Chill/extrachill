<?php
/** Stored redirects do not depend on registration of the retired content model. */
defined( 'ABSPATH' ) || exit;

function extrachill_docs_redirect_target( string $path ): int {
	$map = get_option( 'extrachill_docs_legacy_redirects', array() );
	return (int) ( $map[ '/' . trim( $path, '/' ) . '/' ] ?? 0 );
}

function extrachill_docs_redirect_legacy_path(): void {
	$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
	$id   = extrachill_docs_redirect_target( (string) $path );
	if ( ! $id || 'publish' !== get_post_status( $id ) ) {
		return;
	}
	$url = get_permalink( $id );
	if ( ! $url || rtrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) === rtrim( (string) $path, '/' ) ) {
		return;
	}
	wp_safe_redirect( $url, 301 );
	exit;
}
add_action( 'template_redirect', 'extrachill_docs_redirect_legacy_path', 1 );
