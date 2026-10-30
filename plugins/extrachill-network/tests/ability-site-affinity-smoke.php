<?php
/**
 * Standalone tests for the ability -> site affinity primitive.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['asa_cache']              = array();
$GLOBALS['asa_global_groups']      = array();
$GLOBALS['asa_filters']            = array();
$GLOBALS['asa_local_abilities']    = array(
	'extrachill/shared-thing' => true,
	'extrachill/main-only'    => true,
);
$GLOBALS['asa_remote_requests']    = array();
$GLOBALS['asa_remote_pages']       = array(
	'events' => array(
		1 => array(
			array( 'name' => 'extrachill/add-venue' ),
			array( 'name' => 'extrachill/multi-remote-thing' ),
		),
	),
	'wire'   => array(
		1 => array_merge(
			array( array( 'name' => 'extrachill/multi-remote-thing' ) ),
			array_map(
				static function ( $i ) {
					return array( 'name' => 'extrachill/wire-thing-' . $i );
				},
				range( 1, 99 )
			)
		),
		2 => array_map(
			static function ( $i ) {
				return array( 'name' => 'extrachill/wire-thing-' . $i );
			},
			range( 100, 149 )
		),
	),
);

class WP_Error {
	private string $code;
	private string $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function add_action() {}

function add_filter( $hook, $callback ) {
	$GLOBALS['asa_filters'][ $hook ][] = $callback;
}

function remove_filter( $hook, $callback ) {
	if ( ! isset( $GLOBALS['asa_filters'][ $hook ] ) ) {
		return;
	}
	foreach ( $GLOBALS['asa_filters'][ $hook ] as $index => $registered ) {
		if ( $registered === $callback ) {
			unset( $GLOBALS['asa_filters'][ $hook ][ $index ] );
		}
	}
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['asa_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function wp_cache_get( $key, $group = '' ) {
	return $GLOBALS['asa_cache'][ $group ][ $key ] ?? false;
}

function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) {
	unset( $ttl );
	$GLOBALS['asa_cache'][ $group ][ $key ] = $value;
	return true;
}

function wp_cache_delete( $key, $group = '' ) {
	unset( $GLOBALS['asa_cache'][ $group ][ $key ] );
	return true;
}

function wp_cache_flush_group( $group ) {
	unset( $GLOBALS['asa_cache'][ $group ] );
	return true;
}

function wp_cache_add_global_groups( $groups ) {
	$GLOBALS['asa_global_groups'] = array_merge( $GLOBALS['asa_global_groups'], (array) $groups );
}

function get_current_blog_id() {
	return 1; // 'main'.
}

function wp_has_ability( $name ) {
	return isset( $GLOBALS['asa_local_abilities'][ $name ] );
}

function wp_get_abilities( $args = array() ) {
	unset( $args );
	return $GLOBALS['asa_local_abilities'];
}

function ec_get_blog_slug_by_id( $blog_id ) {
	$map = array(
		1  => 'main',
		7  => 'events',
		11 => 'wire',
	);
	return $map[ (int) $blog_id ] ?? null;
}

function ec_get_all_site_ids() {
	return array( 1, 7, 11 );
}

function get_super_admins() {
	return array( 'admin' );
}

function get_user_by( $field, $value ) {
	unset( $field, $value );
	return (object) array( 'ID' => 1 );
}

function ec_cross_site_rest_request( $site_key, $method, $path, $args = array() ) {
	unset( $method );
	$page = $args['query']['page'] ?? 1;

	$GLOBALS['asa_remote_requests'][] = array(
		'site_key' => $site_key,
		'path'     => $path,
		'page'     => $page,
		'per_page' => $args['query']['per_page'] ?? null,
		'fields'   => $args['query']['_fields'] ?? null,
		'user_id'  => $args['user_id'] ?? null,
	);

	if ( ! isset( $GLOBALS['asa_remote_pages'][ $site_key ] ) ) {
		return new WP_Error( 'ec_unknown_site', 'no fixture for site' );
	}

	return $GLOBALS['asa_remote_pages'][ $site_key ][ $page ] ?? array();
}

require_once dirname( __DIR__ ) . '/inc/core/ability-site-affinity.php';

function asa_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

// --- Known ability resolves to the right site key. ------------------------

asa_assert(
	'events' === ec_get_ability_site_affinity( 'extrachill/add-venue' ),
	'a single-owner remote ability resolves to its owning site key'
);

// --- Unknown ability returns null. -----------------------------------------

asa_assert(
	null === ec_get_ability_site_affinity( 'extrachill/does-not-exist-anywhere' ),
	'an ability registered nowhere in the network resolves to null'
);

// --- Local ability short-circuits to null without touching the index. -----

asa_assert(
	null === ec_get_ability_site_affinity( 'extrachill/main-only' ),
	'an ability already registered locally resolves to null (no affinity needed)'
);

// --- Multi-owner ability is null by default, honours the override filter. -

asa_assert(
	null === ec_get_ability_site_affinity( 'extrachill/multi-remote-thing' ),
	'an ability registered on multiple remote sites with no override resolves to null'
);

$override = static function ( $overrides ) {
	$overrides['extrachill/multi-remote-thing'] = 'wire';
	return $overrides;
};
add_filter( 'ec_ability_site_affinity_overrides', $override );

asa_assert(
	'wire' === ec_get_ability_site_affinity( 'extrachill/multi-remote-thing' ),
	'the override filter names a preferred owner for an ambiguous ability'
);

remove_filter( 'ec_ability_site_affinity_overrides', $override );

asa_assert(
	null === ec_get_ability_site_affinity( 'extrachill/multi-remote-thing' ),
	'removing the override reverts to the ambiguous null (overrides are not baked into the cached index)'
);

// --- Pagination collected every page from a remote site. -------------------

$network_abilities = ec_get_network_abilities();
asa_assert(
	array_key_exists( 'extrachill/wire-thing-1', $network_abilities ) && array_key_exists( 'extrachill/wire-thing-149', $network_abilities ),
	'multi-page remote responses are fully collected across pagination'
);
asa_assert(
	'wire' === $network_abilities['extrachill/wire-thing-149'],
	'an item from the second page of a paginated remote response resolves correctly'
);

$wire_requests = array_values( array_filter( $GLOBALS['asa_remote_requests'], static fn( $r ) => 'wire' === $r['site_key'] ) );
asa_assert( 2 === count( $wire_requests ), 'a 150-item remote site is fetched across exactly two pages of 100' );
asa_assert( 100 === $wire_requests[0]['per_page'] && 1 === $wire_requests[0]['page'], 'first page request uses the batch size and page 1' );
asa_assert( 2 === $wire_requests[1]['page'], 'second page request advances the page number' );
asa_assert( 'name' === $wire_requests[0]['fields'], 'remote requests are trimmed to the name field' );
asa_assert( 1 === $wire_requests[0]['user_id'], 'remote requests authenticate as the resolved system user' );

// --- Cache invalidation forces a rebuild. -----------------------------------

$before_flush = count( $GLOBALS['asa_remote_requests'] );
ec_get_network_abilities(); // still cached — no new remote requests.
asa_assert( count( $GLOBALS['asa_remote_requests'] ) === $before_flush, 'a warm cache serves repeated reads without new remote requests' );

ec_flush_network_ability_affinity_cache();
asa_assert( false === wp_cache_get( EC_ABILITY_AFFINITY_CACHE_KEY, EC_ABILITY_AFFINITY_CACHE_GROUP ), 'flushing the cache clears the cached index' );

ec_get_network_abilities();
asa_assert( count( $GLOBALS['asa_remote_requests'] ) > $before_flush, 'a cache miss after flush rebuilds the index with new remote requests' );

// --- The cache group is registered as global. -------------------------------

ec_register_ability_affinity_cache_group();
asa_assert(
	in_array( EC_ABILITY_AFFINITY_CACHE_GROUP, $GLOBALS['asa_global_groups'], true ),
	'the ability affinity cache group is registered as a global group'
);

echo "All ability site affinity tests passed.\n";
