<?php
/**
 * Standalone tests for the Community composer bridge fallback.
 *
 * @package ExtraChillNetwork
 */

declare( strict_types=1 );

// phpcs:disable -- Standalone WordPress topology mocks intentionally share one file.

define( 'ABSPATH', __DIR__ . '/' );

class WP_Term {
	public function __construct( public int $term_id, public string $slug, public string $name ) {}
}

class WP_Site {
	public int $deleted = 0;
	public int $archived = 0;
	public int $spam = 0;
}

$GLOBALS['ec_test_blog_id']          = 1;
$GLOBALS['ec_test_blog_stack']       = array();
$GLOBALS['ec_test_community_site']   = true;
$GLOBALS['ec_test_contract']         = array(
	'schema_version'       => 1,
	'action'               => 'discussion',
	'query_parameters'     => array(
		'action'   => 'compose',
		'taxonomy' => 'entity_taxonomy',
		'slug'     => 'entity_slug',
	),
	'supported_taxonomies' => array( 'location', 'festival', 'artist' ),
);
$GLOBALS['ec_test_community_terms']  = array(
	'artist' => array( 'phish' => new WP_Term( 102, 'phish', 'Phish' ) ),
);
$GLOBALS['ec_test_links']            = array();

function __( string $text ): string { return $text; }
function add_action(): void {}
function apply_filters( string $hook, $value ) { return $value; }
function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_html( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_url( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES ); }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $value ) ); }
function sanitize_title( string $value ): string { return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $value ), '-' ) ); }
function is_wp_error(): bool { return false; }
function get_current_blog_id(): int { return $GLOBALS['ec_test_blog_id']; }
function ec_get_blog_id( string $key ): int { return 'community' === $key ? 2 : 0; }
function ec_get_site_url( string $key ): ?string { return 'community' === $key ? 'https://community.extrachill.com' : null; }
function get_site( int $blog_id ): ?WP_Site { return 2 === $blog_id && $GLOBALS['ec_test_community_site'] ? new WP_Site() : null; }
function get_blog_option( int $blog_id, string $key, $default = false ) {
	return 2 === $blog_id && 'extrachill_community_discussion_composer_contract' === $key
		? $GLOBALS['ec_test_contract']
		: $default;
}
function switch_to_blog( int $blog_id ): void {
	$GLOBALS['ec_test_blog_stack'][] = $GLOBALS['ec_test_blog_id'];
	$GLOBALS['ec_test_blog_id']      = $blog_id;
}
function restore_current_blog(): void { $GLOBALS['ec_test_blog_id'] = array_pop( $GLOBALS['ec_test_blog_stack'] ); }
function get_term_by( string $field, string $slug, string $taxonomy ) {
	return 2 === get_current_blog_id() && 'slug' === $field
		? ( $GLOBALS['ec_test_community_terms'][ $taxonomy ][ $slug ] ?? false )
		: false;
}
function trailingslashit( string $value ): string { return rtrim( $value, '/' ) . '/'; }
function add_query_arg( $args, string $url = '', ?string $base_url = null ): string {
	if ( ! is_array( $args ) ) {
		return (string) $base_url . '?' . $args . '=' . $url;
	}

	$separator = str_contains( $url, '?' ) ? '&' : '?';
	return $url . $separator . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
}
function extrachill_get_cross_site_term_links(): array { return $GLOBALS['ec_test_links']; }

require_once dirname( __DIR__ ) . '/inc/cross-site-links/network-bridge.php';
require_once dirname( __DIR__ ) . '/inc/cross-site-links/renderers.php';

/** Fail with a useful standalone-test message. */
function bridge_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$terms = array( 'artist' => array( new WP_Term( 2, 'phish', 'Phish & Friends' ) ) );

$GLOBALS['ec_test_links'] = array(
	array(
		'site_key'  => 'community',
		'url'       => 'https://community.extrachill.com/artist/phish/',
		'label'     => 'Forum Discussions',
		'term_name' => 'Phish',
		'count'     => 3,
	),
);
$cards = extrachill_network_bridge_build_cards( $terms, array( 'community' ), array( 'community' ), 'blog' );
bridge_assert( str_contains( $cards[0]['url'], '/artist/phish/' ), 'A real Community destination must take priority.' );
bridge_assert( ! str_contains( $cards[0]['url'], 'compose=discussion' ), 'A real destination must not be replaced by the composer.' );

