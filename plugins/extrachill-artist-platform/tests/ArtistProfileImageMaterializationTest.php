<?php
/** Cross-blog profile image regression coverage. */
require_once __DIR__ . '/support/base-test-case.php';
require_once dirname( __DIR__ ) . '/inc/core/actions/profile-image.php';

final class ArtistProfileImageMaterializationTest extends EC_Artist_Platform_TestCase {
	/** @var int[] Sites created by this test. */
	private $created_blogs = array();

	/**
	 * Create a site whose tables are dropped after the test.
	 *
	 * Site tables are created with DDL, which the per-test transaction does not
	 * roll back. Without dropping them, a later test that is handed the same
	 * blog ID inherits the previous test's attachments (including materialized
	 * copies tagged with _ec_source_attachment) and the copy lookup finds them.
	 */
	private function isolated_blog(): int {
		$blog_id               = (int) self::factory()->blog->create();
		$this->created_blogs[] = $blog_id;
		return $blog_id;
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->created_blogs as $blog_id ) {
			// Drop the tables directly: on some runtimes the site row is already
			// gone here (wp_delete_site() reports site_not_exist) while its tables
			// remain, so the blog ID is handed out again with stale rows.
			$prefix = $wpdb->get_blog_prefix( $blog_id );
			foreach ( $wpdb->tables( 'blog', false ) as $table ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- test teardown of fixture site tables.
			}
		}
		$this->created_blogs = array();
		parent::tearDown();
	}

	/** Real image attachment with a stable local file. */
	private function image( $contents = null ) {
		$temp = wp_tempnam( 'avatar.png' );
		$file = $temp . '.png';
		unlink( $temp );
		file_put_contents( $file, $contents ?? base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF1sAAAAASUVORK5CYII=' ) );
		$id = wp_insert_attachment( array( 'post_title' => 'Avatar', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $file );
		update_attached_file( $id, $file );
		return $id;
	}

	public function test_cross_blog_copy_is_local_and_idempotent() {
		$source_blog = get_current_blog_id();
		$source = $this->image();
		$file = get_attached_file( $source );
		$destination = $this->isolated_blog();
		switch_to_blog( $destination );
		try {
			$local = ec_artist_materialize_profile_image( $source_blog, $source );
			$this->assertNotWPError( $local );
			$this->assertTrue( wp_attachment_is_image( $local ) );
			$this->assertSame( hash_file( 'sha256', $file ), hash_file( 'sha256', get_attached_file( $local ) ) );
			$this->assertSame( $source_blog . ':' . $source, get_post_meta( $local, '_ec_source_attachment', true ) );
			$this->assertSame( $local, ec_artist_materialize_profile_image( $source_blog, $source ) );
			$this->assertSame( $local, ec_artist_materialize_profile_image( $source_blog, $source, $local, false ) );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( $source, ec_artist_materialize_profile_image( $destination, $local ) );
		$this->assertSame( $source_blog, get_current_blog_id() );
	}

	public function test_update_artist_pushes_a_storage_local_renderable_image() {
		if ( ! function_exists( 'ec_get_link_page_storage_blog_id' ) ) {
			$this->markTestSkipped( 'Requires the standalone Link Pages storage runtime.' );
		}
		$this->ec_require_mysql_advisory_locks();
		$artist = $this->create_artist_profile( 'Cross blog avatar' );
		$storage = $this->isolated_blog();
		$this->ec_inject_filter( 'ec_link_page_storage_blog_id', static function () use ( $storage ) { return $storage; } );
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $user );
		wp_set_current_user( $user );
		switch_to_blog( $storage );
		try {
			$page = wp_insert_post( array( 'post_type' => ec_link_page_post_type(), 'post_status' => 'publish', 'post_title' => 'Avatar page' ) );
			update_post_meta( $page, '_associated_artist_profile_id', $artist );
			$this->assertNotWPError( ec_assign_link_page_owner( $page, ec_artist_link_page_owner_reference( $artist ) ) );
		} finally {
			restore_current_blog();
		}
		switch_to_blog( $this->artist_blog_id() );
		try {
			$source = $this->image();
			$source_file = get_attached_file( $source );
			$result = extrachill_artist_platform_ability_update_artist( array( 'artist_id' => $artist, 'profile_image_id' => $source ) );
			$this->assertNotWPError( $result );
			$current = ec_read_link_page_persistence( $page );
			$this->assertNotWPError( $current );
			$local = $current['profile_image_id'];
			$this->assertTrue( ec_artist_push_link_page_identity( $artist ) );
			$this->assertSame( $local, ec_read_link_page_persistence( $page )['profile_image_id'] );
		} finally {
			restore_current_blog();
		}
		switch_to_blog( $storage );
		try {
			$this->assertNotEmpty( wp_get_attachment_image( $local ) );
			$this->assertSame( hash_file( 'sha256', $source_file ), hash_file( 'sha256', get_attached_file( $local ) ) );
			$this->assertSame( $this->artist_blog_id() . ':' . $source, get_post_meta( $local, '_ec_source_attachment', true ) );
			$page_image = $this->image();
			$saved = extrachill_artist_platform_ability_save_link_page_settings( array( 'artist_id' => $artist, 'profile_image_id' => $page_image ) );
			$this->assertNotWPError( $saved );
			$this->assertSame( $page_image, ec_read_link_page_persistence( $page )['profile_image_id'] );
			$artist_image = ec_artist_profile_thumbnail( $artist );
			$this->assertNotWPError( $artist_image );
			$this->assertSame( $page_image, ec_artist_materialize_profile_image( $this->artist_blog_id(), $artist_image ) );
			$cleared = extrachill_artist_platform_ability_save_link_page_settings( array( 'artist_id' => $artist, 'profile_image_id' => 0 ) );
			$this->assertNotWPError( $cleared );
			$this->assertSame( 0, ec_artist_profile_thumbnail( $artist ) );
			$this->assertSame( 0, ec_read_link_page_persistence( $page )['profile_image_id'] );
		} finally {
			restore_current_blog();
			revoke_super_admin( $user );
		}
	}

	public function test_collision_is_not_reused_and_migration_copy_is_adopted() {
		$source_blog = get_current_blog_id();
		$source = $this->image();
		$destination = $this->isolated_blog();
		switch_to_blog( $destination );
		try {
			$collision = $this->image( 'unrelated image bytes' );
			$this->assertSame( 0, ec_artist_materialize_profile_image( $source_blog, $source, $collision, false ) );
			$local = ec_artist_materialize_profile_image( $source_blog, $source, $collision );
			$this->assertNotWPError( $local );
			$this->assertNotSame( $collision, $local );
			delete_post_meta( $local, '_ec_source_attachment' );
			$this->assertSame( $local, ec_artist_materialize_profile_image( $source_blog, $source, $local ) );
			$this->assertSame( 0, ec_artist_materialize_profile_image( $source_blog, 0, $local ) );
			$this->assertWPError( ec_artist_materialize_profile_image( $source_blog, PHP_INT_MAX ) );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( $source_blog, get_current_blog_id() );
	}
}
