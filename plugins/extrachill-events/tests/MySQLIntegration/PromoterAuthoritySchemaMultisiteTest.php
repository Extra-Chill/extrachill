<?php
/**
 * Promoter authority schema coverage on the canonical Events multisite blog.
 *
 * @package ExtraChillEvents\Tests\MySQLIntegration
 */

use ExtraChillEvents\Core\PromoterAuthoritySchema;

/** Proves promoter authority storage is strict, site-scoped, and idempotent. */
final class PromoterAuthoritySchemaMultisiteTest extends WP_UnitTestCase {

	/** Verify every table contract and a repeated install on blog 7. */
	public function test_installs_and_verifies_current_prefix_tables_after_switch(): void {
		global $wpdb;

		$this->assertTrue( is_multisite() );
		$this->assertSame( 1, get_current_blog_id() );

		switch_to_blog( 7 );
		try {
			$prefix    = $wpdb->get_blog_prefix( 7 );
			$contracts = array(
				PromoterAuthoritySchema::organizations_table() => array(
					'columns' => array( 'id', 'promoter_term_id', 'status', 'version', 'verified_by_user_id', 'verified_at', 'updated_at', 'revoked_by_user_id', 'revoked_at' ),
					'unique'  => array(
						'PRIMARY'          => array( 'id' ),
						'promoter_term_id' => array( 'promoter_term_id' ),
					),
				),
				PromoterAuthoritySchema::memberships_table() => array(
					'columns' => array( 'id', 'promoter_term_id', 'user_id', 'is_owner', 'status', 'version', 'created_by_user_id', 'created_at', 'updated_at', 'revoked_by_user_id', 'revoked_at' ),
					'unique'  => array(
						'PRIMARY'       => array( 'id' ),
						'promoter_user' => array( 'promoter_term_id', 'user_id' ),
					),
				),
				PromoterAuthoritySchema::activity_table() => array(
					'columns' => array( 'id', 'promoter_term_id', 'event', 'actor_user_id', 'subject_user_id', 'result_version', 'payload', 'created_at' ),
					'unique'  => array( 'PRIMARY' => array( 'id' ) ),
				),
				PromoterAuthoritySchema::venue_grants_table() => array(
					'columns' => array( 'id', 'promoter_term_id', 'venue_term_id', 'action', 'status', 'version', 'created_by_user_id', 'created_at', 'updated_by_user_id', 'updated_at', 'revoked_by_user_id', 'revoked_at' ),
					'unique'  => array(
						'PRIMARY'               => array( 'id' ),
						'promoter_venue_action' => array( 'promoter_term_id', 'venue_term_id', 'action' ),
					),
				),
			);

			$this->assertSame( $prefix, $wpdb->prefix );
			$this->assertTrue( PromoterAuthoritySchema::is_ready() );
			$this->assertTrue( PromoterAuthoritySchema::health() );
			$this->assertSame( PromoterAuthoritySchema::SCHEMA_VERSION, get_option( PromoterAuthoritySchema::VERSION_OPTION ) );
			$this->assertCount( 4, $contracts );

			foreach ( $contracts as $table => $contract ) {
				$this->assertStringStartsWith( $prefix, $table );
				$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Real schema assertion.
				$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Real engine assertion.
				$this->assertSame( 'innodb', strtolower( (string) $status['Engine'] ) );

				$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
				foreach ( $contract['columns'] as $column ) {
					$this->assertContains( $column, $columns, $table . '.' . $column );
				}

				$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
				foreach ( $contract['unique'] as $name => $expected_columns ) {
					$rows = array_values( array_filter( $indexes, static fn( array $index ): bool => $name === $index['Key_name'] && 0 === (int) $index['Non_unique'] ) );
					usort( $rows, static fn( array $left, array $right ): int => (int) $left['Seq_in_index'] <=> (int) $right['Seq_in_index'] );
					$this->assertSame( $expected_columns, array_column( $rows, 'Column_name' ), $table . '.' . $name );
				}
			}

			delete_option( PromoterAuthoritySchema::VERSION_OPTION );
			$this->assertFalse( PromoterAuthoritySchema::is_ready() );
			$this->assertTrue( PromoterAuthoritySchema::install() );
			$this->assertTrue( PromoterAuthoritySchema::health() );
			$this->assertSame( PromoterAuthoritySchema::SCHEMA_VERSION, get_option( PromoterAuthoritySchema::VERSION_OPTION ) );
			$this->assertFalse( get_option( PromoterAuthoritySchema::FAILURE_OPTION, false ) );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( 1, get_current_blog_id() );
	}

	/** Prove the v1 authority rows survive creation of the v2 grant table. */
	public function test_v1_to_v2_upgrade_preserves_authority_rows(): void {
		global $wpdb;

		$this->assertSame( 1, get_current_blog_id() );
		$promoter_term_id = wp_rand( 900000, 999999 );
		switch_to_blog( 7 );
		try {
			$organizations = PromoterAuthoritySchema::organizations_table();
			$memberships   = PromoterAuthoritySchema::memberships_table();
			$activity      = PromoterAuthoritySchema::activity_table();
			$grants        = PromoterAuthoritySchema::venue_grants_table();
			$created       = '2026-08-22 12:00:00';
			$this->assertSame(
				1,
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Disposable migration fixture.
				$wpdb->insert(
					$organizations,
					array(
						'promoter_term_id'    => $promoter_term_id,
						'status'              => 'active',
						'version'             => 3,
						'verified_by_user_id' => 1,
						'verified_at'         => $created,
						'updated_at'          => $created,
					)
				)
			);
			$this->assertSame(
				1,
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Disposable migration fixture.
				$wpdb->insert(
					$memberships,
					array(
						'promoter_term_id'   => $promoter_term_id,
						'user_id'            => 1,
						'is_owner'           => 1,
						'status'             => 'active',
						'version'            => 2,
						'created_by_user_id' => 1,
						'created_at'         => $created,
						'updated_at'         => $created,
					)
				)
			);
			$this->assertSame(
				1,
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Disposable migration fixture.
				$wpdb->insert(
					$activity,
					array(
						'promoter_term_id' => $promoter_term_id,
						'event'            => 'legacy_v1_evidence',
						'actor_user_id'    => 1,
						'result_version'   => 3,
						'payload'          => '{"legacy":true}',
						'created_at'       => $created,
					)
				)
			);

			// This migration proof needs persistent DDL rather than the temporary
			// table rewrites applied by WP_UnitTestCase around normal test queries.
			remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
			remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
			$wpdb->query( "DROP TABLE `{$grants}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Recreates the exact v1 schema in a disposable database.
			$this->assertSame( '', (string) $wpdb->last_error );
			update_option( PromoterAuthoritySchema::VERSION_OPTION, '1', false );

			$this->assertTrue( PromoterAuthoritySchema::install() );
			$this->assertTrue( PromoterAuthoritySchema::health() );
			$this->assertSame( PromoterAuthoritySchema::SCHEMA_VERSION, get_option( PromoterAuthoritySchema::VERSION_OPTION ) );
			$this->assertSame( $grants, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $grants ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Real v2 table assertion.
			$this->assertSame( '3', $wpdb->get_var( $wpdb->prepare( "SELECT version FROM `{$organizations}` WHERE promoter_term_id = %d", $promoter_term_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Preserved v1 organization assertion.
			$this->assertSame( '2', $wpdb->get_var( $wpdb->prepare( "SELECT version FROM `{$memberships}` WHERE promoter_term_id = %d AND user_id = 1", $promoter_term_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Preserved v1 membership assertion.
			$this->assertSame( '{"legacy":true}', $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM `{$activity}` WHERE promoter_term_id = %d AND event = 'legacy_v1_evidence'", $promoter_term_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Preserved v1 activity assertion.
		} finally {
			if ( ! PromoterAuthoritySchema::is_ready() || true !== PromoterAuthoritySchema::health() ) {
				PromoterAuthoritySchema::install();
			}
			foreach ( array( PromoterAuthoritySchema::activity_table(), PromoterAuthoritySchema::memberships_table(), PromoterAuthoritySchema::organizations_table() ) as $table ) {
				$wpdb->delete( $table, array( 'promoter_term_id' => $promoter_term_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable migration fixture cleanup.
			}
			restore_current_blog();
		}

		$this->assertSame( 1, get_current_blog_id() );
	}
}