$GLOBALS['ec_test_links'] = array();
$cards                     = extrachill_network_bridge_build_cards( $terms, array( 'community' ), array( 'community' ), 'blog' );
bridge_assert( 1 === count( $cards ), 'The empty Community slot must receive at most one fallback.' );
bridge_assert( 'Start a discussion about Phish & Friends' === $cards[0]['label'], 'The fallback label must describe the verified entity.' );
bridge_assert( str_contains( $cards[0]['url'], 'compose=discussion&entity_taxonomy=artist&entity_slug=phish' ), 'The fallback must use Community composer state.' );
bridge_assert( str_contains( $cards[0]['url'], 'utm_source=blog&utm_medium=network_bridge&utm_campaign=community' ), 'The fallback must retain bridge UTM instrumentation.' );
ob_start();
extrachill_cross_site_link_button( $cards[0], 'network-bridge-link' );
$rendered = ob_get_clean();
bridge_assert( str_contains( $rendered, 'Phish &amp; Friends' ) && str_contains( $rendered, '&amp;utm_medium=' ), 'Fallback labels and URLs must be escaped by the canonical renderer.' );

$unsupported = extrachill_network_bridge_build_cards(
	array( 'post_tag' => array( new WP_Term( 3, 'phish', 'Phish' ) ) ),
	array( 'community' ),
	array( 'community' ),
	'blog'
);
bridge_assert( array() === $unsupported, 'Unsupported taxonomies must fail closed.' );

$missing = extrachill_network_bridge_build_cards(
	array( 'artist' => array( new WP_Term( 4, 'missing', 'Missing Artist' ) ) ),
	array( 'community' ),
	array( 'community' ),
	'blog'
);
bridge_assert( array() === $missing, 'A missing destination term must fail closed.' );

$valid_contract              = $GLOBALS['ec_test_contract'];
$GLOBALS['ec_test_contract'] = null;
$absent                      = extrachill_network_bridge_build_cards( $terms, array( 'community' ), array( 'community' ), 'blog' );
bridge_assert( array() === $absent, 'An absent Community contract marker must fail closed.' );

$GLOBALS['ec_test_contract']                   = $valid_contract;
$GLOBALS['ec_test_contract']['schema_version'] = 2;
$unsupported_version                           = extrachill_network_bridge_build_cards( $terms, array( 'community' ), array( 'community' ), 'blog' );
bridge_assert( array() === $unsupported_version, 'An unsupported Community contract schema must fail closed.' );

$GLOBALS['ec_test_contract']                     = $valid_contract;
$GLOBALS['ec_test_contract']['query_parameters'] = array(
	'action'   => 'compose',
	'taxonomy' => 'entity',
);
$malformed_query_topology                        = extrachill_network_bridge_build_cards( $terms, array( 'community' ), array( 'community' ), 'blog' );
bridge_assert( array() === $malformed_query_topology, 'A malformed query-key contract must fail closed.' );

$GLOBALS['ec_test_contract']                         = $valid_contract;
$GLOBALS['ec_test_contract']['supported_taxonomies'] = array( 'festival', 'location' );
$unsupported_taxonomy_topology                       = extrachill_network_bridge_build_cards( $terms, array( 'community' ), array( 'community' ), 'blog' );
bridge_assert( array() === $unsupported_taxonomy_topology, 'A marker that does not support the entity taxonomy must fail closed.' );
$GLOBALS['ec_test_contract'] = $valid_contract;

$GLOBALS['ec_test_community_site'] = false;
$missing_site                      = extrachill_network_bridge_build_cards( $terms, array( 'community' ), array( 'community' ), 'blog' );
bridge_assert( array() === $missing_site, 'A missing Community site must fail closed.' );
$GLOBALS['ec_test_community_site'] = true;

$not_permitted = extrachill_network_bridge_build_cards( $terms, array( 'events' ), array( 'events' ), 'blog' );
bridge_assert( array() === $not_permitted, 'The fallback must not bypass bridge destination policy or capacity.' );

$composer_url = extrachill_network_bridge_get_community_composer_url( 'artist', 'phish' );
$login_url    = add_query_arg( 'redirect_to', rawurlencode( $composer_url ), 'https://community.extrachill.com/login/' );
parse_str( (string) parse_url( $login_url, PHP_URL_QUERY ), $login_query );
bridge_assert( $composer_url === ( $login_query['redirect_to'] ?? '' ), 'The auth-neutral fallback must survive Community and Users logged-out continuation.' );
bridge_assert( 1 === count( $login_query ), 'Composer state must remain nested inside redirect_to.' );
bridge_assert( ! str_contains( $composer_url, '/?s=' ) && ! str_contains( $composer_url, '/artist/phish/' ), 'The fallback must never synthesize search or archive URLs.' );
bridge_assert( 1 === get_current_blog_id(), 'Destination checks must restore the source blog context.' );

fwrite( STDOUT, "NetworkBridgeComposerFallbackTest passed.\n" );
