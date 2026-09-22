<?php
/**
 * Executable database behavior for the Link Page schema-reconciliation
 * routine (#287).
 *
 * `extrachill_analytics_link_page_migration_reconcile_table()` issues real
 * `ALTER TABLE` DDL (`MODIFY COLUMN ... FIRST/AFTER`, `DROP INDEX`,
 * `ADD (UNIQUE) KEY`) against a per-site table. The managed WordPress test
 * harness runs on a SQLite-backed MySQL-grammar translator: `SHOW COLUMNS`
 * and `SHOW INDEX` are faithfully reported and `DROP INDEX` / `ADD (UNIQUE)
 * KEY` are correctly applied, so the index-widening fix that stops
 * blog 4's row-collapsing bug is verified end-to-end here against real
 * rows. Column *repositioning* is not: the harness silently accepts
 * `MODIFY COLUMN ... FIRST` / `... AFTER \`col\`` (and the `CHANGE COLUMN`
 * equivalent) without error but never actually moves the column — verified
 * directly against a two-column throwaway table before writing this
 * docblock. This is a harness limitation, not a production one: `MODIFY
 * COLUMN ... AFTER` is standard, long-supported MySQL/MariaDB DDL, and it is
 * the exact statement shape used to hand-repair blog 4's real
 * `link_page_id, stat_date, link_url, click_count, link_text` drift before
 * this routine existed (per Extra-Chill/extrachill-analytics#287). Tests
 * that exercise column reordering therefore assert the generated SQL text
 * instead of the post-ALTER column order.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Verify the versioned schema-reconciliation routine against real tables.
 */
final class LinkPageSchemaReconciliationTest extends Extrachill_Analytics_TestCase {

	/**
	 * A fresh create_table() satisfies the migration contract exactly, for
	 * both owned tables. This is the regression test #287 asked for: it
	 * would have caught link_text shipping outside unique_daily_link_click's
	 * contract the moment the contract and the CREATE TABLE SQL diverged.
	 */
	public function test_fresh_create_table_satisfies_migration_contract_exactly(): void {
		delete_option( EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION_OPTION );
		extrachill_analytics_link_page_create_table();

		$views_ready = extrachill_analytics_link_page_migration_table_ready(
			extrachill_analytics_link_page_views_table(),
			extrachill_analytics_link_page_migration_view_columns(),
			extrachill_analytics_link_page_migration_view_indexes()
		);
		$clicks_ready = extrachill_analytics_link_page_migration_table_ready(
			extrachill_analytics_link_page_clicks_table(),
			extrachill_analytics_link_page_migration_click_columns(),
			extrachill_analytics_link_page_migration_click_indexes()
		);

		$this->assertTrue( $views_ready, is_wp_error( $views_ready ) ? $views_ready->get_error_message() . ' ' . wp_json_encode( $views_ready->get_error_data() ) : '' );
		$this->assertTrue( $clicks_ready, is_wp_error( $clicks_ready ) ? $clicks_ready->get_error_message() . ' ' . wp_json_encode( $clicks_ready->get_error_data() ) : '' );
	}

	/**
	 * A table already on the current contract costs nothing: reconciliation
	 * issues no DDL at all.
	 */
	public function test_reconcile_is_a_cheap_no_op_once_the_contract_already_matches(): void {
		delete_option( EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION_OPTION );
		extrachill_analytics_link_page_create_table();
		$table = extrachill_analytics_link_page_clicks_table();

		$captured = $this->capture_queries();
		$result   = extrachill_analytics_link_page_migration_reconcile_table(
			$table,
			extrachill_analytics_link_page_migration_click_columns(),
			extrachill_analytics_link_page_migration_click_indexes()
		);
		$alters   = preg_grep( '/^ALTER TABLE/i', $captured->queries );

		$this->assertTrue( $result );
		$this->assertSame( array(), array_values( $alters ) );
	}

