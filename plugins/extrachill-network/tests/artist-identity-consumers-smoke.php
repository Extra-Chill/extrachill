<?php
/**
 * Standalone checks for network consumers of the canonical artist identity.
 *
 * Exercises the cross-site artist links and the artist canonical resolver
 * against the real ec_get_artist_identity(), from the main blog.
 *
 * Run: php tests/artist-identity-consumers-smoke.php
 *
 * @package ExtraChillNetwork
 */

declare( strict_types=1 );

// phpcs:disable -- Standalone WordPress multisite mocks intentionally share one file.

define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/support/artist-identity-wp-objects.php';

$GLOBALS['aic_blog_id']    = 1;
$GLOBALS['aic_blog_stack'] = array();
$GLOBALS['aic_switches']   = 0;
$GLOBALS['aic_artist_ids'] = array( 40, 41, 999 );

function __( string $text ): string {
	return $text;
}

function absint( $value ): int {
	return abs( (int) $value );
}

function add_filter( string $hook, $callback ): void {}

function get_current_blog_id(): int {
	return (int) $GLOBALS['aic_blog_id'];
}

function switch_to_blog( $blog_id ): bool {
	++$GLOBALS['aic_switches'];
	$GLOBALS['aic_blog_stack'][] = $GLOBALS['aic_blog_id'];
	$GLOBALS['aic_blog_id']      = (int) $blog_id;
	return true;
}

function restore_current_blog(): bool {
	$GLOBALS['aic_blog_id'] = (int) array_pop( $GLOBALS['aic_blog_stack'] );
	return true;
}

function trailingslashit( string $value ): string {
	return rtrim( $value, '/' ) . '/';
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/' );
}

function home_url( string $path = '' ): string {
	$base = 4 === get_current_blog_id() ? 'https://artist.extrachill.com' : 'https://extrachill.com';
	return $base . '/' . ltrim( $path, '/' );
}

function get_option( string $name, $fallback = false ) {
	return 'permalink_structure' === $name ? '/%postname%/' : $fallback;
}

// The artist CPT is registered only on the artist blog.
function get_post_type_object( string $post_type ): ?WP_Post_Type {
	return 4 === get_current_blog_id() && 'artist_profile' === $post_type ? new WP_Post_Type( array( 'slug' => 'artists' ) ) : null;
}

function aic_posts(): array {
	if ( 4 !== get_current_blog_id() ) {
		return array();
	}
	return array(
		40 => new WP_Post( 40, 'artist_profile', 'publish', 'kid-lake', 'Kid Lake' ),
		41 => new WP_Post( 41, 'artist_profile', 'draft', 'draft-band', 'Draft Band' ),
		42 => new WP_Post( 42, 'artist_profile', 'publish', 'no-image', 'No Image' ),
	);
}

function get_post( int $post_id ): ?WP_Post {
	return aic_posts()[ $post_id ] ?? null;
}

function get_post_meta( int $post_id, string $key, bool $single ) {
	unset( $single );
	$meta = 4 === get_current_blog_id() ? array( 40 => array( '_thumbnail_id' => '501' ) ) : array();
	return $meta[ $post_id ][ $key ] ?? '';
}

function get_post_thumbnail_id( WP_Post $post ): int {
	return (int) get_post_meta( $post->ID, '_thumbnail_id', true );
}

function wp_get_attachment_image_url( int $attachment_id, string $size ) {
	return 'https://artist.extrachill.com/uploads/' . $attachment_id . '-' . $size . '.jpg';
}

function get_posts( array $args ): array {
	foreach ( aic_posts() as $post ) {
		if ( $args['post_type'] === $post->post_type && $args['post_status'] === $post->post_status && $args['name'] === $post->post_name ) {
			return array( $post->ID );
		}
	}
	return array();
}

function taxonomy_exists( string $taxonomy ): bool {
	return 1 === get_current_blog_id() && 'artist' === $taxonomy;
}

function get_term( int $term_id, string $taxonomy ) {
	unset( $term_id, $taxonomy );
	return null;
}

function get_term_meta( int $term_id, string $key, bool $single ) {
	unset( $term_id, $key, $single );
	return '';
}

function ec_get_artists_for_user( $user_id ): array {
	unset( $user_id );
	return $GLOBALS['aic_artist_ids'];
}

function extrachill_get_current_site_key(): string {
	return 'main';
}

function get_userdata( $user_id ) {
	unset( $user_id );
	return false;
}

function get_user_by( string $field, string $value ) {
	unset( $field, $value );
	return false;
}

require_once __DIR__ . '/support/network-contract.php';
require_once __DIR__ . '/support/artist-identity-runtime.php';
require_once dirname( __DIR__ ) . '/inc/cross-site-links/entity-links.php';
require_once dirname( __DIR__ ) . '/inc/cross-site-links/canonical-authority.php';

function aic_assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

aic_assert_same(
	array(
		'id'        => 40,
		'permalink' => 'https://artist.extrachill.com/artists/kid-lake/',
	),
	extrachill_get_artist_profile_by_slug( 'Kid Lake' ),
	'profile by slug returns the published profile with its pretty artist-blog permalink'
);
aic_assert_same( false, extrachill_get_artist_profile_by_slug( 'draft-band' ), 'profile by slug ignores unpublished profiles' );
aic_assert_same( false, extrachill_get_artist_profile_by_slug( '' ), 'profile by slug rejects an empty slug' );
aic_assert_same( 1, get_current_blog_id(), 'slug lookups restore the caller blog' );

aic_assert_same( true, extrachill_artist_profile_has_image( 'kid-lake' ), 'a published profile with a featured image has an image' );
aic_assert_same( false, extrachill_artist_profile_has_image( 'no-image' ), 'a published profile without a featured image has none' );
aic_assert_same( false, extrachill_artist_profile_has_image( 'missing' ), 'a missing profile has no image' );
aic_assert_same( 'https://artist.extrachill.com/artists/kid-lake/', extrachill_resolve_artist_canonical( 'kid-lake' ), 'the artist canonical resolves to the profile permalink' );
aic_assert_same( null, extrachill_resolve_artist_canonical( 'no-image' ), 'an artist without a profile image has no canonical' );

$switches = $GLOBALS['aic_switches'];
extrachill_get_artist_profile_by_slug( 'kid-lake' );
extrachill_artist_profile_has_image( 'kid-lake' );
aic_assert_same( $switches, $GLOBALS['aic_switches'], 'repeat slug lookups are served from the identity cache' );

$switches = $GLOBALS['aic_switches'];
aic_assert_same(
	array(
		array(
			'type'  => 'artist_profile',
			'url'   => 'https://artist.extrachill.com/artists/kid-lake/',
			'label' => 'Kid Lake',
		),
	),
	extrachill_get_cross_site_user_links( 44 ),
	'user links list only published artist profiles, with canonical URLs'
);
aic_assert_same( 1, get_current_blog_id(), 'user links restore the caller blog' );
extrachill_get_cross_site_user_links( 44 );
aic_assert_same( $switches + 3, $GLOBALS['aic_switches'], 'user links read each uncached profile once, then hit the cache' );

echo "Artist identity consumer checks passed.\n";
