<?php
/**
 * Standalone checks for the canonical artist identity read.
 *
 * Run: php tests/artist-identity-smoke.php
 *
 * @package ExtraChillNetwork
 */

declare( strict_types=1 );

// phpcs:disable -- Standalone WordPress multisite mocks intentionally share one file.

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

require_once __DIR__ . '/support/artist-identity-wp-objects.php';

$GLOBALS['ec_test_actions']       = array();
$GLOBALS['ec_test_global_groups'] = array();

function add_action( string $hook, string $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['ec_test_actions'][ $hook ] = compact( 'callback', 'priority', 'accepted_args' );
}

function apply_filters( string $hook, $value ) {
	unset( $hook );
	return $value;
}

function get_current_blog_id(): int {
	return $GLOBALS['ec_test']['blog'];
}

function switch_to_blog( int $blog_id ): void {
	++$GLOBALS['ec_test']['switches'];
	$GLOBALS['ec_test']['stack'][] = $GLOBALS['ec_test']['blog'];
	$GLOBALS['ec_test']['blog']    = $blog_id;
}

function restore_current_blog(): void {
	$GLOBALS['ec_test']['blog'] = array_pop( $GLOBALS['ec_test']['stack'] );
}

function wp_cache_add_global_groups( array $groups ): void {
	$GLOBALS['ec_test_global_groups'] = array_merge( $GLOBALS['ec_test_global_groups'], $groups );
}

function wp_cache_get( string $key, string $group, bool $force = false, &$found = null ) {
	unset( $force );
	$found = array_key_exists( $group . '|' . $key, $GLOBALS['ec_test_cache'] );
	return $found ? $GLOBALS['ec_test_cache'][ $group . '|' . $key ] : false;
}

function wp_cache_set( string $key, $value, string $group, int $ttl = 0 ): bool {
	unset( $ttl );
	$GLOBALS['ec_test_cache'][ $group . '|' . $key ] = $value;
	return true;
}

function wp_cache_add( string $key, $value, string $group ): bool {
	if ( array_key_exists( $group . '|' . $key, $GLOBALS['ec_test_cache'] ) ) {
		return false;
	}
	return wp_cache_set( $key, $value, $group );
}

function wp_cache_delete( string $key, string $group ): bool {
	unset( $GLOBALS['ec_test_cache'][ $group . '|' . $key ] );
	return true;
}

function wp_cache_incr( string $key, int $offset, string $group ) {
	if ( ! array_key_exists( $group . '|' . $key, $GLOBALS['ec_test_cache'] ) ) {
		return false;
	}
	$GLOBALS['ec_test_cache'][ $group . '|' . $key ] += $offset;
	return $GLOBALS['ec_test_cache'][ $group . '|' . $key ];
}

function sanitize_title( string $value ): string {
	return strtolower( trim( (string) preg_replace( '/[^a-z0-9-]+/i', '-', $value ), '-' ) );
}

function trailingslashit( string $value ): string {
	return rtrim( $value, '/' ) . '/';
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/' );
}

function ec_test_blog_data(): array {
	return $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ] ?? array();
}

function get_option( string $name ) {
	return ec_test_blog_data()['options'][ $name ] ?? false;
}

function home_url( string $path = '' ): string {
	return ( ec_test_blog_data()['home'] ?? 'https://blog-' . get_current_blog_id() . '.test' ) . '/' . ltrim( $path, '/' );
}

function get_post_type_object( string $post_type ): ?WP_Post_Type {
	unset( $post_type );
	return null;
}

function get_post( int $post_id ): ?WP_Post {
	return ec_test_blog_data()['posts'][ $post_id ] ?? null;
}

function get_post_type( int $post_id ): string {
	$post = get_post( $post_id );
	return $post ? $post->post_type : '';
}

function get_post_meta( int $post_id, string $key, bool $single ) {
	unset( $single );
	return ec_test_blog_data()['post_meta'][ $post_id ][ $key ] ?? '';
}

function get_post_thumbnail_id( WP_Post $post ): int {
	return (int) get_post_meta( $post->ID, '_thumbnail_id', true );
}

