<?php

require_once __DIR__ . '/support/base-test-case.php';

/**
 * Fail-closed coverage: when the genre vocabulary is unavailable, genre
 * writes must refuse instead of storing raw strings.
 *
 * The managed harness runs with the real extrachill-network genre resolver
 * and taxonomy active, so unavailability is produced the way production
 * experiences it: the vocabulary taxonomy is not registered.
 */
final class ArtistGenresFailClosedTest extends EC_Artist_Platform_TestCase {
	private $profile_id;

	protected function setUp(): void {
		parent::setUp();

		$this->profile_id = $this->create_artist_profile( 'The Chill Band' );
	}

	protected function tearDown(): void {
		// Restore the real network taxonomy registration after unregistration.
		if ( ! taxonomy_exists( 'genre' ) && function_exists( 'extrachill_network_register_taxonomies' ) ) {
			extrachill_network_register_taxonomies();
		}
		ec_artist_genres_bind_taxonomy();
		parent::tearDown();
	}

	public function test_set_genres_refuses_to_write_without_the_genre_vocabulary(): void {
		if ( ! taxonomy_exists( 'genre' ) ) {
			$this->markTestSkipped( 'The genre taxonomy is not registered by extrachill-network; the fail-closed path is the default.' );
		}

		unregister_taxonomy( 'genre' );

		$this->assertFalse( taxonomy_exists( 'genre' ) );

		switch_to_blog( $this->artist_blog_id() );
		try {
			$result = ec_artist_set_genres( $this->profile_id, array( 'Rock', 'rap' ) );
		} finally {
			restore_current_blog();
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'genre_vocabulary_unavailable', $result->get_error_code() );
		switch_to_blog( $this->artist_blog_id() );
		try {
			$assigned = get_the_terms( $this->profile_id, 'genre' );
		} finally {
			restore_current_blog();
		}
		$this->assertInstanceOf( WP_Error::class, $assigned );
	}

	public function test_reads_return_empty_arrays_when_nothing_is_assigned(): void {
		$this->assertSame( array(), ec_artist_get_genres( $this->profile_id ) );
		$this->assertSame( array(), ec_artist_get_genre_labels( $this->profile_id ) );
	}
}
