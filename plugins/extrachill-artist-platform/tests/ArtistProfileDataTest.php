<?php

require_once __DIR__ . '/support/base-test-case.php';

final class ArtistProfileDataTest extends EC_Artist_Platform_TestCase {
	private $profile_id;
	private $header_attachment_id;
	private $profile_attachment_id;

	protected function setUp(): void {
		parent::setUp();

		switch_to_blog( $this->artist_blog_id() );
		try {
			$this->profile_id = (int) self::factory()->post->create(
				array(
					'post_type'    => 'artist_profile',
					'post_status'  => 'publish',
					'post_title'   => 'The Chill Band',
					'post_name'    => 'the-chill-band',
					'post_content' => 'A short bio.',
				)
			);

			update_post_meta( $this->profile_id, '_local_city', 'Charleston, SC' );
			update_post_meta(
				$this->profile_id,
				'_artist_profile_social_links',
				array(
					array(
						'type' => 'spotify',
						'url'  => 'https://spotify.com/artist/chill',
					),
				)
			);

			$this->header_attachment_id = (int) self::factory()->attachment->create_object(
				'header.jpg',
				$this->profile_id,
				array(
					'post_mime_type' => 'image/jpeg',
					'post_type'      => 'attachment',
				)
			);
			update_post_meta( $this->profile_id, '_artist_profile_header_image_id', $this->header_attachment_id );

			$this->profile_attachment_id = (int) self::factory()->attachment->create_object(
				'profile.jpg',
				$this->profile_id,
				array(
					'post_mime_type' => 'image/jpeg',
					'post_type'      => 'attachment',
				)
			);
			set_post_thumbnail( $this->profile_id, $this->profile_attachment_id );

			$genre_term = get_term_by( 'slug', 'psych-rock', 'genre' );
			if ( $genre_term ) {
				$genre_term_id = (int) $genre_term->term_id;
			} else {
				$created       = wp_insert_term( 'Psych rock', 'genre' );
				$genre_term_id = is_wp_error( $created ) ? 0 : (int) $created['term_id'];
			}
			wp_set_object_terms( $this->profile_id, array( $genre_term_id ), 'genre', false );
		} finally {
			restore_current_blog();
		}
	}

	public function test_returns_the_complete_canonical_profile_fields(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			$data = ec_get_artist_profile_data( $this->profile_id );

			$this->assertSame( $this->profile_id, $data['artist_id'] );
			$this->assertSame( 'The Chill Band', $data['title'] );
			$this->assertSame( 'the-chill-band', $data['slug'] );
			$this->assertSame( get_permalink( $this->profile_id ), $data['permalink'] );
			$this->assertSame( 'A short bio.', $data['bio'] );
			$this->assertSame( array( 'psych-rock' ), $data['genres'] );
			$this->assertSame( array( 'Psych rock' ), $data['genre_labels'] );
			$this->assertSame( 'Charleston, SC', $data['local_city'] );
			$this->assertSame( '', $data['website_url'] );
			$this->assertSame( '', $data['spotify_url'] );
			$this->assertSame( '', $data['apple_music_url'] );
			$this->assertSame( '', $data['bandcamp_url'] );
			$this->assertSame(
				array(
					array(
						'type' => 'spotify',
						'url'  => 'https://spotify.com/artist/chill',
					),
				),
				$data['social_links']
			);
			$this->assertSame( $this->header_attachment_id, (int) $data['header_image_id'] );
			$this->assertSame( wp_get_attachment_url( $this->header_attachment_id ), $data['header_image_url'] );
			$this->assertSame( $this->profile_attachment_id, (int) $data['profile_image_id'] );
			$this->assertSame(
				get_the_post_thumbnail_url( $this->profile_id, 'large' ),
				$data['profile_image_url']
			);
			$this->assertSame( 0, $data['link_page_id'] );
		} finally {
			restore_current_blog();
		}
	}

	public function test_public_ability_returns_only_published_profiles_and_official_links(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			$result = extrachill_artist_platform_ability_artist_get( array( 'id' => $this->profile_id ) );

			$this->assertSame( get_permalink( $this->profile_id ), $result['permalink'] );
			$this->assertSame(
				array(
					array(
						'type' => 'spotify',
						'url'  => 'https://spotify.com/artist/chill',
					),
				),
				$result['official_links']
			);

			wp_update_post(
				array(
					'ID'          => $this->profile_id,
					'post_status' => 'draft',
				)
			);
			clean_post_cache( $this->profile_id );

			$result = extrachill_artist_platform_ability_artist_get( array( 'id' => $this->profile_id ) );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'invalid_artist', $result->get_error_code() );
		} finally {
			restore_current_blog();
		}
	}
}
