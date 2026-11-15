<?php
/**
 * Failed artist identity pushes are reported instead of dropped (#1473).
 *
 * @package ExtraChillArtistPlatform
 */

require_once __DIR__ . '/support/base-test-case.php';

final class LinkPageIdentityPushReportingTest extends EC_Artist_Platform_TestCase {
	/** @var array<int, array{0:int,1:WP_Error,2:string}> Captured failure actions. */
	private $failures = array();

	/** @var string Temporary error_log destination. */
	private $log_file = '';

	/** @var string|false Previous error_log ini value. */
	private $previous_log = false;

	/** @var callable Failure recorder. */
	private $recorder;

	/** @var int[] Sites created by this test. */
	private $created_blogs = array();

	protected function setUp(): void {
		parent::setUp();
		$this->failures = array();
		$this->recorder = function ( $artist_id, $error, $context ) {
			$this->failures[] = array( $artist_id, $error, $context );
		};
		add_action( 'ec_artist_link_page_identity_push_failed', $this->recorder, 10, 3 );
		$this->log_file     = (string) wp_tempnam( 'identity-push-log' );
		$this->previous_log = ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- capture the operator log for assertions.
	}

	protected function tearDown(): void {
		global $wpdb;
		remove_action( 'ec_artist_link_page_identity_push_failed', $this->recorder, 10 );
		if ( false !== $this->previous_log ) {
			ini_set( 'error_log', $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restore the log destination.
		}
		if ( $this->log_file && file_exists( $this->log_file ) ) {
			unlink( $this->log_file );
		}
		foreach ( $this->created_blogs as $blog_id ) {
			$prefix = $wpdb->get_blog_prefix( $blog_id );
			foreach ( $wpdb->tables( 'blog', false ) as $table ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- test teardown of fixture site tables.
			}
		}
		$this->created_blogs = array();
		parent::tearDown();
	}

	private function log_contents(): string {
		return file_exists( $this->log_file ) ? (string) file_get_contents( $this->log_file ) : '';
	}

	public function test_hook_callers_are_registered(): void {
		$this->assertSame( 10, has_action( 'ec_artist_platform_sync', 'ec_handle_sync_action' ) );
		$this->assertSame( 20, has_action( 'save_post_artist_profile', 'ec_artist_push_identity_on_save' ) );
		$this->assertSame( 10, has_action( 'updated_post_meta', 'ec_artist_push_identity_on_thumbnail_change' ) );
	}

	public function test_reporter_is_silent_for_non_errors(): void {
		$this->assertFalse( ec_artist_report_link_page_identity_push( 12, true, 'save' ) );
		$this->assertSame( array(), $this->failures );
		$this->assertSame( '', $this->log_contents() );
	}

	public function test_reporter_fires_action_and_logs_errors(): void {
		$error = new WP_Error( 'forced_push_failure', 'Forced failure.' );
		$this->assertTrue( ec_artist_report_link_page_identity_push( 12, $error, 'create', 34 ) );
		$this->assertCount( 1, $this->failures );
		$this->assertSame( array( 12, $error, 'create' ), $this->failures[0] );
		$log = $this->log_contents();
		$this->assertStringContainsString( 'Artist 12 Link Page 34 identity push failed (create): forced_push_failure: Forced failure.', $log );
	}

	public function test_failing_push_from_save_hook_is_reported(): void {
		// A save callback handed an artist_profile post object whose ID does not
		// resolve to an artist profile on this blog: the push rejects it.
		$post_id         = (int) self::factory()->post->create( array( 'post_type' => 'post' ) );
		$post            = clone get_post( $post_id );
		$post->post_type = 'artist_profile';

		ec_artist_push_identity_on_save( $post_id, $post );

		$this->assertCount( 1, $this->failures );
		$this->assertSame( $post_id, $this->failures[0][0] );
		$this->assertSame( 'invalid_artist_profile', $this->failures[0][1]->get_error_code() );
		$this->assertSame( 'save', $this->failures[0][2] );
		$this->assertStringContainsString( "Artist {$post_id} Link Page identity push failed (save): invalid_artist_profile", $this->log_contents() );
	}

	public function test_failing_push_from_sync_action_is_reported(): void {
		do_action( 'ec_artist_platform_sync', 0 );

		$this->assertCount( 1, $this->failures );
		$this->assertSame( 'invalid_artist_profile', $this->failures[0][1]->get_error_code() );
		$this->assertSame( 'sync_action', $this->failures[0][2] );
	}

	public function test_no_page_push_from_save_and_thumbnail_hooks_is_silent(): void {
		if ( ! function_exists( 'ec_read_link_page_persistence' ) || ! function_exists( 'ec_with_link_page_storage_blog' ) ) {
			$this->markTestSkipped( 'Requires the Link Pages runtime.' );
		}
		$artist = $this->create_artist_profile( 'No page yet band' );
		switch_to_blog( $this->artist_blog_id() );
		try {
			$this->assertFalse( ec_get_link_page_for_artist( $artist, true ) );
			ec_artist_push_identity_on_save( $artist, get_post( $artist ) );
			ec_artist_push_identity_on_thumbnail_change( 0, $artist, '_thumbnail_id' );
			ec_artist_push_identity_on_thumbnail_change( 0, $artist, '_unrelated_meta' );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( array(), $this->failures );
		$this->assertSame( '', $this->log_contents() );
	}

	public function test_thumbnail_hook_reports_failure_and_save_hook_success_is_silent(): void {
		if ( ! function_exists( 'ec_get_link_page_storage_blog_id' ) ) {
			$this->markTestSkipped( 'Requires the standalone Link Pages storage runtime.' );
		}
		$this->ec_require_mysql_advisory_locks();
		$artist                = $this->create_artist_profile( 'Broken photo band' );
		$storage               = (int) self::factory()->blog->create();
		$this->created_blogs[] = $storage;
		$this->ec_inject_filter( 'ec_link_page_storage_blog_id', static function () use ( $storage ) { return $storage; } );
		switch_to_blog( $storage );
		try {
			$page = wp_insert_post( array( 'post_type' => ec_link_page_post_type(), 'post_status' => 'publish', 'post_title' => 'Broken photo page' ) );
			$this->assertNotWPError( ec_assign_link_page_owner( $page, \ExtraChillArtistPlatform\Artist_Link_Pages::owner_reference( $artist ) ) );
		} finally {
			restore_current_blog();
		}
		switch_to_blog( $this->artist_blog_id() );
		try {
			// A successful push (name only, no photo) stays silent.
			ec_artist_push_identity_on_save( $artist, get_post( $artist ) );
			$this->assertSame( array(), $this->failures );

			// A thumbnail that is not an image attachment cannot be materialized.
			$not_an_image = (int) self::factory()->post->create( array( 'post_type' => 'post' ) );
			update_post_meta( $artist, '_thumbnail_id', $not_an_image );
		} finally {
			restore_current_blog();
		}
		$this->assertNotEmpty( $this->failures );
		$last = end( $this->failures );
		$this->assertSame( $artist, $last[0] );
		$this->assertSame( 'invalid_profile_image', $last[1]->get_error_code() );
		$this->assertSame( 'thumbnail_change', $last[2] );
		$this->assertStringContainsString( "Artist {$artist} Link Page identity push failed (thumbnail_change): invalid_profile_image", $this->log_contents() );
	}
}
