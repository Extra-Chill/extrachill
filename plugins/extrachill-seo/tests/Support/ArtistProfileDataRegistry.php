<?php
/**
 * Test double for extrachill-artist-platform's ec_get_artist_profile_data().
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Support;

/**
 * Stores artist profile data keyed by post ID.
 */
final class ArtistProfileDataRegistry {

	/**
	 * Staged artist data.
	 *
	 * @var array<int, array>
	 */
	private static $data = array();

	public static function reset(): void {
		self::$data = array();
	}

	public static function set( int $artist_id, array $data ): void {
		self::$data[ $artist_id ] = $data;
	}

	public static function get( int $artist_id ): array {
		return self::$data[ $artist_id ] ?? array();
	}
}
