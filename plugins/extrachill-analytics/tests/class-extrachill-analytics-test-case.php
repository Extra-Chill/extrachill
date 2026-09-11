<?php
/**
 * Shared base test case for Extra Chill Analytics on the managed WordPress harness.
 *
 * Provides request-scope toggles (query flags, admin screen, ajax/cron filters,
 * external object cache) and custom-table cleanup between tests. WordPress
 * core function stubs are prohibited: every WordPress behavior exercised here
 * is real, driven through WordPress's own extension points.
 *
 * @package ExtraChill\Analytics
 */

/**
 * Base test case for analytics tests that exercise real WordPress state.
 */
abstract class Extrachill_Analytics_TestCase extends WP_UnitTestCase {

	/**
	 * Cron filter and ajax/cron simulation callbacks to unwind.
	 *
	 * @var array<int, array{string, callable}>
	 */
	private $request_filters = array();

	/**
	 * Real wpdb captured at set_up and restored at tear_down.
	 *
	 * @var wpdb|null
	 */
	protected $original_wpdb;

	/**
	 * Skip the per-test database transaction on the SQLite harness.
	 *
	 * wp-phpunit's start_transaction() issues MySQL-only syntax
	 * (SET autocommit = 0). WP_SQLite_DB mis-emulates the resulting transaction:
	 * a later insert can roll back earlier unrelated writes mid-test (observed:
	 * an events-table insert erased prior postmeta writes while the insert
	 * itself survived). Per-statement autocommit is deterministic on SQLite;
	 * isolation comes from reset_analytics_tables() plus WordPress core's data
	 * cleanup instead of rollback.
	 */
	public function start_transaction(): void {
		global $wpdb;
		if ( 'WP_SQLite_DB' === get_class( $wpdb ) ) {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
			return;
		}
		parent::start_transaction();
	}

	/**
	 * Reset request scope and custom tables before every test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->original_wpdb = $GLOBALS['wpdb'];

		// The harness install stores the site_admins network option as the
		// literal string "" instead of an array, which breaks grant_super_admin()
		// and every manage_network_options check. Restore the WordPress default.
		if ( ! is_array( get_site_option( 'site_admins' ) ) ) {
			update_site_option( 'site_admins', array( sanitize_user( 'admin' ) ) );
		}

		$this->reset_analytics_tables();
		wp_cache_flush();

		global $wp_query, $_wp_using_ext_object_cache;
		foreach ( array(
			'is_preview',
			'is_admin',
			'is_search',
			'is_front_page',
			'is_home',
			'is_archive',
			'is_singular',
			'is_page',
			'is_single',
			'is_category',
			'is_tag',
			'is_tax',
			'is_date',
			'is_day',
			'is_month',
			'is_year',
			'is_time',
			'is_author',
			'is_feed',
			'is_robots',
			'is_404',
		) as $flag ) {
			$wp_query->$flag = false;
		}
		$_wp_using_ext_object_cache = null;
		unset( $GLOBALS['current_screen'] );

		$_COOKIE = array();
		$this->unset_server_keys(
			array(
				'HTTP_HOST',
				'HTTP_ORIGIN',
				'HTTP_REFERER',
				'HTTP_USER_AGENT',
				'HTTP_SEC_GPC',
				'HTTP_DNT',
				'REMOTE_ADDR',
				'REQUEST_METHOD',
				'REQUEST_URI',
				'QUERY_STRING',
			)
		);
	}

	/**
	 * Remove request filters and restore request scope after every test.
	 */
	public function tear_down(): void {
		if ( null !== $this->original_wpdb && isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}

		foreach ( $this->request_filters as $entry ) {
			remove_filter( $entry[0], $entry[1] );
		}
		$this->request_filters = array();

		parent::tear_down();
	}

	/**
	 * Create a real network site and return its resolved blog ID.
	 *
	 * Two harness quirks are absorbed here: the FIRST wp_insert_site() call on
	 * a fresh test install fails silently (the factory suppresses the error and
	 * no site row is created), so a throwaway warm-up site is created first;
	 * and the SQLite adapter can report a stale insert ID, so the site is
	 * resolved by domain instead of trusting the factory return value. The
	 * silent first-insert failure is tracked upstream against the harness.
	 *
	 * @param string $domain Site domain.
	 * @return int Blog ID.
	 */
	protected function create_blog( string $domain ): int {
		static $warmed_up = false;
		if ( ! $warmed_up ) {
			self::factory()->blog->create(
				array(
					'domain' => 'warmup.example.org',
					'path'   => '/',
				)
			);
			$warmed_up = true;
		}
		self::factory()->blog->create(
			array(
				'domain' => $domain,
				'path'   => '/',
			)
		);
		$sites = get_sites(
			array(
				'domain' => $domain,
				'path'   => '/',
				'number' => 1,
			)
		);
		$site  = reset( $sites );
		$this->assertNotFalse( $site, 'Created site should exist: ' . $domain );
		$blog_id = (int) $site->blog_id;

		// The harness database engine can store the new site's role options in
		// a form that fails to unserialize, which breaks every capability check
		// on that site. Re-seed it from the main site's role map.
		global $wpdb;
		$roles_option = $wpdb->get_blog_prefix( $blog_id ) . 'user_roles';
		if ( ! is_array( get_blog_option( $blog_id, $roles_option ) ) ) {
			update_blog_option( $blog_id, $roles_option, get_blog_option( 1, $wpdb->base_prefix . 'user_roles' ) );
		}
		return $blog_id;
	}

