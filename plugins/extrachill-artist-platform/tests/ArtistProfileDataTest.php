<?php

use PHPUnit\Framework\TestCase;

final class ArtistProfileDataTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array(
			'posts'      => array(
				12 => (object) array(
					'post_type'    => 'artist_profile',
					'post_status'  => 'publish',
					'post_title'   => 'The Chill Band',
					'post_name'    => 'the-chill-band',
					'post_content' => 'A short bio.',
				),
			),
			'meta'       => array(
				12 => array(
					'_genre'                          => array( 'Psych rock' ),
					'_local_city'                     => array( 'Charleston, SC' ),
					'_artist_profile_header_image_id' => array( 34 ),
					'_artist_profile_social_links'    => array( array( array( 'type' => 'spotify', 'url' => 'https://spotify.com/artist/chill' ) ) ),
				),
			),
			'thumbnails' => array( 12 => 56 ),
		);
	}

	public function test_returns_the_complete_canonical_profile_fields(): void {
		$this->assertSame(
			array(
				'artist_id'         => 12,
				'title'             => 'The Chill Band',
				'slug'              => 'the-chill-band',
				'permalink'         => 'https://artist.example/artists/the-chill-band/',
				'bio'               => 'A short bio.',
				'genre'             => 'Psych rock',
				'local_city'        => 'Charleston, SC',
				'website_url'       => '',
				'spotify_url'       => '',
				'apple_music_url'   => '',
				'bandcamp_url'      => '',
				'social_links'      => array( array( 'type' => 'spotify', 'url' => 'https://spotify.com/artist/chill' ) ),
				'header_image_id'   => 34,
				'header_image_url'  => 'https://artist.example/media/34.jpg',
				'profile_image_id'  => 56,
				'profile_image_url' => 'https://artist.example/media/56.jpg',
				'link_page_id'      => 0,
			),
			ec_get_artist_profile_data( 12 )
		);
	}

	public function test_public_ability_returns_only_published_profiles_and_official_links(): void {
		$result = extrachill_artist_platform_ability_artist_get( array( 'id' => 12 ) );

		$this->assertSame( 'https://artist.example/artists/the-chill-band/', $result['permalink'] );
		$this->assertSame(
			array( array( 'type' => 'spotify', 'url' => 'https://spotify.com/artist/chill' ) ),
			$result['official_links']
		);

		$GLOBALS['ec_test']['posts'][12]->post_status = 'draft';
		$result = extrachill_artist_platform_ability_artist_get( array( 'id' => 12 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_artist', $result->get_error_code() );
	}
}
