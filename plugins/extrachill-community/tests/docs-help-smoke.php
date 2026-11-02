<?php
/**
 * Standalone docs URL and placement regression checks: php tests/docs-help-smoke.php.
 *
 * @package ExtraChillCommunity
 */

define( 'ABSPATH', __DIR__ );

/**
 * Docs site URL test double.
 *
 * @param string $site Site key.
 * @return string|false
 */
function ec_get_site_url( $site ) {
	return 'docs' === $site ? getenv( 'EC_DOCS_TEST_URL' ) : '';
}

/**
 * Normalize a URL test double.
 *
 * @param string $url URL.
 * @return string
 */
function trailingslashit( $url ) {
	return rtrim( $url, '/' ) . '/';
}

/**
 * Sanitize a slug test double.
 *
 * @param string $slug Slug.
 * @return string
 */
function sanitize_title( $slug ) {
	return strtolower( trim( $slug, '/' ) );
}

putenv( 'EC_DOCS_TEST_URL=https://docs.example.test/' );
require_once __DIR__ . '/../inc/core/docs-links.php';
require_once __DIR__ . '/../../extrachill-artist-platform/inc/core/docs-links.php';
require_once __DIR__ . '/../../extrachill-events/inc/core/docs-links.php';

$placements = array(
	'inc/social/notifications/notifications-content.php' => 'notifications-system',
	'src/blocks/edit-profile/view.tsx'                   => 'setting-up-your-user-profile',
	'bbpress/form-topic.php'                             => 'using-the-forums',
);

foreach ( $placements as $file => $slug ) {
	$expected = 'https://docs.example.test/community/' . $slug . '/';
	if ( extrachill_community_docs_url( $slug ) !== $expected ) {
		throw new RuntimeException( 'Incorrect docs URL.' );
	}
	$source = file_get_contents( __DIR__ . '/../' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract check.
	if ( false === strpos( $source, 'data-ec-track="docs-help-' . $slug . '"' ) ) {
		throw new RuntimeException( 'Missing tracked help link.' );
	}
}

$helpers = array(
	'community'       => 'extrachill_community_docs_url',
	'artist-platform' => 'extrachill_artist_platform_docs_url',
	'events-calendar' => 'extrachill_events_docs_url',
);
foreach ( $helpers as $section => $helper ) {
	$expected = 'https://docs.example.test/' . $section . '/getting-started/';
	if ( $helper( 'getting-started' ) !== $expected ) {
		throw new RuntimeException( 'Incorrect section URL.' );
	}
}
putenv( 'EC_DOCS_TEST_URL' );
foreach ( $helpers as $section => $helper ) {
	$expected = 'https://docs.extrachill.com/' . $section . '/getting-started/';
	if ( $helper( 'getting-started' ) !== $expected ) {
		throw new RuntimeException( 'Incorrect fallback URL.' );
	}
}

echo "Docs help checks passed.\n";
