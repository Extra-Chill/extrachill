<?php
/**
 * Standalone tests for cross-site-links generation-key cache invalidation.
 *
 * Covers inc/cross-site-links/taxonomy-links.php:
 *   - the cross-site links group registers as a network-global group,
 *   - every cache key is generation-prefixed via one helper,
 *   - invalidation bumps an O(1) generation counter (no wp_cache_flush_group),
 *   - bumps fire on publish-state transitions and deletes only,
 *   - bumps coalesce across a request batch and re-arm on external bumps.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['csl_actions']            = array();
$GLOBALS['csl_cache']              = array();
$GLOBALS['csl_global_groups']      = array();
$GLOBALS['csl_incr_fails']         = false;
$GLOBALS['csl_autosave_ids']       = array();
$GLOBALS['csl_revision_ids']       = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['csl_actions'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	add_action( $hook, $callback, $priority, $accepted_args );
}

function apply_filters( $hook, $value ) {
	return $value;
}

function wp_cache_get( $key, $group = '' ) {
	return $GLOBALS['csl_cache'][ $group ][ $key ] ?? false;
}

function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) {
	$GLOBALS['csl_cache'][ $group ][ $key ] = $value;
	return true;
}

function wp_cache_add( $key, $value, $group = '', $ttl = 0 ) {
	if ( isset( $GLOBALS['csl_cache'][ $group ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['csl_cache'][ $group ][ $key ] = $value;
	return true;
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	if ( $GLOBALS['csl_incr_fails'] || ! isset( $GLOBALS['csl_cache'][ $group ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['csl_cache'][ $group ][ $key ] += $offset;
	return $GLOBALS['csl_cache'][ $group ][ $key ];
}

function wp_cache_add_global_groups( $groups ) {
	foreach ( (array) $groups as $group ) {
		$GLOBALS['csl_global_groups'][] = $group;
	}
}

function wp_is_post_autosave( $post ) {
	$id = is_object( $post ) ? $post->ID : (int) $post;
	return in_array( $id, $GLOBALS['csl_autosave_ids'], true );
}

function wp_is_post_revision( $post ) {
	$id = is_object( $post ) ? $post->ID : (int) $post;
	return in_array( $id, $GLOBALS['csl_revision_ids'], true );
}

function is_wp_error( $thing ) {
	return false;
}

function get_term( $term_id, $taxonomy = '' ) {
	$term       = new stdClass();
	$term->term_id = $term_id;
	return $term;
}

function extrachill_get_current_site_key() {
	return 'main';
}

function extrachill_get_taxonomy_site_map() {
	return array();
}

class WP_Post {
	public $ID;
	public $post_type;

	public function __construct( $id, $post_type ) {
		$this->ID        = $id;
		$this->post_type = $post_type;
	}
}

function csl_assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function csl_generation(): int {
	return (int) ( $GLOBALS['csl_cache'][ EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP ]['generation'] ?? 0 );
}

function csl_make_post( int $id, string $post_type ): WP_Post {
	return new WP_Post( $id, $post_type );
}

function csl_transition( string $new, string $old, WP_Post $post ): void {
	extrachill_cross_site_links_maybe_flush_on_transition( $new, $old, $post );
}

function csl_delete( int $id, ?WP_Post $post ): void {
	extrachill_cross_site_links_maybe_flush_on_delete( $id, $post );
}

require_once dirname( __DIR__ ) . '/inc/cross-site-links/taxonomy-links.php';

// The init callback registers the group as network-global.
foreach ( $GLOBALS['csl_actions']['init'] as $entry ) {
	$entry['callback']();
}
csl_assert_same( array( EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP ), $GLOBALS['csl_global_groups'], 'Cross-site links group registers as a global group.' );

// Hook contract: transition + delete register; save_post is gone.
$hooks = array_map(
	static fn ( array $entry ): string => $entry['callback'],
	$GLOBALS['csl_actions']['transition_post_status'] ?? array()
);
csl_assert_same( array( 'extrachill_cross_site_links_maybe_flush_on_transition' ), $hooks, 'Invalidation registers on transition_post_status.' );
csl_assert_same( false, isset( $GLOBALS['csl_actions']['save_post'] ), 'save_post no longer registers an invalidation hook.' );
csl_assert_same( true, ! empty( $GLOBALS['csl_actions']['deleted_post'] ), 'deleted_post still registers an invalidation hook.' );

// Cold cache initializes generation 1 and prefixes every key with it.
csl_assert_same( 'g1:links_artist_12_main', extrachill_cross_site_links_cache_key( 'links_artist_12_main' ), 'Cold generation initializes to 1 and prefixes keys.' );

// The generation is read once per request (static).
wp_cache_set( 'generation', 42, EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP );
csl_assert_same( 'g1:links_artist_12_main', extrachill_cross_site_links_cache_key( 'links_artist_12_main' ), 'Generation is read once per request.' );
wp_cache_set( 'generation', 1, EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP );

// The cached-read path serves primed entries under the generation-prefixed key.
$sentinel = array( array( 'site_key' => 'events', 'count' => 3 ) );
wp_cache_set( 'g1:links_artist_12_main', $sentinel, EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP, 3600 );
csl_assert_same( $sentinel, extrachill_get_cross_site_term_links( 12, 'artist' ), 'Cached read resolves the generation-prefixed key.' );

// No publish-state change, no bump: update of an already-published post.
csl_transition( 'publish', 'publish', csl_make_post( 101, 'post' ) );
csl_assert_same( 1, csl_generation(), 'Re-publishing an already-published post does not bump.' );

// No publish-state change at all.
csl_transition( 'pending', 'draft', csl_make_post( 102, 'post' ) );
csl_assert_same( 1, csl_generation(), 'Draft-to-pending transition does not bump.' );

// Post type cannot affect cross-site link answers.
csl_transition( 'publish', 'draft', csl_make_post( 103, 'nav_menu_item' ) );
csl_assert_same( 1, csl_generation(), 'Non-invalidating post type does not bump.' );

// Autosave and revision guards.
$GLOBALS['csl_autosave_ids'][] = 104;
$GLOBALS['csl_revision_ids'][] = 105;
csl_transition( 'publish', 'draft', csl_make_post( 104, 'post' ) );
csl_transition( 'publish', 'draft', csl_make_post( 105, 'post' ) );
csl_assert_same( 1, csl_generation(), 'Autosaves and revisions do not bump.' );

// Delete of a non-invalidating post type.
csl_delete( 103, csl_make_post( 103, 'nav_menu_item' ) );
csl_assert_same( 1, csl_generation(), 'Delete of a non-invalidating post type does not bump.' );

// (a) A real publish transition bumps the generation and changes the built key.
csl_transition( 'publish', 'draft', csl_make_post( 201, 'data_machine_events' ) );
csl_assert_same( 2, csl_generation(), 'Publish transition bumps the generation.' );
csl_assert_same( 'g2:links_artist_12_main', extrachill_cross_site_links_cache_key( 'links_artist_12_main' ), 'Built keys change after the bump.' );

// Invalidation actually orphans the previous generation's cached entry.
csl_assert_same( array(), extrachill_get_cross_site_term_links( 12, 'artist' ), 'Previous-generation cache entries are no longer served.' );

// Coalescing: a batch of inserts in one request bumps once.
for ( $i = 0; $i < 5; $i++ ) {
	csl_transition( 'publish', 'draft', csl_make_post( 300 + $i, 'data_machine_events' ) );
}
csl_assert_same( 2, csl_generation(), 'A batch of publish transitions bumps once per request.' );

// An external bump (another worker/site) re-arms the coalescing guard.
wp_cache_set( 'generation', 9, EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP );
csl_transition( 'publish', 'draft', csl_make_post( 310, 'post' ) );
csl_assert_same( 10, csl_generation(), 'Coalescing guard re-arms after an external generation change.' );

// Unpublishing also bumps (publish was the old status).
wp_cache_set( 'generation', 20, EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP );
csl_transition( 'trash', 'publish', csl_make_post( 202, 'festival_wire' ) );
csl_assert_same( 21, csl_generation(), 'Unpublish transition bumps the generation.' );

// wp_cache_incr failure falls back to wp_cache_set with generation + 1.
$GLOBALS['csl_incr_fails'] = true;
wp_cache_set( 'generation', 100, EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP );
csl_transition( 'publish', 'draft', csl_make_post( 311, 'topic' ) );
csl_assert_same( 101, csl_generation(), 'Incr failure falls back to set with generation + 1.' );
$GLOBALS['csl_incr_fails'] = false;

// Hard delete of an invalidating post type bumps.
wp_cache_set( 'generation', 500, EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP );
csl_delete( 400, csl_make_post( 400, 'product' ) );
csl_assert_same( 501, csl_generation(), 'Delete of an invalidating post type bumps the generation.' );

// Legacy deleted_post callers may pass no post object; that still bumps.
wp_cache_set( 'generation', 700, EXTRACHILL_CROSS_SITE_LINKS_CACHE_GROUP );
csl_delete( 401, null );
csl_assert_same( 701, csl_generation(), 'Delete without a post object bumps (legacy behavior kept).' );

// (c) No wp_cache_flush_group call remains in cross-site-links code
// (comments and docblocks that reference the contract are stripped before
// matching). Scoped to inc/cross-site-links/ because unrelated inc/ code
// outside this contract still flushes its own groups (tracked follow-up).
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__ . '/../inc/cross-site-links' ) );
foreach ( $files as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}
	$contents = file_get_contents( $file->getPathname() );
	$code     = preg_replace( '#/\*.*?\*/#s', '', (string) $contents );
	$code     = preg_replace( '#^\s*//.*$#m', '', (string) $code );
	if ( false !== strpos( (string) $code, 'wp_cache_flush_group' ) ) {
		fwrite( STDERR, "FAIL: wp_cache_flush_group remains in {$file->getPathname()}\n" );
		exit( 1 );
	}
}

fwrite( STDOUT, "Cross-site links generation invalidation tests passed.\n" );
