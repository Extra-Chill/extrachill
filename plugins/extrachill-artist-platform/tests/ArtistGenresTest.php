<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/genre-resolver-stubs.php';

final class ArtistGenresTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array(
			'current_blog_id' => 4,
			'blog_stack'      => array(),
			'blogs'           => array(
				1 => array(
					'terms'     => array(
						142 => (object) array(
							'term_id'  => 142,
							'taxonomy' => 'artist',
							'slug'     => 'the-chill-band',
							'name'     => 'The Chill Band',
							'count'    => 0,
						),
					),
					'term_meta' => array(
						142 => array( '_artist_profile_id' => 12 ),
					),
				),
				4 => array(
					'posts'     => array(
						12 => (object) array(
							'ID'           => 12,
							'post_type'    => 'artist_profile',
							'post_status'  => 'publish',
							'post_title'   => 'The Chill Band',
							'post_name'    => 'the-chill-band',
							'post_content' => 'A short bio.',
						),
					),
					'post_meta' => array(
						12 => array( '_artist_term_id' => 142 ),
					),
					'terms'     => array(
						900 => (object) array(
							'term_id'  => 900,
							'taxonomy' => 'genre',
							'slug'     => 'rock',
							'name'     => 'Rock',
							'count'    => 0,
						),
						901 => (object) array(
							'term_id'  => 901,
							'taxonomy' => 'genre',
							'slug'     => 'hip-hop',
							'name'     => 'Hip-Hop',
							'count'    => 0,
						),
						902 => (object) array(
							'term_id'  => 902,
							'taxonomy' => 'genre',
							'slug'     => 'rnb',
							'name'     => 'R&B',
							'count'    => 0,
						),
						903 => (object) array(
							'term_id'  => 903,
							'taxonomy' => 'genre',
							'slug'     => 'electronic',
							'name'     => 'Electronic',
							'count'    => 0,
						),
						904 => (object) array(
							'term_id'  => 904,
							'taxonomy' => 'genre',
							'slug'     => 'jazz',
							'name'     => 'Jazz',
							'count'    => 0,
						),
					),
				),
			),
		);
	}

	private function assigned_slugs( int $post_id ): array {
		$slugs = array();
		foreach ( ec_artist_get_genre_terms( $post_id ) as $term ) {
			$slugs[] = $term->slug;
		}
		return $slugs;
	}

	public function test_set_genres_resolves_names_and_aliases_to_existing_terms(): void {
		$result = ec_artist_set_genres( 12, array( 'Rap/Hip-Hop', 'RNB' ) );

		$this->assertSame( array( 'hip-hop', 'rnb' ), $result );
		$this->assertSame( array( 'hip-hop', 'rnb' ), $this->assigned_slugs( 12 ) );
		$this->assertSame( array( 'Hip-Hop', 'R&B' ), ec_artist_get_genre_labels( 12 ) );
	}

	public function test_set_genres_accepts_a_single_multi_genre_string(): void {
		$result = ec_artist_set_genres( 12, 'Hip Hop, RNB' );

		$this->assertSame( array( 'hip-hop', 'rnb' ), $result );
		$this->assertSame( array( 'hip-hop', 'rnb' ), $this->assigned_slugs( 12 ) );
	}

	public function test_set_genres_caps_assignments_at_three(): void {
		$result = ec_artist_set_genres( 12, array( 'Rock', 'Jazz', 'Electronic', 'Indie' ) );

		$this->assertCount( 3, $result );
		$this->assertSame( array( 'rock', 'jazz', 'electronic' ), $result );
	}

	public function test_set_genres_drops_unresolvable_values(): void {
		$result = ec_artist_set_genres( 12, array( 'rappp', 'idk', 'Jazz' ) );

		$this->assertSame( array( 'jazz' ), $result );
		$this->assertSame( array( 'jazz' ), $this->assigned_slugs( 12 ) );
	}

	public function test_set_genres_with_empty_input_clears_the_assignment(): void {
		ec_artist_set_genres( 12, array( 'Jazz' ) );
		$result = ec_artist_set_genres( 12, array() );

		$this->assertSame( array(), $result );
		$this->assertSame( array(), $this->assigned_slugs( 12 ) );
	}

	public function test_set_genres_replaces_the_previous_set_instead_of_appending(): void {
		ec_artist_set_genres( 12, array( 'Rock' ) );
		$result = ec_artist_set_genres( 12, array( 'Jazz' ) );

		$this->assertSame( array( 'jazz' ), $result );
		$this->assertSame( array( 'jazz' ), $this->assigned_slugs( 12 ) );
	}

	public function test_set_genres_rejects_non_artist_profiles(): void {
		$result = ec_artist_set_genres( 999, array( 'Rock' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_artist_profile', $result->get_error_code() );
	}

	private function main_term_meta( int $term_id, string $key ) {
		return $GLOBALS['ec_test']['blogs'][1]['term_meta'][ $term_id ][ $key ] ?? null;
	}

	public function test_mirror_writes_json_slugs_to_the_bound_main_site_term(): void {
		ec_artist_set_genres( 12, array( 'Rap/Hip-Hop', 'RNB' ) );

		$this->assertSame(
			wp_json_encode( array( 'hip-hop', 'rnb' ) ),
			$this->main_term_meta( 142, '_genres' )
		);
	}

	public function test_mirror_deletes_the_meta_when_genres_are_cleared(): void {
		ec_artist_set_genres( 12, array( 'Jazz' ) );
		ec_artist_set_genres( 12, array() );

		$this->assertNull( $this->main_term_meta( 142, '_genres' ) );
	}

	public function test_mirror_follows_a_raw_binding_update_to_the_new_term(): void {
		ec_artist_set_genres( 12, array( 'Jazz' ) );
		$GLOBALS['ec_test']['blogs'][1]['terms'][143] = (object) array(
			'term_id'  => 143,
			'taxonomy' => 'artist',
			'slug'     => 'the-chill-band-two',
			'name'     => 'The Chill Band Two',
			'count'    => 0,
		);
		update_post_meta( 12, '_artist_term_id', 143 );

		ec_artist_set_genres( 12, array( 'Rock' ) );

		$this->assertSame( wp_json_encode( array( 'rock' ) ), $this->main_term_meta( 143, '_genres' ) );
	}

	public function test_mirror_is_a_no_op_without_a_bound_term(): void {
		delete_post_meta( 12, '_artist_term_id' );

		ec_artist_set_genres( 12, array( 'Jazz' ) );

		$this->assertSame( array( 'jazz' ), $this->assigned_slugs( 12 ) );
		$this->assertNull( $this->main_term_meta( 142, '_genres' ) );
	}
}
