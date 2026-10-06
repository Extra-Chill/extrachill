<?php
/**
 * File-level WP doubles owned by the priority-promoter-unit suite (#942).
 *
 * Kept local to this suite for the same reason documented in
 * promoted-events-stubs.php: sibling pure suites define the same symbols with
 * their own expectations, and whichever doubles file loads first in a process
 * wins. Every double is guarded so a real WordPress runtime skips them.
 *
 * State lives in $GLOBALS['priority_promoter_test'] so each test seeds and
 * resets it explicitly.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- Test stub intentionally mixes WP function shims with a fake $wpdb.

/** Reset the in-memory WordPress state used by the doubles. */
function priority_promoter_test_reset(): void {
	$GLOBALS['priority_promoter_test'] = array(
		'cache'        => array(),
		'term_meta'    => array(),
		'post_meta'    => array(),
		'post_types'   => array(),
		'object_terms' => array(),
		'terms'        => array(),
		'can'          => true,
		'abilities'    => array(),
	);
}

if ( ! isset( $GLOBALS['priority_promoter_test'] ) ) {
	priority_promoter_test_reset();
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '' ) {
		return $GLOBALS['priority_promoter_test']['cache'][ $group ][ $key ] ?? false;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
		unset( $expire );
		$GLOBALS['priority_promoter_test']['cache'][ $group ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		unset( $GLOBALS['priority_promoter_test']['cache'][ $group ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key = '', $single = false ) {
		unset( $single );
		return $GLOBALS['priority_promoter_test']['term_meta'][ (int) $term_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $key, $value ) {
		$GLOBALS['priority_promoter_test']['term_meta'][ (int) $term_id ][ $key ] = true === $value ? '1' : $value;
		return true;
	}
}

if ( ! function_exists( 'delete_term_meta' ) ) {
	function delete_term_meta( $term_id, $key ) {
		unset( $GLOBALS['priority_promoter_test']['term_meta'][ (int) $term_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		unset( $single );
		return $GLOBALS['priority_promoter_test']['post_meta'][ (int) $post_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['priority_promoter_test']['post_meta'][ (int) $post_id ][ $key ] = true === $value ? '1' : $value;
		return true;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post_id = null ) {
		return $GLOBALS['priority_promoter_test']['post_types'][ (int) $post_id ] ?? false;
	}
}

if ( ! function_exists( 'wp_get_object_terms' ) ) {
	function wp_get_object_terms( $object_id, $taxonomy, $args = array() ) {
		unset( $args );
		return $GLOBALS['priority_promoter_test']['object_terms'][ (int) $object_id ][ $taxonomy ] ?? array();
	}
}

if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args = array() ) {
		$terms = $GLOBALS['priority_promoter_test']['terms'];

		if ( ! empty( $args['meta_query'][0]['key'] ) ) {
			$key   = $args['meta_query'][0]['key'];
			$terms = array_filter(
				$terms,
				static function ( $term ) use ( $key ) {
					return '1' === ( $GLOBALS['priority_promoter_test']['term_meta'][ $term->term_id ][ $key ] ?? '' );
				}
			);
		}

		if ( ! empty( $args['include'] ) ) {
			$include = array_map( 'intval', $args['include'] );
			$terms   = array_filter(
				$terms,
				static function ( $term ) use ( $include ) {
					return in_array( $term->term_id, $include, true );
				}
			);
		}

		if ( 'ids' === ( $args['fields'] ?? '' ) ) {
			return array_values( array_map( 'strval', array_keys( $terms ) ) );
		}

		return array_values( $terms );
	}
}

if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy = '' ) {
		unset( $taxonomy );
		return $GLOBALS['priority_promoter_test']['terms'][ (int) $term_id ] ?? null;
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy = '' ) {
		unset( $taxonomy );
		foreach ( $GLOBALS['priority_promoter_test']['terms'] as $term ) {
			if ( 'slug' === $field && $term->slug === $value ) {
				return $term;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		unset( $capability );
		return (bool) $GLOBALS['priority_promoter_test']['can'];
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $args ) {
		$GLOBALS['priority_promoter_test']['abilities'][ $name ] = $args;
		return true;
	}
}

/**
 * Fake $wpdb — records the backfill query and returns seeded post IDs.
 * Sufficient for the backfill's single SELECT shape only.
 */
class PriorityPromoterFakeWpdb {

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