	/**
	 * A table whose unique key drifted to the pre-link_text 3-column shape —
	 * the actual data-corrupting half of #287 — is rebuilt to the 4-column
	 * contract with zero row loss, and the routine is idempotent.
	 */
	public function test_reconcile_rebuilds_a_widened_unique_key_without_row_loss_and_is_idempotent(): void {
		global $wpdb;
		$table           = extrachill_analytics_link_page_clicks_table();
		$charset_collate = $wpdb->get_charset_collate();

		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table, test fixture setup.
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Reproduces the pre-link_text-in-key shape directly; column order matches the contract so only the index is under test here.
			"CREATE TABLE `{$table}` (
				click_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				link_page_id bigint(20) unsigned NOT NULL,
				stat_date date NOT NULL,
				link_url varchar(2083) NOT NULL,
				link_text varchar(255) NOT NULL DEFAULT '',
				click_count bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (click_id),
				UNIQUE KEY unique_daily_link_click (link_page_id, stat_date, link_url(191)),
				KEY link_page_date (link_page_id, stat_date)
			) {$charset_collate};"
		);

		$wpdb->insert( $table, array( 'link_page_id' => 42, 'stat_date' => '2026-08-25', 'link_url' => 'https://example.com/a', 'link_text' => 'Buy Tickets', 'click_count' => 3 ), array( '%d', '%s', '%s', '%s', '%d' ) );
		$wpdb->insert( $table, array( 'link_page_id' => 42, 'stat_date' => '2026-08-25', 'link_url' => 'https://example.com/b', 'link_text' => '', 'click_count' => 7 ), array( '%d', '%s', '%s', '%s', '%d' ) );
		$this->assertSame( '', (string) $wpdb->last_error );

		$before = $this->read_rows( $table );
		$this->assertCount( 2, $before );

		$columns = extrachill_analytics_link_page_migration_click_columns();
		$indexes = extrachill_analytics_link_page_migration_click_indexes();

		$result = extrachill_analytics_link_page_migration_reconcile_table( $table, $columns, $indexes );
		$this->assertTrue( $result, is_wp_error( $result ) ? $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : '' );

		$ready = extrachill_analytics_link_page_migration_table_ready( $table, $columns, $indexes );
		$this->assertTrue( $ready, is_wp_error( $ready ) ? $ready->get_error_message() . ' ' . wp_json_encode( $ready->get_error_data() ) : '' );

		$after = $this->read_rows( $table );
		$this->assertSame( $before, $after, 'Reconciling a widened unique key must not lose or alter any existing row.' );

		$captured = $this->capture_queries();
		$second   = extrachill_analytics_link_page_migration_reconcile_table( $table, $columns, $indexes );
		$this->assertTrue( $second );
		$this->assertSame( array(), array_values( preg_grep( '/^ALTER TABLE/i', $captured->queries ) ), 'A second reconcile call against an already-reconciled table must be a no-op.' );
	}

	/**
	 * The full blog 4 drift — wrong column order *and* the narrow unique
	 * key — is reconciled without row loss. Column order in the destination
	 * table cannot be asserted on this harness (see class docblock), so this
	 * test instead asserts the exact ALTER TABLE statements generated to fix
	 * it: byte-identical to the MODIFY COLUMN ... AFTER technique the issue
	 * used to hand-repair blog 4 on real MySQL.
	 */
	public function test_reconcile_generates_correct_ddl_for_the_full_blog4_drift_and_preserves_rows(): void {
		global $wpdb;
		$table           = extrachill_analytics_link_page_clicks_table();
		$charset_collate = $wpdb->get_charset_collate();

		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table, test fixture setup.
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Reproduces blog 4's exact documented drift: link_url, click_count, link_text order and a 3-column unique key.
			"CREATE TABLE `{$table}` (
				click_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				link_page_id bigint(20) unsigned NOT NULL,
				stat_date date NOT NULL,
				link_url varchar(2083) NOT NULL,
				click_count bigint(20) unsigned NOT NULL DEFAULT 0,
				link_text varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (click_id),
				UNIQUE KEY unique_daily_link_click (link_page_id, stat_date, link_url(191)),
				KEY link_page_date (link_page_id, stat_date)
			) {$charset_collate};"
		);
		$wpdb->insert( $table, array( 'link_page_id' => 42, 'stat_date' => '2026-08-25', 'link_url' => 'https://example.com/a', 'click_count' => 3, 'link_text' => 'Buy Tickets' ), array( '%d', '%s', '%s', '%d', '%s' ) );
		$this->assertSame( '', (string) $wpdb->last_error );

		$before = $this->read_rows( $table );

		$captured = $this->capture_queries();
		$result   = extrachill_analytics_link_page_migration_reconcile_table(
			$table,
			extrachill_analytics_link_page_migration_click_columns(),
			extrachill_analytics_link_page_migration_click_indexes()
		);
		$alters = array_values( preg_grep( '/^ALTER TABLE/i', $captured->queries ) );

		// The harness accepts but does not apply MODIFY COLUMN's position
		// clause, so the top-level `table_ready()` recheck inside
		// reconcile_table() still reports the column-order mismatch it
		// cannot see fixed — that recheck failure is the harness artifact
		// documented above, not evidence the DDL was wrong.
		$this->assertTrue(
			is_wp_error( $result ) && 'analytics_link_page_migration_schema_mismatch' === $result->get_error_code(),
			is_wp_error( $result ) ? $result->get_error_message() : wp_json_encode( $result )
		);

		$this->assertSame(
			array(
				"ALTER TABLE `{$table}` MODIFY COLUMN `click_id` bigint(20) unsigned NOT NULL auto_increment FIRST",
				"ALTER TABLE `{$table}` MODIFY COLUMN `link_page_id` bigint(20) unsigned NOT NULL AFTER `click_id`",
				"ALTER TABLE `{$table}` MODIFY COLUMN `stat_date` date NOT NULL AFTER `link_page_id`",
				"ALTER TABLE `{$table}` MODIFY COLUMN `link_url` varchar(2083) NOT NULL AFTER `stat_date`",
				"ALTER TABLE `{$table}` MODIFY COLUMN `link_text` varchar(255) NOT NULL DEFAULT '' AFTER `link_url`",
				"ALTER TABLE `{$table}` MODIFY COLUMN `click_count` bigint(20) unsigned NOT NULL DEFAULT '0' AFTER `link_text`",
				"ALTER TABLE `{$table}` DROP INDEX `unique_daily_link_click`",
				"ALTER TABLE `{$table}` ADD UNIQUE KEY `unique_daily_link_click` (`link_page_id`, `stat_date`, `link_url`(191), `link_text`(100))",
			),
			$alters,
			'Every column must be repositioned into contract order via MODIFY COLUMN ... FIRST/AFTER, and the narrow unique key replaced with the 4-column contract.'
		);

		$after = $this->read_rows( $table );
		$this->assertSame( $before, $after, 'No row may be lost or have a value altered while columns are repositioned and the unique key is rebuilt.' );
	}

	/**
	 * The click/view writers guard every insert with the schema-readiness
	 * check, the same source-level convention `SchemaReadinessTest` already
	 * enforces for the network-wide events writer. This is what makes a
	 * passive artist site — front-end click/view traffic only, no admin_init
	 * ever fires — reconcile itself instead of drifting forever (#287).
	 */
	public function test_write_functions_guard_every_insert_with_schema_ensure_ready(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/link-page-analytics.php' );

		$view_start  = strpos( $source, 'function extrachill_analytics_handle_link_page_view_db_write' );
		$click_start = strpos( $source, 'function extrachill_analytics_handle_link_click_db_write' );
		$this->assertNotFalse( $view_start );
		$this->assertNotFalse( $click_start );

		$bodies = array(
			'view writer'  => substr( $source, $view_start, $click_start - $view_start ),
			'click writer' => substr( $source, $click_start ),
		);
		foreach ( $bodies as $label => $body ) {
			$guard  = strpos( $body, 'extrachill_analytics_link_page_schema_ensure_ready();' );
			$insert = strpos( $body, '$wpdb->query(' );
			$this->assertNotFalse( $guard, "The {$label} must call the schema-readiness guard." );
			$this->assertNotFalse( $insert, "The {$label} must issue its owned query." );
			$this->assertLessThan( $insert, $guard, "The {$label} must call ensure_ready() before its write." );
		}
	}

	/**
	 * The upgrade sweep is a no-op for an irrelevant plugin update, and for
	 * this plugin's own update it reconciles every network site — not only
	 * the site running the upgrade — and always restores the blog the
	 * request started on.
	 *
	 * A dedicated second site makes the "every network site" claim concrete
	 * and avoids depending on the ambient state of the harness's default
	 * test site, which earlier tests in this file leave with a deliberately
	 * drifted (and, per the class docblock, harness-unreorderable) clicks
	 * table.
	 */
	public function test_network_upgrade_sweep_targets_only_this_plugin_and_reconciles_every_site(): void {
		global $wpdb;
		$started_on = get_current_blog_id();
		$other_blog = $this->create_blog( 'ec-sweep-probe-' . uniqid() . '.example.org' );

		switch_to_blog( $other_blog );
		delete_option( EXTRACHILL_ANALYTICS_LINK_PAGE_DB_VERSION_OPTION );
		$wpdb->query( 'DROP TABLE IF EXISTS `' . extrachill_analytics_link_page_clicks_table() . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table, test fixture setup.
		restore_current_blog();
		$this->assertSame( $started_on, get_current_blog_id() );

		extrachill_analytics_link_page_reconcile_network_on_upgrade(
			null,
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'plugins' => array( 'some-other-plugin/some-other-plugin.php' ),
			)
		);
		$this->assertSame( $started_on, get_current_blog_id(), 'An irrelevant plugin update must not touch the current blog.' );
		switch_to_blog( $other_blog );
		$missing = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', extrachill_analytics_link_page_clicks_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table existence probe.
		restore_current_blog();
		$this->assertNull( $missing, 'An irrelevant plugin update must not touch any network site.' );

		extrachill_analytics_link_page_reconcile_network_on_upgrade(
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'extrachill-analytics/extrachill-analytics.php',
			)
		);
		$this->assertSame( $started_on, get_current_blog_id(), 'The sweep must always restore the blog it started on.' );

		switch_to_blog( $other_blog );
		$ready = extrachill_analytics_link_page_migration_table_ready(
			extrachill_analytics_link_page_clicks_table(),
			extrachill_analytics_link_page_migration_click_columns(),
			extrachill_analytics_link_page_migration_click_indexes()
		);
		restore_current_blog();
		$this->assertTrue( $ready, 'The sweep must reconcile every network site, not only the one running the upgrade.' );
	}

	/**
	 * Read every owned row, sorted for a value-only (order-independent)
	 * comparison — column *order* is deliberately not part of this
	 * assertion (see class docblock).
	 *
	 * @param string $table Table name.
	 * @return array<int,array<string,mixed>>
	 */
	private function read_rows( string $table ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY click_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table, test-only read.
		$sorted = array();
		foreach ( $rows as $row ) {
			ksort( $row );
			$sorted[] = $row;
		}
		return $sorted;
	}
}
