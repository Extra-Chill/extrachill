<?php
/**
 * In-memory term meta registry for unit tests.
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Support;

final class TermMetaRegistry {

	/** @var array<string, mixed> */
	private static array $store = array();

	public static function set( int $term_id, string $key, $value ): void {
		self::$store[ self::key( $term_id, $key ) ] = $value;
	}

	public static function get( int $term_id, string $key ) {
		return self::$store[ self::key( $term_id, $key ) ] ?? '';
	}

	public static function reset(): void {
		self::$store = array();
	}

	private static function key( int $term_id, string $key ): string {
		return $term_id . ':' . $key;
	}
}
