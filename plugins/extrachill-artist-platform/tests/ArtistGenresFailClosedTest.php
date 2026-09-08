<?php

use PHPUnit\Framework\TestCase;

/**
 * Fail-closed coverage: without the extrachill-network genre resolver (#191)
 * in the process, genre writes must refuse instead of storing raw strings.
 *
 * Runs in a separate process so the resolver fixture used by other tests is
 * not loaded here.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ArtistGenresFailClosedTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array(
			'current_blog_id' => 4,
			'blog_stack'      => array(),
			'blogs'           => array(
				4 => array(
					'posts' => array(
						12 => (object) array(
							'ID'           => 12,
							'post_type'    => 'artist_profile',
							'post_status'  => 'publish',
							'post_title'   => 'The Chill Band',
							'post_name'    => 'the-chill-band',
							'post_content' => 'A short bio.',
						),
					),
					'terms' => array(
						900 => (object) array(
							'term_id'  => 900,
							'taxonomy' => 'genre',
							'slug'     => 'rock',
							'name'     => 'Rock',
							'count'    => 0,
						),
					),
				),
			),
		);
	}

	public function test_set_genres_refuses_to_write_without_the_network_resolver(): void {
		$this->assertFalse( function_exists( 'extrachill_network_resolve_genres' ) );

		$result = ec_artist_set_genres( 12, array( 'Rock', 'rap' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'genre_vocabulary_unavailable', $result->get_error_code() );
		$this->assertSame( array(), ec_test_blog_store( 'object_terms' ) );
	}

	public function test_reads_return_empty_arrays_when_nothing_is_assigned(): void {
		$this->assertSame( array(), ec_artist_get_genres( 12 ) );
		$this->assertSame( array(), ec_artist_get_genre_labels( 12 ) );
	}
}
