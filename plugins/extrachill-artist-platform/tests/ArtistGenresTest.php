<?php

require_once __DIR__ . '/support/base-test-case.php';

final class ArtistGenresTest extends EC_Artist_Platform_TestCase {
	private $profile_id;
	private $term_id;

	protected function setUp(): void {
		parent::setUp();

		$bound            = $this->create_bound_artist( 'The Chill Band' );
		$this->profile_id = $bound['profile_id'];
		$this->term_id    = $bound['term_id'];

		switch_to_blog( $this->artist_blog_id() );
		try {
			foreach ( array(
				'Rock'       => 'rock',
				'Hip-Hop'    => 'hip-hop',
				'R&B'        => 'rnb',
				'Electronic' => 'electronic',
				'Jazz'       => 'jazz',
			) as $name => $slug ) {
				if ( ! get_term_by( 'slug', $slug, 'genre' ) ) {
					wp_insert_term( $name, 'genre', array( 'slug' => $slug ) );
				}
			}
		} finally {
			restore_current_blog();
		}
	}

	private function assigned_slugs( int $post_id ): array {
		switch_to_blog( $this->artist_blog_id() );
		try {
			$slugs = array();
			foreach ( ec_artist_get_genre_terms( $post_id ) as $term ) {
				$slugs[] = $term->slug;
			}
			return $slugs;
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Run one canonical genre write on the artist blog.
	 */
	private function set_genres( int $post_id, $input ) {
		switch_to_blog( $this->artist_blog_id() );
		try {
			return ec_artist_set_genres( $post_id, $input );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * The stored display names for the given genre slugs.
	 */
	private function genre_term_names( array $slugs ): array {
		switch_to_blog( $this->artist_blog_id() );
		try {
			$names = array();
			foreach ( $slugs as $slug ) {
				$term    = get_term_by( 'slug', $slug, 'genre' );
				$names[] = $term ? $term->name : '';
			}
			return $names;
		} finally {
			restore_current_blog();
		}
	}

	private function genre_labels( int $post_id ): array {
		switch_to_blog( $this->artist_blog_id() );
		try {
			return ec_artist_get_genre_labels( $post_id );
		} finally {
			restore_current_blog();
		}
	}

	private function main_term_meta( int $term_id, string $key ) {
		switch_to_blog( $this->main_blog_id() );
		try {
			return get_term_meta( $term_id, $key, true );
		} finally {
			restore_current_blog();
		}
	}

	public function test_set_genres_resolves_names_and_aliases_to_existing_terms(): void {
		$result = $this->set_genres( $this->profile_id, array( 'Rap/Hip-Hop', 'RNB' ) );

		$this->assertSame( array( 'hip-hop', 'rnb' ), $result );
		$this->assertSame( array( 'hip-hop', 'rnb' ), $this->assigned_slugs( $this->profile_id ) );
		$this->assertSame(
			$this->genre_term_names( array( 'hip-hop', 'rnb' ) ),
			$this->genre_labels( $this->profile_id )
		);
	}

	public function test_set_genres_fails_closed_on_an_empty_local_vocabulary(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			$term_ids = get_terms( array( 'taxonomy' => 'genre', 'hide_empty' => false, 'fields' => 'ids' ) );
			$this->assertIsArray( $term_ids );
			foreach ( $term_ids as $term_id ) {
				wp_delete_term( (int) $term_id, 'genre' );
			}

			$result = ec_artist_set_genres( $this->profile_id, array( 'jam', 'soul-funk' ) );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'genre_vocabulary_unavailable', $result->get_error_code() );
			$this->assertSame( array(), get_terms( array( 'taxonomy' => 'genre', 'hide_empty' => false ) ) );
			$this->assertSame( array(), ec_artist_get_genre_labels( $this->profile_id ) );
		} finally {
			restore_current_blog();
		}
	}

	public function test_missing_local_term_preserves_assignments_and_mirror(): void {
		$this->assertSame( array( 'rock' ), $this->set_genres( $this->profile_id, 'Rock' ) );
		$mirror = $this->main_term_meta( $this->term_id, '_genres' );

		switch_to_blog( $this->artist_blog_id() );
		try {
			$jazz = get_term_by( 'slug', 'jazz', 'genre' );
			wp_delete_term( (int) $jazz->term_id, 'genre' );
		} finally {
			restore_current_blog();
		}

		$result = $this->set_genres( $this->profile_id, array( 'Hip Hop', 'Jazz' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'genre_vocabulary_unavailable', $result->get_error_code() );
		$this->assertSame( array( 'rock' ), $this->assigned_slugs( $this->profile_id ) );
		$this->assertSame( $mirror, $this->main_term_meta( $this->term_id, '_genres' ) );
		$this->assertSame( array( '' ), $this->genre_term_names( array( 'jazz' ) ) );
	}

	public function test_genre_binding_runs_after_artist_profile_registration(): void {
		$this->assertGreaterThan(
			has_action( 'init', 'extrachill_register_artist_profile_cpt' ),
			has_action( 'init', 'ec_artist_genres_bind_taxonomy' )
		);
		$this->assertContains( 'genre', get_object_taxonomies( 'artist_profile' ) );
	}

	public function test_set_genres_accepts_a_single_multi_genre_string(): void {
		$result = $this->set_genres( $this->profile_id, 'Hip Hop, RNB' );

		$this->assertSame( array( 'hip-hop', 'rnb' ), $result );
		$this->assertSame( array( 'hip-hop', 'rnb' ), $this->assigned_slugs( $this->profile_id ) );
	}

	public function test_set_genres_caps_assignments_at_three(): void {
		$result = $this->set_genres( $this->profile_id, array( 'Rock', 'Jazz', 'Electronic', 'Indie' ) );

		$this->assertCount( 3, $result );
		$this->assertSame( array( 'rock', 'jazz', 'electronic' ), $result );
	}

	public function test_set_genres_drops_unresolvable_values(): void {
		$result = $this->set_genres( $this->profile_id, array( 'rappp', 'idk', 'Jazz' ) );

		$this->assertSame( array( 'jazz' ), $result );
		$this->assertSame( array( 'jazz' ), $this->assigned_slugs( $this->profile_id ) );
	}

	public function test_set_genres_with_empty_input_clears_the_assignment(): void {
		$this->set_genres( $this->profile_id, array( 'Jazz' ) );
		$result = $this->set_genres( $this->profile_id, array() );

		$this->assertSame( array(), $result );
		$this->assertSame( array(), $this->assigned_slugs( $this->profile_id ) );
	}

	public function test_set_genres_replaces_the_previous_set_instead_of_appending(): void {
		$this->set_genres( $this->profile_id, array( 'Rock' ) );
		$result = $this->set_genres( $this->profile_id, array( 'Jazz' ) );

		$this->assertSame( array( 'jazz' ), $result );
		$this->assertSame( array( 'jazz' ), $this->assigned_slugs( $this->profile_id ) );
	}

	public function test_set_genres_rejects_non_artist_profiles(): void {
		$regular_post_id = (int) self::factory()->post->create( array( 'post_title' => 'Just A Post' ) );

		$result = $this->set_genres( $regular_post_id, array( 'Rock' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_artist_profile', $result->get_error_code() );
	}

	public function test_mirror_writes_json_slugs_to_the_bound_main_site_term(): void {
		$this->set_genres( $this->profile_id, array( 'Rap/Hip-Hop', 'RNB' ) );

		$this->assertSame(
			wp_json_encode( array( 'hip-hop', 'rnb' ) ),
			$this->main_term_meta( $this->term_id, '_genres' )
		);
	}

	public function test_mirror_deletes_the_meta_when_genres_are_cleared(): void {
		$this->set_genres( $this->profile_id, array( 'Jazz' ) );
		$this->set_genres( $this->profile_id, array() );

		$this->assertSame( '', $this->main_term_meta( $this->term_id, '_genres' ) );
	}

	public function test_mirror_follows_a_raw_binding_update_to_the_new_term(): void {
		$this->set_genres( $this->profile_id, array( 'Jazz' ) );

		$new_term_id = $this->create_artist_term( 'the-chill-band-two' );
		switch_to_blog( $this->artist_blog_id() );
		update_post_meta( $this->profile_id, '_artist_term_id', $new_term_id );
		clean_post_cache( $this->profile_id );
		restore_current_blog();

		$this->set_genres( $this->profile_id, array( 'Rock' ) );

		$this->assertSame( wp_json_encode( array( 'rock' ) ), $this->main_term_meta( $new_term_id, '_genres' ) );
	}

	public function test_mirror_is_a_no_op_without_a_bound_term(): void {
		switch_to_blog( $this->artist_blog_id() );
		delete_post_meta( $this->profile_id, '_artist_term_id' );
		restore_current_blog();

		$this->set_genres( $this->profile_id, array( 'Jazz' ) );

		$this->assertSame( array( 'jazz' ), $this->assigned_slugs( $this->profile_id ) );
		$this->assertSame( '', $this->main_term_meta( $this->term_id, '_genres' ) );
	}
}
