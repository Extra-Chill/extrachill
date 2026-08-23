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
			);

			$this->assertSame( $prefix, $wpdb->prefix );
			$this->assertTrue( PromoterAuthoritySchema::is_ready() );
			$this->assertTrue( PromoterAuthoritySchema::health() );
			$this->assertSame( PromoterAuthoritySchema::SCHEMA_VERSION, get_option( PromoterAuthoritySchema::VERSION_OPTION ) );
			$this->assertCount( 3, $contracts );

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
}