	/**
	 * Simulate a public request method and host.
	 *
	 * @param string $host   HTTP_HOST value.
	 * @param string $method REQUEST_METHOD value.
	 */
	protected function set_request( $host = 'example.org', $method = 'GET' ): void {
		$_SERVER['HTTP_HOST']      = $host;
		$_SERVER['REQUEST_METHOD'] = $method;
	}

	/**
	 * Toggle WP_Query conditional flags on the main query.
	 *
	 * @param array<string,bool> $flags Conditional name => value.
	 */
	protected function set_query_flags( array $flags ): void {
		global $wp_query;
		foreach ( $flags as $flag => $value ) {
			$wp_query->$flag = (bool) $value;
		}
	}

	/**
	 * Simulate an admin request via the current screen.
	 */
	protected function set_admin_context(): void {
		if ( ! class_exists( 'WP_Screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		if ( ! isset( $GLOBALS['current_screen'] ) ) {
			$GLOBALS['current_screen'] = WP_Screen::get( 'dashboard' );
		}
	}

	/**
	 * Toggle the wp_doing_ajax() / wp_doing_cron() filters.
	 *
	 * @param bool $ajax Whether the request looks like AJAX.
	 * @param bool $cron Whether the request looks like cron.
	 */
	protected function set_doing_context( $ajax = false, $cron = false ): void {
		$this->set_filter( 'wp_doing_ajax', $ajax );
		$this->set_filter( 'wp_doing_cron', $cron );
	}

	/**
	 * Force one filter-style toggle and remember it for tear_down.
	 *
	 * @param string $hook  Filter name.
	 * @param bool   $value Return value.
	 */
	private function set_filter( $hook, $value ): void {
		$callback = static function () use ( $value ) {
			return $value;
		};
		add_filter( $hook, $callback );
		$this->request_filters[] = array( $hook, $callback );
	}

	/**
	 * Toggle whether WordPress believes an external object cache is active.
	 *
	 * @param bool|null $using True/false to force, null to restore discovery.
	 */
	protected function set_ext_object_cache( $using ): void {
		global $_wp_using_ext_object_cache;
		$_wp_using_ext_object_cache = $using;
	}

	/**
	 * Unset a whitelist of $_SERVER keys.
	 *
	 * @param array<int,string> $keys Server keys.
	 */
	protected function unset_server_keys( array $keys ): void {
		foreach ( $keys as $key ) {
			unset( $_SERVER[ $key ] );
		}
	}

	/**
	 * Whether the harness database engine provides the JSON1 functions.
	 *
	 * The managed SQLite build can lack JSON_UNQUOTE/JSON_EXTRACT, which the
	 * JSON-based report queries legitimately use. Tests covering those queries
	 * skip with this documented reason instead of failing on engine capability.
	 *
	 * @return bool
	 */
	protected function sqlite_has_json1(): bool {
		static $has_json1 = null;
		if ( null === $has_json1 ) {
			global $wpdb;
			$has_json1 = null !== $wpdb->get_var( "SELECT JSON_UNQUOTE(JSON_EXTRACT('{}', '$.a'))" );
		}
		return (bool) $has_json1;
	}

	/**
	 * Delete every row from the plugin's custom tables.
	 */
	protected function reset_analytics_tables(): void {
		global $wpdb;
		foreach ( array(
			$wpdb->base_prefix . 'extrachill_analytics_events',
			$wpdb->base_prefix . 'extrachill_analytics_php_errors',
			$wpdb->base_prefix . 'extrachill_analytics_mediavine_revenue',
			$wpdb->base_prefix . 'extrachill_link_page_analytics',
		) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is plugin-owned and allow-listed.
		}
	}

	/**
	 * Count analytics events, optionally filtered by type.
	 *
	 * @param string $event_type Optional event type filter.
	 * @return int
	 */
	protected function event_count( $event_type = '' ): int {
		global $wpdb;
		$table = extrachill_analytics_events_table();
		if ( '' === $event_type ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table.
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_type = %s', $table, $event_type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table.
	}

	/**
	 * Fetch analytics event rows, oldest first.
	 *
	 * @param string $event_type Optional event type filter.
	 * @return array<int, object>
	 */
	protected function event_rows( $event_type = '' ): array {
		global $wpdb;
		$table = extrachill_analytics_events_table();
		if ( '' === $event_type ) {
			return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table.
		}
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE event_type = %s ORDER BY id ASC', $table, $event_type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table.
	}

	/**
	 * Decode the event_data JSON of one event row.
	 *
	 * @param object $row Event row.
	 * @return array
	 */
	protected function event_data( $row ): array {
		return (array) json_decode( (string) $row->event_data, true );
	}

	/**
	 * Capture every executed SQL string through the wpdb query filter.
	 *
	 * The filter is removed automatically in tear_down().
	 *
	 * @return object Captured queries on ->queries.
	 */
	protected function capture_queries(): object {
		$captured          = new stdClass();
		$captured->queries = array();
		$captured_callback = static function ( $query ) use ( $captured ) {
			$captured->queries[] = (string) $query;
			return $query;
		};
		add_filter( 'query', $captured_callback );
		$this->request_filters[] = array( 'query', $captured_callback );
		return $captured;
	}
}