function wp_get_attachment_image_url( int $attachment_id, string $size ) {
	return 'https://artist.test/uploads/' . $attachment_id . '-' . $size . '.webp';
}

function get_posts( array $args ): array {
	$ids = array();
	foreach ( ec_test_blog_data()['posts'] ?? array() as $post ) {
		if ( $args['post_type'] === $post->post_type && $args['post_status'] === $post->post_status && $args['name'] === $post->post_name ) {
			$ids[] = $post->ID;
		}
	}
	return array_slice( $ids, 0, $args['posts_per_page'] );
}

function taxonomy_exists( string $taxonomy ): bool {
	return in_array( $taxonomy, ec_test_blog_data()['taxonomies'] ?? array(), true );
}

function get_term( int $term_id, string $taxonomy ) {
	$term = ec_test_blog_data()['terms'][ $term_id ] ?? null;
	return $term && $taxonomy === $term->taxonomy ? $term : null;
}

function get_term_meta( int $term_id, string $key, bool $single ) {
	unset( $single );
	return ec_test_blog_data()['term_meta'][ $term_id ][ $key ] ?? '';
}

require_once __DIR__ . '/support/network-contract.php';
require_once dirname( __DIR__ ) . '/inc/core/artist-identity.php';

$failures = 0;
function ec_assert( bool $condition, string $message ): void {
	global $failures;
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}
	++$failures;
	fwrite( STDERR, "FAIL: {$message}\n" );
}

/**
 * Reset to a bound pair: profile 40 <-> term 9, plus edge-case fixtures.
 */
function ec_test_reset( int $current_blog = 2 ): void {
	$GLOBALS['ec_test_cache'] = array();
	ec_artist_identity_cache_generation( true );
	$GLOBALS['ec_test']       = array(
		'blog'     => $current_blog,
		'stack'    => array(),
		'switches' => 0,
		'blogs'    => array(
			1 => array(
				'taxonomies' => array( 'artist' ),
				'terms'      => array(
					9  => new WP_Term( 9, 'artist', 'the-band', 'The Band' ),
					10 => new WP_Term( 10, 'artist', 'lonely-term', 'Lonely Term' ),
					11 => new WP_Term( 11, 'artist', 'stale-term', 'Stale Term' ),
					12 => new WP_Term( 12, 'genre', 'rock', 'Rock' ),
				),
				'term_meta'  => array(
					9  => array( '_artist_profile_id' => '40' ),
					11 => array( '_artist_profile_id' => '40' ),
				),
			),
			4 => array(
				'home'      => 'https://artist.test',
				'options'   => array( 'permalink_structure' => '/%postname%' ),
				'posts'     => array(
					40 => new WP_Post( 40, 'artist_profile', 'publish', 'the-band', 'The Band & Friends' ),
					41 => new WP_Post( 41, 'artist_profile', 'draft', '', 'Draft Act' ),
					42 => new WP_Post( 42, 'artist_profile', 'publish', 'one-sided', 'One Sided' ),
					43 => new WP_Post( 43, 'page', 'publish', 'not-an-artist', 'Not An Artist' ),
				),
				'post_meta' => array(
					40 => array(
						'_artist_term_id' => 9,
						'_thumbnail_id'   => '77',
					),
					42 => array( '_artist_term_id' => '9' ),
				),
			),
		),
	);
}

