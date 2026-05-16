<?php
/**
 * In-memory term registry for unit tests.
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Support;

final class TermRegistry {

	/** @var array<string, array<int, \WP_Term>> */
	private static array $store = array();

	public static function set( int $post_id, string $taxonomy, array $terms ): void {
		self::$store[ self::key( $post_id, $taxonomy ) ] = $terms;
	}

	/**
	 * @return array<int, \WP_Term>|false
	 */
	public static function get( int $post_id, string $taxonomy ) {
		$key = self::key( $post_id, $taxonomy );
		return self::$store[ $key ] ?? false;
	}

	public static function reset(): void {
		self::$store = array();
	}

	private static function key( int $post_id, string $taxonomy ): string {
		return $post_id . ':' . $taxonomy;
	}
}
