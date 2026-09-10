<?php
/**
 * Test registry backing the data-machine-events affiliate helper stubs.
 *
 * Mirrors the real helper contracts so resolution tests are meaningful:
 * `unwrap()` returns the input unchanged when no mapping exists, exactly
 * like `datamachine_unwrap_affiliate_url()` does for URLs it cannot parse.
 *
 * Fixture URLs deliberately use neutral example hosts so no affiliate host
 * or vendor literals appear anywhere in this plugin.
 *
 * @package ExtraChill\SEO\Tests\Support
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Support;

final class TicketUrlRegistry {

	/** @var array<int, string> URLs reported as affiliate-wrapped. */
	private static array $affiliate_urls = array();

	/** @var array<string, string> Unwrap mappings: wrapper URL => destination. */
	private static array $unwrap_map = array();

	public static function set_affiliate_urls( array $urls ): void {
		self::$affiliate_urls = array_values( $urls );
	}

	public static function set_unwrap_map( array $map ): void {
		self::$unwrap_map = $map;
	}

	public static function reset(): void {
		self::$affiliate_urls = array();
		self::$unwrap_map     = array();
	}

	public static function is_affiliate( string $url ): bool {
		return in_array( $url, self::$affiliate_urls, true );
	}

	public static function unwrap( string $url ): string {
		return self::$unwrap_map[ $url ] ?? $url;
	}
}
