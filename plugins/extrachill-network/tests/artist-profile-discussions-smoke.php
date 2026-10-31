<?php
/**
 * Standalone topology checks for network-owned artist discussions.
 *
 * @package ExtraChillNetwork
 */

declare( strict_types=1 );

// phpcs:disable -- Standalone WordPress topology mocks intentionally share one file.

define( 'ABSPATH', __DIR__ . '/' );

class WP_Term {
	public function __construct( public int $term_id, public string $slug, public string $name ) {}
}

class WP_Post {
	public function __construct(
		public int $ID,
		public string $post_type,
		public string $post_status,
		public string $post_name,
		public string $post_title
	) {}
}

class WP_Site {
	public int $deleted = 0;
	public int $archived = 0;
	public int $spam = 0;
}

class WP_Query {
	public array $posts;

	public function __construct( array $args ) {
		$GLOBALS['ec_test_queries'][] = $args;
		$this->posts                  = $GLOBALS['ec_test_topics'];
	}
}

$GLOBALS['ec_test_blog_id'] = 4;
$GLOBALS['ec_test_filters'] = array();
$GLOBALS['ec_test_queries'] = array();
$GLOBALS['ec_test_route']   = true;
$GLOBALS['ec_test_topics']  = array(
	new WP_Post( 91, 'topic', 'publish', 'first-topic', 'First &amp; Topic' ),
	new WP_Post( 82, 'topic', 'closed', 'closed-topic', 'Closed Topic' ),
	new WP_Post( 74, 'topic', 'publish', '%e3%83%86%e3%82%b9%e3%83%88', 'Japanese Test' ),
	new WP_Post( 73, 'topic', 'private', 'private-topic', 'Private Topic' ),
);

function add_filter( string $hook, string $callback, int $priority, int $accepted_args ): void {
	$GLOBALS['ec_test_filters'][] = compact( 'hook', 'callback', 'priority', 'accepted_args' );
}

