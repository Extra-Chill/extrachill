<?php
/**
 * In-memory venue data registry for unit tests.
 *
 * Stand-in for the data-machine-events public integration API's
 * `data_machine_events_get_venue_data()` return shape.
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Support;

final class VenueDataRegistry {

	/** @var array<int, array<string, mixed>> */
	private static array $store = array();

	public static function set( int $term_id, array $data ): void {
		self::$store[ $term_id ] = $data;
	}

	public static function get( int $term_id ): ?array {
		return self::$store[ $term_id ] ?? null;
	}

	public static function reset(): void {
		self::$store = array();
	}
}
