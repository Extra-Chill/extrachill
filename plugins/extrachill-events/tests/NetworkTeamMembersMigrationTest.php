<?php
/** Read-only inspection and safe deploy adoption proofs. */

use ExtraChillEvents\Core\NetworkTeamMembersMigration;

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
		} finally {
			$GLOBALS['wpdb'] = $original;
		}
	}
}
