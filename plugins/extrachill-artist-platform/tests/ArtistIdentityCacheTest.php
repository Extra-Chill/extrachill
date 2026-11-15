<?php
/**
 * Canonical artist identity reads and their owner invalidation.
 *
 * Runs against the managed multisite harness with the real Network read
 * (ec_get_artist_identity) and the real Artist Platform writers, so every
 * assertion that follows a write proves the cached record was invalidated.
 *
 * @package ExtraChillArtistPlatform
 */

require_once __DIR__ . '/support/base-test-case.php';

final class ArtistIdentityCacheTest extends EC_Artist_Platform_TestCase {

	/**
	 * Use the portable binding lock so binding writers run on SQLite too.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->ec_inject_filter(
			'ec_artist_binding_database_backend',
			static function () {
				return 'sqlite';
			}
		);
	}

	private function identity( string $key, $value ) {
		return ec_get_artist_identity( array( $key => $value ) );
	}

	public function test_reciprocal_binding_reads_the_same_identity_from_every_lookup_and_blog(): void {
		$pair = $this->create_bound_artist( 'Identity Band' );

		$by_profile = $this->identity( 'profile_id', $pair['profile_id'] );
		$this->assertIsArray( $by_profile );
		$this->assertTrue( $by_profile['binding_valid'] );
		$this->assertSame( $pair['term_id'], $by_profile['term_id'] );
		$this->assertSame( 'publish', $by_profile['status'] );
		$this->assertSame( 'Identity Band', $by_profile['name'] );

		// get_permalink() after switch_to_blog() keeps the caller's rewrite
		// state, so build the expectation from the artist blog's own structure.
		switch_to_blog( $this->artist_blog_id() );
		$structure          = (string) get_option( 'permalink_structure' );
		$expected_permalink = '' === $structure
			? home_url( '?artist_profile=identity-band' )
			: home_url( '/artists/identity-band' . ( '/' === substr( $structure, -1 ) ? '/' : '' ) );
		restore_current_blog();
		$this->assertSame( $expected_permalink, $by_profile['permalink'], 'the permalink follows the artist blog permalink structure' );

		switch_to_blog( $this->main_blog_id() );
		$this->assertSame( $by_profile, $this->identity( 'term_id', $pair['term_id'] ) );
		$this->assertSame( $by_profile, $this->identity( 'slug', $by_profile['slug'] ) );
		restore_current_blog();
	}

	public function test_profile_lifecycle_invalidates_cached_identity(): void {
		$pair = $this->create_bound_artist( 'Lifecycle Band' );
		$id   = $pair['profile_id'];
		$this->assertSame( 'publish', $this->identity( 'profile_id', $id )['status'] );

		switch_to_blog( $this->artist_blog_id() );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		restore_current_blog();
		$this->assertSame( 'draft', $this->identity( 'profile_id', $id )['status'], 'a status transition is visible immediately' );

		switch_to_blog( $this->artist_blog_id() );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
				'post_title'  => 'Renamed Lifecycle Band',
			)
		);
		restore_current_blog();
		$this->assertSame( 'Renamed Lifecycle Band', $this->identity( 'profile_id', $id )['name'], 'a save is visible immediately' );

		switch_to_blog( $this->artist_blog_id() );
		$attachment_id = (int) self::factory()->attachment->create();
		// set_post_thumbnail() needs a renderable file; write the meta it writes.
		update_post_meta( $id, '_thumbnail_id', $attachment_id );
		restore_current_blog();
		$this->assertSame( $attachment_id, $this->identity( 'profile_id', $id )['thumbnail_id'], 'a thumbnail change is visible immediately' );

		switch_to_blog( $this->artist_blog_id() );
		wp_delete_post( $id, true );
		restore_current_blog();
		$this->assertNull( $this->identity( 'profile_id', $id ), 'a deleted profile is gone immediately' );
		$this->assertFalse( $this->identity( 'term_id', $pair['term_id'] )['binding_valid'] );
	}

	public function test_binding_writers_invalidate_both_sides(): void {
		$slug       = 'identity-sync';
		$profile_id = $this->create_artist_profile( 'Identity Sync', array( 'post_name' => $slug ) );

		$unbound = $this->identity( 'profile_id', $profile_id );
		$this->assertFalse( $unbound['binding_valid'] );
		$this->assertSame( 0, $unbound['term_id'] );

		$term_id = ec_sync_artist_profile_term_binding( $profile_id );
		$this->assertNotWPError( $term_id );
		$bound = $this->identity( 'profile_id', $profile_id );
		$this->assertTrue( $bound['binding_valid'], 'a sync that creates and binds the term is visible immediately' );
		$this->assertSame( $term_id, $bound['term_id'] );
		$this->assertSame( $profile_id, $this->identity( 'term_id', $term_id )['profile_id'] );

		switch_to_blog( $this->artist_blog_id() );
		wp_delete_post( $profile_id, true );
		restore_current_blog();
		$this->assertNull( $this->identity( 'profile_id', $profile_id ), 'deleting the profile is visible from the profile side' );
		$term_side = $this->identity( 'term_id', $term_id );
		$this->assertSame( 0, $term_side['profile_id'], 'the delete cleanup unbinds the term side immediately' );
		$this->assertFalse( $term_side['binding_valid'] );
	}

	public function test_main_blog_term_changes_invalidate_cached_identity(): void {
		$pair = $this->create_bound_artist( 'Term Band' );
		$this->assertTrue( $this->identity( 'term_id', $pair['term_id'] )['binding_valid'] );

		switch_to_blog( $this->main_blog_id() );
		wp_update_term( $pair['term_id'], 'artist', array( 'name' => 'Term Band Renamed' ) );
		restore_current_blog();
		$this->assertSame( 'Term Band Renamed', $this->identity( 'profile_id', $pair['profile_id'] )['term_name'] );

		switch_to_blog( $this->main_blog_id() );
		wp_delete_term( $pair['term_id'], 'artist' );
		restore_current_blog();
		$this->assertFalse( $this->identity( 'profile_id', $pair['profile_id'] )['binding_valid'], 'a deleted term breaks the binding immediately' );
	}
}
