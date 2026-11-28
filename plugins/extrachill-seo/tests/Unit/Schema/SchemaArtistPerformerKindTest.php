<?php
/**
 * Artist profile schema follows the artist's performer kind.
 *
 * Music acts publish MusicGroup with genre; comedy acts publish
 * PerformingGroup without genre. The `#musicgroup` @id fragment is stable
 * for every kind so Link Page ProfilePage references keep resolving.
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Schema;

use ExtraChill\SEO\Tests\Support\ArtistProfileDataRegistry;
use PHPUnit\Framework\TestCase;
use WP_Post;

use function ExtraChill\SEO\Schema\ec_seo_artist_schema_type;
use function ExtraChill\SEO\Schema\ec_seo_emit_artist_schema;

require_once dirname( __DIR__, 2 ) . '/Support/ArtistProfileDataRegistry.php';

if ( ! function_exists( 'ec_get_artist_profile_data' ) ) {
	require_once dirname( __DIR__, 2 ) . '/Support/artist-profile-data-stub.php';
}

require_once dirname( __DIR__, 3 ) . '/inc/schema/schema-artist.php';

final class SchemaArtistPerformerKindTest extends TestCase {

	protected function setUp(): void {
		ArtistProfileDataRegistry::reset();
		$GLOBALS['ec_seo_test_is_singular']    = true;
		$GLOBALS['ec_seo_test_queried_object'] = new WP_Post(
			array(
				'ID'        => 13743,
				'post_type' => 'artist_profile',
				'post_name' => 'bobby-baker',
			)
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ec_seo_test_is_singular'], $GLOBALS['ec_seo_test_queried_object'] );
		ArtistProfileDataRegistry::reset();
	}

	public function test_music_artist_emits_music_group_with_genre(): void {
		$entity = $this->emit(
			array(
				'performer_kind' => 'music',
				'schema_type'    => 'MusicGroup',
			)
		);

		$this->assertSame( 'MusicGroup', $entity['@type'] );
		$this->assertSame( get_permalink( 13743 ) . '#musicgroup', $entity['@id'] );
		$this->assertSame( 'Rock', $entity['genre'] );
	}

	public function test_comedy_artist_emits_performing_group_without_genre(): void {
		$entity = $this->emit(
			array(
				'performer_kind' => 'comedy',
				'schema_type'    => 'PerformingGroup',
			)
		);

		$this->assertSame( 'PerformingGroup', $entity['@type'] );
		$this->assertSame( get_permalink( 13743 ) . '#musicgroup', $entity['@id'], 'The @id fragment must stay stable across kinds.' );
		$this->assertSame( 'Bobby Baker', $entity['name'] );
		$this->assertArrayNotHasKey( 'genre', $entity );
	}

	public function test_data_without_performer_kind_keeps_music_group(): void {
		$entity = $this->emit( array() );

		$this->assertSame( 'MusicGroup', $entity['@type'] );
		$this->assertSame( 'Rock', $entity['genre'] );
	}

	public function test_schema_type_resolution_allows_only_performer_types(): void {
		$this->assertSame( 'MusicGroup', ec_seo_artist_schema_type( array() ) );
		$this->assertSame( 'PerformingGroup', ec_seo_artist_schema_type( array( 'schema_type' => 'PerformingGroup' ) ) );
		$this->assertSame( 'MusicGroup', ec_seo_artist_schema_type( array( 'schema_type' => 'Organization' ) ) );
	}

	/**
	 * Stage artist data and return the emitted entity.
	 *
	 * @param array $overrides Artist data overrides.
	 */
	private function emit( array $overrides ): array {
		ArtistProfileDataRegistry::set(
			13743,
			array_merge(
				array(
					'title' => 'Bobby Baker',
					'bio'   => 'Charleston comic.',
					'genre' => 'Rock',
				),
				$overrides
			)
		);

		$graph = ec_seo_emit_artist_schema( array() );
		$this->assertCount( 1, $graph );
		return $graph[0];
	}
}
