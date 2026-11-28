<?php

require_once __DIR__ . '/support/base-test-case.php';

final class ArtistPerformerKindTest extends EC_Artist_Platform_TestCase {
	private $profile_id;

	protected function setUp(): void {
		parent::setUp();
		$this->profile_id = $this->create_artist_profile( 'Bobby Baker', array( 'post_name' => 'bobby-baker' ) );
	}

	public function test_resolver_maps_each_kind_to_its_schema_type(): void {
		$this->assertSame( 'MusicGroup', ec_artist_performer_kind_schema_type( 'music' ) );
		$this->assertSame( 'PerformingGroup', ec_artist_performer_kind_schema_type( 'comedy' ) );
		$this->assertSame( 'MusicGroup', ec_artist_performer_kind_schema_type( '' ) );
		$this->assertSame( 'MusicGroup', ec_artist_performer_kind_schema_type( 'opera' ) );
		$this->assertSame( 'MusicGroup', ec_artist_performer_kind_schema_type( null ) );
		$this->assertSame( array( 'music', 'comedy' ), array_keys( ec_artist_performer_kinds() ) );
	}

	public function test_genre_is_music_only(): void {
		$this->assertTrue( ec_artist_performer_kind_has_genre( 'music' ) );
		$this->assertTrue( ec_artist_performer_kind_has_genre( '' ) );
		$this->assertFalse( ec_artist_performer_kind_has_genre( 'comedy' ) );
	}

	public function test_existing_profiles_default_to_music(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			$this->assertSame( 'music', ec_artist_get_performer_kind( $this->profile_id ) );
			$data = ec_get_artist_profile_data( $this->profile_id );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( 'music', $data['performer_kind'] );
		$this->assertSame( 'MusicGroup', $data['schema_type'] );
	}

	public function test_comedy_kind_is_stored_and_exposed(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			$this->assertTrue( ec_artist_set_performer_kind( $this->profile_id, 'comedy' ) );
			$this->assertSame( 'comedy', get_post_meta( $this->profile_id, EC_ARTIST_PERFORMER_KIND_META, true ) );
			$data = ec_get_artist_profile_data( $this->profile_id );

			$this->assertTrue( ec_artist_set_performer_kind( $this->profile_id, 'music' ) );
			$this->assertFalse( metadata_exists( 'post', $this->profile_id, EC_ARTIST_PERFORMER_KIND_META ) );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( 'comedy', $data['performer_kind'] );
		$this->assertSame( 'PerformingGroup', $data['schema_type'] );
	}

	public function test_unknown_kind_is_rejected(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			$result = ec_artist_set_performer_kind( $this->profile_id, 'opera' );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'invalid_performer_kind', $result->get_error_code() );
			$this->assertSame( 'music', ec_artist_get_performer_kind( $this->profile_id ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_meta_is_registered_on_artist_profiles(): void {
		$this->assertSame( 10, has_action( 'init', 'ec_artist_register_performer_kind_meta' ) );
		switch_to_blog( $this->artist_blog_id() );
		try {
			ec_artist_register_performer_kind_meta();
			$this->assertTrue( registered_meta_key_exists( 'post', EC_ARTIST_PERFORMER_KIND_META, 'artist_profile' ) );
		} finally {
			restore_current_blog();
		}
	}
}
