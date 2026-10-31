<?php
/**
 * File-level WP doubles owned by the promoted-events-unit suite (#866).
 *
 * Kept local to this suite for the same reason documented in
 * phpunit-archive-layout-unit.xml.dist and archive-layout-stubs.php: sibling
 * pure suites define the same symbols with their own expectations, and
 * whichever doubles file loads first in a process wins. Every double here is
 * guarded so the managed (real-WordPress) suite skips these definitions
 * entirely.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- Test stub intentionally mixes WP function shims, a fake $wpdb, and fake data-machine-events doubles.

/**
 * Minimal in-memory object cache. Real caching semantics (get/set/delete,
 * per-group buckets) are required for the self-invalidating cache-key test —
 * a no-op double would hide the exact bug this suite exists to catch.
 */
if ( ! isset( $GLOBALS['promoted_events_test_cache'] ) ) {
	$GLOBALS['promoted_events_test_cache'] = array();
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '' ) {
		return $GLOBALS['promoted_events_test_cache'][ $group ][ $key ] ?? false;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
		unset( $expire );
		$GLOBALS['promoted_events_test_cache'][ $group ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		unset( $GLOBALS['promoted_events_test_cache'][ $group ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'is_tax' ) ) {
	function is_tax( $taxonomies = array() ) {
		unset( $taxonomies );
		return (bool) ( $GLOBALS['test_is_tax'] ?? false );
	}
}

if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() {
		return $GLOBALS['test_queried_object'] ?? null;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return (bool) ( $GLOBALS['test_is_user_logged_in'] ?? false );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) ( $GLOBALS['test_current_user_id'] ?? 0 );
	}
}

/**
 * Fake for extrachill-users' extrachill_users_get_local_scene(). Reads a
 * fixture from $GLOBALS['test_local_scene'] so every test in the suite
 * controls it explicitly rather than racing on first-declaration order.
 */
if ( ! function_exists( 'extrachill_users_get_local_scene' ) ) {
	function extrachill_users_get_local_scene( int $user_id ) {
		unset( $user_id );
		return $GLOBALS['test_local_scene'] ?? null;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post_id ) {
		return 'https://events.example/e/' . (int) $post_id . '/';
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post_id ) {
		return $GLOBALS['test_post_titles'][ (int) $post_id ] ?? ( 'Event ' . (int) $post_id );
	}
}

if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( $post_id, $taxonomy ) {
		unset( $taxonomy );
		return $GLOBALS['test_post_venues'][ (int) $post_id ] ?? false;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name ) {
		unset( $name );
		return 'F j, Y';
	}
}

if ( ! function_exists( 'date_i18n' ) ) {
	function date_i18n( $format, $timestamp ) {
		return gmdate( $format, $timestamp );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return $url;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) {
		unset( $domain );
		echo esc_html( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html() stub applied immediately above.
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		unset( $domain );
		return esc_html( $text );
	}
}

/**
 * Fake $wpdb — records every prepared query and returns a seeded post_id
 * list. Sufficient for the resolver's single SELECT shape; NOT a
 * general-purpose mock.
 */
class PromotedEventsFakeWpdb {

	public string $prefix             = 'wp_';
	public string $posts              = 'wp_posts';
	public string $term_relationships = 'wp_term_relationships';
	public string $term_taxonomy      = 'wp_term_taxonomy';

	/** @var array<int,string> */
	public array $queries = array();

	/** @var int[] */
	public array $seeded_post_ids = array();

	public function prepare( string $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$replacement = is_string( $arg ) ? "'" . $arg . "'" : (string) $arg;
			$query       = preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function get_col( string $query ): array {
		$this->queries[] = $query;
		return array_map( 'strval', $this->seeded_post_ids );
	}
}