// Registration.
ec_test_reset();
ec_assert( in_array( EC_ARTIST_IDENTITY_CACHE_GROUP, $GLOBALS['ec_test_global_groups'], true ), 'cache group is registered as network-global' );
foreach ( array( 'clean_post_cache', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta', 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
	$expected_args = 'clean_post_cache' === $hook ? 2 : 3;
	ec_assert( isset( $GLOBALS['ec_test_actions'][ $hook ] ) && $expected_args === $GLOBALS['ec_test_actions'][ $hook ]['accepted_args'], "{$hook} invalidation is registered" );
}

// Lookup by profile ID.
$identity = ec_get_artist_identity( array( 'profile_id' => 40 ) );
ec_assert(
	array(
		'profile_id'    => 40,
		'term_id'       => 9,
		'slug'          => 'the-band',
		'name'          => 'The Band & Friends',
		'status'        => 'publish',
		'permalink'     => 'https://artist.test/artists/the-band',
		'thumbnail_id'  => 77,
		'thumbnail_url' => 'https://artist.test/uploads/77-thumbnail.webp',
		'term_slug'     => 'the-band',
		'term_name'     => 'The Band',
		'binding_valid' => true,
	) === $identity,
	'profile_id lookup returns the full bound identity with a pretty artist-blog permalink'
);
ec_assert( 2 === get_current_blog_id() && array() === $GLOBALS['ec_test']['stack'], 'the read restores the caller blog' );
ec_assert( ec_get_artist_identity( array( 'profile_id' => '40' ) ) === $identity, 'digit-string profile IDs resolve the same identity' );

// Lookup by term ID and slug.
ec_assert( ec_get_artist_identity( array( 'term_id' => 9 ) ) === $identity, 'term_id lookup returns the same identity' );
ec_assert( ec_get_artist_identity( array( 'slug' => 'The Band' ) ) === $identity, 'slug lookup sanitizes and returns the same identity' );

// Cache hits avoid switching.
ec_test_reset();
ec_get_artist_identity( array( 'profile_id' => 40 ) );
$switches = $GLOBALS['ec_test']['switches'];
ec_assert( 2 === $switches, 'a profile miss switches once to the artist blog and once to main' );
ec_get_artist_identity( array( 'profile_id' => 40 ) );
ec_assert( $switches === $GLOBALS['ec_test']['switches'], 'a cache hit does not switch blogs' );
ec_get_artist_identity( array( 'profile_id' => 999 ) );
$switches = $GLOBALS['ec_test']['switches'];
ec_assert( null === ec_get_artist_identity( array( 'profile_id' => 999 ) ) && $switches === $GLOBALS['ec_test']['switches'], 'a missing profile is cached as a negative result' );

// Same-blog reads do not switch.
ec_test_reset( 4 );
ec_get_artist_identity( array( 'profile_id' => 41 ) );
ec_assert( 0 === $GLOBALS['ec_test']['switches'], 'an unbound profile read from the artist blog does not switch' );

// Binding validity.
ec_test_reset();
$one_sided = ec_get_artist_identity( array( 'profile_id' => 42 ) );
ec_assert( is_array( $one_sided ) && 9 === $one_sided['term_id'] && 'the-band' === $one_sided['term_slug'] && false === $one_sided['binding_valid'], 'a profile whose term points elsewhere reports its pointer but an invalid binding' );
$stale = ec_get_artist_identity( array( 'term_id' => 11 ) );
ec_assert( is_array( $stale ) && 40 === $stale['profile_id'] && 'The Band & Friends' === $stale['name'] && false === $stale['binding_valid'], 'a term whose profile points elsewhere reports an invalid binding' );
$lonely = ec_get_artist_identity( array( 'term_id' => 10 ) );
ec_assert( is_array( $lonely ) && 0 === $lonely['profile_id'] && '' === $lonely['name'] && '' === $lonely['status'] && 'lonely-term' === $lonely['term_slug'] && false === $lonely['binding_valid'], 'an unbound term returns term fields only' );
$draft = ec_get_artist_identity( array( 'profile_id' => 41 ) );
ec_assert( is_array( $draft ) && 'draft' === $draft['status'] && 'https://artist.test/?post_type=artist_profile&p=41' === $draft['permalink'] && 0 === $draft['term_id'] && '' === $draft['thumbnail_url'] && false === $draft['binding_valid'], 'an unbound draft gets the core draft permalink and no binding' );
$GLOBALS['ec_test']['blogs'][4]['options']['permalink_structure'] = '';
ec_forget_artist_identity( 40, 0 );
ec_assert( 'https://artist.test/?artist_profile=the-band' === ec_get_artist_identity( array( 'profile_id' => 40 ) )['permalink'], 'plain permalinks use the post type query var, as core does' );
$GLOBALS['ec_test']['blogs'][4]['options']['permalink_structure'] = '/%postname%/';
ec_forget_artist_identity( 40, 0 );
ec_assert( 'https://artist.test/artists/the-band/' === ec_get_artist_identity( array( 'profile_id' => 40 ) )['permalink'], 'a trailing-slash structure adds the trailing slash' );

ec_test_reset();
$GLOBALS['ec_test']['blogs'][1]['term_meta'][9]['_artist_profile_id'] = '40abc';
ec_assert( false === ec_get_artist_identity( array( 'profile_id' => 40 ) )['binding_valid'], 'a malformed term pointer is unbound, not cast' );
ec_test_reset();
$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_artist_term_id'] = -9;
ec_assert( 0 === ec_get_artist_identity( array( 'profile_id' => 40 ) )['term_id'], 'a negative profile pointer is unbound' );
ec_test_reset();
$GLOBALS['ec_test']['blogs'][1]['terms'][9] = new WP_Term( 9, 'genre', 'the-band', 'The Band' );
$wrong_tax = ec_get_artist_identity( array( 'profile_id' => 40 ) );
ec_assert( is_array( $wrong_tax ) && false === $wrong_tax['binding_valid'] && '' === $wrong_tax['term_slug'], 'a pointer to a non-artist term is invalid' );

// Absent and invalid lookups.
ec_test_reset();
ec_assert( null === ec_get_artist_identity( array( 'profile_id' => 43 ) ), 'a non-artist_profile post is not an identity' );
ec_assert( null === ec_get_artist_identity( array( 'term_id' => 12 ) ), 'a term in another taxonomy is not an identity' );
ec_assert( null === ec_get_artist_identity( array( 'slug' => 'draft-act' ) ), 'slug lookups only match published profiles' );
$switches = $GLOBALS['ec_test']['switches'];
ec_assert( null === ec_get_artist_identity( array() ), 'an empty lookup is rejected' );
ec_assert( null === ec_get_artist_identity( array( 'profile_id' => 40, 'term_id' => 9 ) ), 'a lookup with two keys is rejected' );
ec_assert( null === ec_get_artist_identity( array( 'id' => 40 ) ), 'an unknown lookup key is rejected' );
ec_assert( null === ec_get_artist_identity( array( 'profile_id' => 0 ) ), 'a non-positive ID is rejected' );
ec_assert( null === ec_get_artist_identity( array( 'slug' => '' ) ), 'an empty slug is rejected' );
ec_assert( $switches === $GLOBALS['ec_test']['switches'], 'rejected lookups never switch blogs' );

// An unavailable taxonomy is not cached.
ec_test_reset();
$GLOBALS['ec_test']['blogs'][1]['taxonomies'] = array();
ec_assert( null === ec_get_artist_identity( array( 'term_id' => 9 ) ), 'an unregistered artist taxonomy reads as unavailable' );
$GLOBALS['ec_test']['blogs'][1]['taxonomies'] = array( 'artist' );
ec_assert( 40 === ec_get_artist_identity( array( 'term_id' => 9 ) )['profile_id'], 'an unavailable read is not cached' );

// Forget invalidates.
ec_test_reset();
ec_get_artist_identity( array( 'profile_id' => 40 ) );
ec_get_artist_identity( array( 'slug' => 'the-band' ) );
$GLOBALS['ec_test']['blogs'][4]['posts'][40] = new WP_Post( 40, 'artist_profile', 'draft', 'the-band', 'Renamed' );
ec_assert( 'publish' === ec_get_artist_identity( array( 'profile_id' => 40 ) )['status'], 'reads are served from the cache until forgotten' );
ec_forget_artist_identity( 40, 9 );
ec_assert( 'Renamed' === ec_get_artist_identity( array( 'profile_id' => 40 ) )['name'], 'forget rebuilds the profile record' );
ec_assert( null === ec_get_artist_identity( array( 'slug' => 'the-band' ) ), 'forget retires slug records too' );

// Hooks forget on owned writes.
ec_test_reset( 4 );
ec_get_artist_identity( array( 'term_id' => 9 ) );
$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_artist_term_id'] = 10;
ec_artist_identity_post_meta_changed( 1, 40, '_artist_term_id' );
ec_assert( false === ec_get_artist_identity( array( 'term_id' => 9 ) )['binding_valid'], 'a profile pointer write on the artist blog forgets the term record' );

ec_test_reset( 4 );
ec_get_artist_identity( array( 'profile_id' => 40 ) );
$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_thumbnail_id'] = '78';
ec_artist_identity_post_meta_changed( array( 1 ), 40, '_thumbnail_id' );
ec_assert( 78 === ec_get_artist_identity( array( 'profile_id' => 40 ) )['thumbnail_id'], 'a profile thumbnail change forgets the record' );

ec_test_reset( 2 );
ec_get_artist_identity( array( 'profile_id' => 40 ) );
$GLOBALS['ec_test']['blogs'][4]['post_meta'][40]['_thumbnail_id'] = '78';
ec_artist_identity_post_meta_changed( 1, 40, '_thumbnail_id' );
ec_artist_identity_post_meta_changed( 1, 40, '_edit_lock' );
ec_assert( 77 === ec_get_artist_identity( array( 'profile_id' => 40 ) )['thumbnail_id'], 'meta writes on other blogs and unrelated keys do not forget' );

ec_test_reset( 4 );
ec_get_artist_identity( array( 'profile_id' => 40 ) );
$renamed                                     = new WP_Post( 40, 'artist_profile', 'private', 'the-band', 'Renamed Band' );
$GLOBALS['ec_test']['blogs'][4]['posts'][40] = $renamed;
ec_artist_identity_post_cache_cleaned( 40, new WP_Post( 40, 'page', 'publish', 'x', 'X' ) );
ec_assert( 'publish' === ec_get_artist_identity( array( 'profile_id' => 40 ) )['status'], 'post-cache cleans for other post types do not forget' );
ec_test_reset( 2 );
ec_get_artist_identity( array( 'profile_id' => 40 ) );
$GLOBALS['ec_test']['blogs'][4]['posts'][40] = $renamed;
ec_artist_identity_post_cache_cleaned( 40, $renamed );
ec_assert( 'publish' === ec_get_artist_identity( array( 'profile_id' => 40 ) )['status'], 'post-cache cleans on other blogs do not forget' );
switch_to_blog( 4 );
ec_artist_identity_post_cache_cleaned( 40, $renamed );
restore_current_blog();
$after_clean = ec_get_artist_identity( array( 'profile_id' => 40 ) );
ec_assert( 'private' === $after_clean['status'] && 'Renamed Band' === $after_clean['name'], 'a profile post-cache clean on the artist blog, even when switched in, forgets the record' );

ec_test_reset( 1 );
ec_get_artist_identity( array( 'profile_id' => 40 ) );
$GLOBALS['ec_test']['blogs'][1]['term_meta'][9]['_artist_profile_id'] = '0';
ec_artist_identity_term_meta_changed( 1, 9, '_artist_profile_id' );
ec_assert( false === ec_get_artist_identity( array( 'profile_id' => 40 ) )['binding_valid'], 'a term pointer write on main forgets the profile record' );

ec_test_reset( 1 );
ec_get_artist_identity( array( 'profile_id' => 40 ) );
$GLOBALS['ec_test']['blogs'][1]['terms'][9] = new WP_Term( 9, 'artist', 'the-band', 'The Band (Renamed)' );
ec_artist_identity_term_changed( 9, 9, 'genre' );
ec_assert( 'The Band' === ec_get_artist_identity( array( 'profile_id' => 40 ) )['term_name'], 'edits to other taxonomies do not forget' );
ec_artist_identity_term_changed( 9, 9, 'artist' );
ec_assert( 'The Band (Renamed)' === ec_get_artist_identity( array( 'profile_id' => 40 ) )['term_name'], 'an artist term edit on main forgets dependent records' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} artist identity check(s) failed.\n" );
	exit( 1 );
}

echo "Artist identity checks passed.\n";
