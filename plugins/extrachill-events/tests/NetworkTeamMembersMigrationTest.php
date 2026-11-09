<?php
/** Read-only inspection and safe deploy adoption proofs. */

use ExtraChillEvents\Core\NetworkTeamMembersMigration;

if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( $name, $fallback = false ) {
		return $GLOBALS['ec_test_site_options'][ $name ] ?? $fallback;
	}
}
if ( ! function_exists( 'update_site_option' ) ) {
	function update_site_option( $name, $value ) {
		$GLOBALS['ec_test_site_options'][ $name ] = $value;
		return true;
	}
}

final class NetworkTeamMembersMigrationTest extends PHPUnit\Framework\TestCase {
	public function test_inspection_never_writes_and_copy_is_idempotent_sql(): void {
		$original = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class {
			public $base_prefix = 'wp_';
			public $last_error = '';
			public $queries = array();
			public $missing = 7;
			public function get_blog_prefix( $id ) { return 'wp_' . $id . '_'; }
			public function esc_like( $value ) { return $value; }
			public function prepare( $query, $value ) { return str_replace( '%s', $value, $query ); }
			public function get_var( $query ) {
				return str_starts_with( $query, 'SHOW TABLES' ) ? NetworkTeamMembersMigration::legacy_table() : $this->missing;
			}
			public function query( $query ) { $this->queries[] = $query; return 7; }
		};
		try {
			$this->assertSame( array( 'status' => 'pending', 'missing' => 7 ), NetworkTeamMembersMigration::inspect() );
			$this->assertSame( array(), $GLOBALS['wpdb']->queries );
			$this->assertTrue( NetworkTeamMembersMigration::copy_legacy() );
			$this->assertTrue( NetworkTeamMembersMigration::copy_legacy() );
			$this->assertSame( $GLOBALS['wpdb']->queries[0], $GLOBALS['wpdb']->queries[1] );
			$this->assertStringStartsWith( 'INSERT IGNORE INTO ' . \ExtraChill\Users\Teams\TeamMembershipRepository::table(), $GLOBALS['wpdb']->queries[0] );
			$this->assertStringContainsString( '(team_type, team_id, user_id', $GLOBALS['wpdb']->queries[0] );
			$this->assertStringContainsString( 'SELECT team_type, team_term_id, user_id', $GLOBALS['wpdb']->queries[0] );
			$this->assertFalse( NetworkTeamMembersMigration::apply()['success'] );
			$this->assertCount( 2, $GLOBALS['wpdb']->queries, 'Non-CLI apply must never write.' );

			$GLOBALS['ec_test_site_options'] = array();
			NetworkTeamMembersMigration::maybe_copy_legacy();
			NetworkTeamMembersMigration::maybe_copy_legacy();
			$this->assertCount( 3, $GLOBALS['wpdb']->queries, 'Deploy copy runs once, not on every request.' );
			$this->assertSame( 1, $GLOBALS['ec_test_site_options'][ NetworkTeamMembersMigration::COPIED_OPTION ] );
		} finally {
			$GLOBALS['wpdb'] = $original;
		}
	}

	public function test_single_site_never_treats_the_network_table_as_a_source(): void {
		$original = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class {
			public $base_prefix = 'wp_';
			public $queries = array();
			public function get_blog_prefix( $id ) { return 'wp_'; }
			public function get_var( $query ) { $this->queries[] = $query; return 'wp_ec_team_members'; }
			public function query( $query ) { $this->queries[] = $query; return 1; }
		};
		try {
			$this->assertFalse( NetworkTeamMembersMigration::exists() );
			$this->assertTrue( NetworkTeamMembersMigration::copy_legacy() );
			$this->assertSame( array(), $GLOBALS['wpdb']->queries );
		} finally {
			$GLOBALS['wpdb'] = $original;
		}
	}
}
