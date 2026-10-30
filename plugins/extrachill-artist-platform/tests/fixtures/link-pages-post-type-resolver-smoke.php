<?php

// phpcs:ignoreFile WordPress.WP.AlternativeFunctions.json_encode_json_encode -- standalone smoke harness mirroring the plugin bootstrap under test; runs outside WP with mirrored signatures.

/**
 * Proves extrachill_artist_platform_link_page_post_type() (extrachill-link-pages#34):
 * the guarded resolver defers to the runtime function ec_link_page_post_type()
 * when it exists, and falls back to the legacy 'artist_link_page' literal
 * otherwise. That fallback line is the only place the literal may appear in
 * a consumer.
 */

$mode = $argv[1] ?? 'fallback';
$root = dirname( __DIR__, 2 );

define( 'ABSPATH', __DIR__ . '/' );

function get_option( $name, $default = false ) {
	// Bundled mode: no external runtime plugin active.
	return 'active_plugins' === $name ? array() : $default;
}

function get_site_option( $name, $default = false ) {
	return $default;
}

if ( 'resolved' === $mode ) {
	function ec_link_page_post_type( $blog_id = null ) {
		return 'ec_link_page';
	}
}

require_once $root . '/inc/link-pages/runtime-handoff.php';

echo json_encode(
	array(
		'resolved' => extrachill_artist_platform_link_page_post_type(),
	)
);