function __( string $text ): string { return $text; }
function esc_html__( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_url( string $url ): string { return htmlspecialchars( $url, ENT_QUOTES ); }
function esc_html_e( string $text ): void { echo esc_html( $text ); }
function is_wp_error(): bool { return false; }
function sanitize_title( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', trim( $value ) ) ); }
function trailingslashit( string $value ): string { return rtrim( $value, '/' ) . '/'; }
function user_trailingslashit( string $value ): string { return trailingslashit( $value ); }
function get_current_blog_id(): int { return $GLOBALS['ec_test_blog_id']; }
function get_post_type( int $post_id ): string { return in_array( $post_id, array( 700, 701, 702 ), true ) ? 'artist_profile' : 'post'; }
function get_site( int $blog_id ): ?WP_Site { return 2 === $blog_id ? new WP_Site() : null; }
function get_site_url( int $blog_id ): string { return 2 === $blog_id ? 'https://community.example' : 'https://example.test'; }
function ec_get_blog_id( string $key ): int { return array( 'main' => 1, 'community' => 2 )[ $key ] ?? 0; }

function switch_to_blog( int $blog_id ): void {
	$GLOBALS['ec_test_blog_stack'][] = $GLOBALS['ec_test_blog_id'];
	$GLOBALS['ec_test_blog_id']      = $blog_id;
}

function restore_current_blog(): void {
	$GLOBALS['ec_test_blog_id'] = array_pop( $GLOBALS['ec_test_blog_stack'] );
}

function taxonomy_exists( string $taxonomy ): bool { return 'artist' === $taxonomy; }

function get_term( int $term_id, string $taxonomy ) {
	if ( 1 !== get_current_blog_id() || 'artist' !== $taxonomy ) {
		return false;
	}

	return match ( $term_id ) {
		55 => new WP_Term( 55, 'kid-lake', 'Kid Lake' ),
		56 => new WP_Term( 56, 'quiet-artist', 'Quiet Artist' ),
		57 => new WP_Term( 57, 'missing-artist', 'Missing Artist' ),
		default => false,
	};
}

function get_term_by( string $field, string $slug, string $taxonomy ) {
	if ( 2 !== get_current_blog_id() || 'slug' !== $field || 'artist' !== $taxonomy ) {
		return false;
	}

	return match ( $slug ) {
		'kid-lake' => new WP_Term( 155, 'kid-lake', 'Kid Lake' ),
		'quiet-artist' => new WP_Term( 156, 'quiet-artist', 'Quiet Artist' ),
		default => false,
	};
}

function get_term_link( WP_Term $term ): string {
	return 'https://community.example/artist/' . $term->slug . '/?source="profile';
}

function get_option( string $key, $default = false ) {
	$options = array(
		'_bbp_topic_slug'   => 't',
		'_bbp_include_root' => false,
		'rewrite_rules'     => $GLOBALS['ec_test_route']
			? array( 't/([^/]+)/?$' => 'index.php?topic=$matches[1]' )
			: array(),
	);

	return $options[ $key ] ?? $default;
}

function get_the_title( WP_Post $post ): string { return $post->post_title; }
function get_the_date( string $format, WP_Post $post ): string { return 'July ' . $post->ID; }
function get_bloginfo( string $show ): string { return 'UTF-8'; }
function wp_strip_all_tags( string $text ): string { return strip_tags( $text ); }

require_once dirname( __DIR__ ) . '/inc/integrations/artist-profile-discussions.php';

/** Fail the standalone process with a useful message. */
function ec_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

ec_test_assert( ! function_exists( 'bbp_get_topic_post_type' ), 'The Artist-request test must not load bbPress functions.' );
ec_test_assert(
	array(
		'hook'          => 'ec_artist_profile_sections',
		'callback'      => 'extrachill_network_register_artist_discussions_section',
		'priority'      => 10,
		'accepted_args' => 3,
	) === $GLOBALS['ec_test_filters'][0],
	'Network must register through the Artist section seam when bbPress is absent.'
);

$sections = extrachill_network_register_artist_discussions_section( array() );
ec_test_assert( 'discussions' === $sections[0]['id'] && 50 === $sections[0]['priority'], 'Discussions must follow the existing profile sections.' );

$data = extrachill_network_get_artist_discussions( 700, 55 );
ec_test_assert( 'https://community.example/artist/kid-lake/?source="profile' === $data['archive_url'], 'The Community term link must remain the canonical archive.' );
ec_test_assert( 3 === count( $data['topics'] ), 'Only public and closed topics with canonical routes should be rendered.' );
ec_test_assert( 'https://community.example/t/first-topic/' === $data['topics'][0]['url'], 'Topic URLs must use the persisted canonical route.' );
ec_test_assert( 'https://community.example/t/closed-topic/' === $data['topics'][1]['url'], 'Closed topics must remain publicly visible.' );
ec_test_assert( 'https://community.example/t/%e3%83%86%e3%82%b9%e3%83%88/' === $data['topics'][2]['url'], 'WordPress percent-encoded non-Latin slugs must not be encoded twice.' );
ec_test_assert( 4 === $GLOBALS['ec_test_queries'][0]['posts_per_page'], 'Topic output must be bounded to four.' );
ec_test_assert( array( 'publish', 'closed' ) === $GLOBALS['ec_test_queries'][0]['post_status'], 'The query must match Community public statuses.' );
ec_test_assert( 155 === $GLOBALS['ec_test_queries'][0]['tax_query'][0]['terms'], 'The Community-local term ID must be used instead of the main-site ID.' );
ec_test_assert( true === $GLOBALS['ec_test_queries'][0]['no_found_rows'] && false === $GLOBALS['ec_test_queries'][0]['update_post_meta_cache'], 'The bounded query must skip unnecessary count and meta work.' );
ec_test_assert( 4 === get_current_blog_id(), 'Cross-site lookups must restore the Artist blog context.' );

ob_start();
extrachill_network_render_artist_discussions_section( 700, 55 );
$rendered = ob_get_clean();
ec_test_assert( str_contains( $rendered, 'First &amp; Topic' ), 'Topic titles must be normalized and escaped once.' );
ec_test_assert( str_contains( $rendered, '?source=&quot;profile' ), 'Canonical archive output must be escaped.' );

$GLOBALS['ec_test_route']  = false;
$GLOBALS['ec_test_topics'] = array();
$missing_route             = extrachill_network_get_artist_discussions( 701, 56 );
ec_test_assert( '' === $missing_route['archive_url'] && array() === $missing_route['topics'], 'A missing destination post-type route must fail closed.' );

$missing_term = extrachill_network_get_artist_discussions( 702, 57 );
ec_test_assert( '' === $missing_term['archive_url'], 'A missing Community term must fail closed.' );

fwrite( STDOUT, "ArtistProfileDiscussionsTest passed.\n" );
