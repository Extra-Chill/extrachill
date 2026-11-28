<?php
/**
 * Link Page analytics tables are created only on the Link Page storage blog,
 * and the one-time orphan cleanup refuses any non-storage copy with rows
 * (Extra-Chill/extrachill#1775).
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Storage-blog-only schema ownership and orphan cleanup.
 */
final class LinkPageStorageBlogSchemaTest extends Extrachill_Analytics_TestCase {

	/**
	 * Active storage-blog filter callback.
	 *
	 * @var callable|null
	 */
	private $storage_filter;

	/**
	 * Blogs created by this test whose real tables must be dropped.
	 *
	 * @var int[]
	 */
	private $blogs = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}
		// The harness rewrites CREATE/DROP TABLE to TEMPORARY tables, which
		// SHOW TABLES (the production existence probe) cannot see. Use real
		// tables here and drop them in tear_down().
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function tear_down(): void {
		foreach ( $this->blogs as $blog_id ) {
			$this->reset_blog_tables( $blog_id );
		}
		$this->blogs = array();
		if ( $this->storage_filter ) {
			remove_filter( 'extrachill_analytics_link_page_storage_blog_id', $this->storage_filter );
			$this->storage_filter = null;
		}
		// Cleanup tests may drop the main blog's copies; restore them for later tests.
		delete_option( EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION_OPTION );
		extrachill_analytics_link_page_create_table();
		parent::tear_down();
	}

	public function test_upgrade_reconciles_only_the_storage_blog(): void {
		$started_on = get_current_blog_id();
		$storage    = $this->blog( 'storage' );
		$other      = $this->blog( 'other' );
		$this->reset_blog_tables( $storage );
		$this->reset_blog_tables( $other );
		$this->use_storage_blog( $storage );

		extrachill_analytics_link_page_reconcile_network_on_upgrade(
			null,
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'plugins' => array( 'some-other-plugin/some-other-plugin.php' ),
			)
		);
		$this->assertFalse( $this->blog_has_tables( $storage ), 'An irrelevant plugin update must not create tables.' );

		extrachill_analytics_link_page_reconcile_network_on_upgrade(
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'extrachill-analytics/extrachill-analytics.php',
			)
		);

		$this->assertSame( $started_on, get_current_blog_id(), 'The upgrade hook must restore the blog it started on.' );
		$this->assertTrue( $this->blog_has_tables( $storage ), 'The storage blog must get both Link Page tables.' );
		$this->assertFalse( $this->blog_has_tables( $other ), 'Non-storage blogs must not get Link Page tables.' );

		switch_to_blog( $storage );
		$ready = extrachill_analytics_link_page_schema_table_ready(
			extrachill_analytics_link_page_clicks_table(),
			extrachill_analytics_link_page_schema_click_columns(),
			extrachill_analytics_link_page_schema_click_indexes()
		);
		restore_current_blog();
		$this->assertTrue( $ready );
	}

	public function test_create_table_and_admin_init_from_a_non_storage_blog_target_the_storage_blog(): void {
		$storage = $this->blog( 'storage' );
		$other   = $this->blog( 'other' );
		$this->reset_blog_tables( $storage );
		$this->reset_blog_tables( $other );
		$this->use_storage_blog( $storage );

		switch_to_blog( $other );
		extrachill_analytics_link_page_create_table_on_admin_init();
		$this->assertSame( $other, get_current_blog_id() );
		$this->assertFalse( $this->blog_has_tables( $storage ), 'admin_init on a non-storage blog is a no-op.' );

		extrachill_analytics_link_page_create_table();
		$this->assertSame( $other, get_current_blog_id(), 'create_table() must restore the calling blog.' );
		$this->assertFalse( (bool) get_option( EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION_OPTION ), 'The version option belongs to the storage blog only.' );
		restore_current_blog();

		$this->assertTrue( $this->blog_has_tables( $storage ) );
		$this->assertFalse( $this->blog_has_tables( $other ) );
		$this->assertSame( EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION, get_blog_option( $storage, EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION_OPTION ) );
	}

	public function test_cleanup_is_dry_run_by_default_drops_only_empty_copies_and_refuses_non_empty(): void {
		global $wpdb;
		$storage = $this->blog( 'storage' );
		$empty   = $this->blog( 'empty' );
		$legacy  = $this->blog( 'legacy' );

		// Reproduce the pre-#1775 state: tables on every blog.
		foreach ( array( $storage, $empty, $legacy ) as $blog_id ) {
			switch_to_blog( $blog_id );
			delete_option( EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION_OPTION );
			extrachill_analytics_link_page_create_table_on_current_blog();
			restore_current_blog();
		}
		$legacy_views = $wpdb->get_blog_prefix( $legacy ) . 'extrch_link_page_daily_views';
		$wpdb->insert( $legacy_views, array( 'link_page_id' => 7, 'stat_date' => '2026-01-01', 'view_count' => 5 ), array( '%d', '%s', '%d' ) );
		$this->use_storage_blog( $storage );

		$dry = extrachill_analytics_ability_cleanup_link_page_tables( array() );
		$this->assertIsArray( $dry );
		$this->assertTrue( $dry['dry_run'] );
		$this->assertSame( $storage, $dry['storage_blog_id'] );
		$this->assertSame( array( 'storage', 'storage' ), $this->actions_for( $dry, $storage ) );
		$this->assertSame( array( 'would_drop', 'would_drop' ), $this->actions_for( $dry, $empty ) );
		$this->assertSame( array( 'refused', 'would_drop' ), $this->actions_for( $dry, $legacy ) );
		$this->assertTrue( $this->blog_has_tables( $empty ), 'Dry run must not drop anything.' );

		$live = extrachill_analytics_link_page_cleanup_orphan_tables( false );
		$this->assertFalse( $live['dry_run'] );
		$this->assertSame( array( 'dropped', 'dropped' ), $this->actions_for( $live, $empty ) );
		$this->assertSame( array( 'refused', 'dropped' ), $this->actions_for( $live, $legacy ) );
		$this->assertGreaterThanOrEqual( 1, $live['refused'] );

		$this->assertTrue( $this->blog_has_tables( $storage ), 'Storage blog tables are never dropped.' );
		$this->assertFalse( $this->blog_has_tables( $empty ) );
		$this->assertFalse( get_blog_option( $empty, EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION_OPTION ) );
		$this->assertSame( $legacy_views, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy_views ) ), 'A non-storage copy with rows must be refused, not dropped.' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test probe.
		$this->assertSame( 5, (int) $wpdb->get_var( "SELECT SUM(view_count) FROM `{$legacy_views}`" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test probe.
	}

	public function test_cleanup_refuses_when_the_storage_blog_is_unresolved(): void {
		$this->use_storage_blog( 0 );
		$this->assertWPError( extrachill_analytics_link_page_cleanup_orphan_tables( true ) );
	}

	/**
	 * Create a blog whose Link Page tables are dropped after the test.
	 *
	 * @param string $label Domain label.
	 * @return int
	 */
	private function blog( string $label ): int {
		$blog_id       = $this->create_blog( 'ec-lp-' . $label . '-' . uniqid() . '.example.org' );
		$this->blogs[] = $blog_id;
		return $blog_id;
	}

	/**
	 * Point the storage-blog filter at a blog.
	 *
	 * @param int $blog_id Blog ID.
	 */
	private function use_storage_blog( int $blog_id ): void {
		$this->storage_filter = static function () use ( $blog_id ) {
			return $blog_id;
		};
		add_filter( 'extrachill_analytics_link_page_storage_blog_id', $this->storage_filter );
	}

	/**
	 * Drop both Link Page tables and the version option on a blog.
	 *
	 * @param int $blog_id Blog ID.
	 */
	private function reset_blog_tables( int $blog_id ): void {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $blog_id );
		foreach ( array( 'extrch_link_page_daily_views', 'extrch_link_page_daily_link_clicks' ) as $suffix ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}{$suffix}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin-owned table, test fixture setup.
		}
		delete_blog_option( $blog_id, EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION_OPTION );
	}

	/**
	 * Whether both Link Page tables exist on a blog.
	 *
	 * @param int $blog_id Blog ID.
	 * @return bool
	 */
	private function blog_has_tables( int $blog_id ): bool {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $blog_id );
		foreach ( array( 'extrch_link_page_daily_views', 'extrch_link_page_daily_link_clicks' ) as $suffix ) {
			if ( $prefix . $suffix !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prefix . $suffix ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test probe.
				return false;
			}
		}
		return true;
	}

	/**
	 * Report actions for one blog, views table first.
	 *
	 * @param array $report  Cleanup report.
	 * @param int   $blog_id Blog ID.
	 * @return string[]
	 */
	private function actions_for( array $report, int $blog_id ): array {
		$actions = array();
		foreach ( $report['tables'] as $row ) {
			if ( $blog_id === $row['blog_id'] ) {
				$actions[] = $row['action'];
			}
		}
		return $actions;
	}
}
