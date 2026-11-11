<?php
/** Standalone retirement migration coverage. */
use ExtraChillEvents\Core\NetworkTeamInvitationsMigration;
use PHPUnit\Framework\TestCase;

define( 'ABSPATH', __DIR__ );
define( 'WP_CLI', true );
function add_filter( $name, $callback ) { $GLOBALS['migration_filter'] = $callback; }
function ec_get_blog_id( $name ) { return 7; }
require_once dirname( __DIR__ ) . '/inc/Core/NetworkTeamInvitationsMigration.php';

final class NetworkTeamInvitationsMigrationTest extends TestCase {
	public function test_inspect_block_and_retire(): void {
		$GLOBALS['wpdb'] = new class {
			public $base_prefix = 'wp_';
			public $last_error = '';
			public $tables = array( 'wp_7_ec_team_invitations' => 0, 'wp_7_ec_team_claims' => 2 );
			public $drops = array();
			public function get_blog_prefix( $id ) { return 'wp_' . $id . '_'; }
			public function esc_like( $value ) { return addcslashes( $value, '_%' ); }
			public function prepare( $sql, $value ) { return str_replace( array( '%s', '%i' ), array( "'{$value}'", "`{$value}`" ), $sql ); }
			public function get_var( $sql ) {
				if ( preg_match( "/SHOW TABLES LIKE '(.*)'/", $sql, $match ) ) {
					$table = stripslashes( $match[1] );
					return array_key_exists( $table, $this->tables ) ? $table : null;
				}
				preg_match( '/FROM `([^`]+)`/', $sql, $match );
				return $this->tables[ $match[1] ];
			}
			public function query( $sql ) {
				$this->drops[] = $sql;
				preg_match( '/DROP TABLE `([^`]+)`/', $sql, $match );
				unset( $this->tables[ $match[1] ] );
				return true;
			}
		};
		$db = $GLOBALS['wpdb'];
		$state = NetworkTeamInvitationsMigration::inspect();
		$this->assertSame( 2, $state['tables']['wp_7_ec_team_claims']['rows'] );
		$this->assertTrue( $state['tables']['wp_7_ec_team_invitations']['exists'] );
		$this->assertSame( array(), $db->drops );
		$this->assertFalse( NetworkTeamInvitationsMigration::isComplete() );
		$this->assertSame( 'blocked', NetworkTeamInvitationsMigration::apply()['status'] );
		$this->assertCount( 2, $db->tables );
		$this->assertSame( array(), $db->drops );
		$db->tables['wp_7_ec_team_claims'] = 0;
		$this->assertTrue( NetworkTeamInvitationsMigration::apply()['complete'] );
		$this->assertCount( 2, $db->drops );
		$this->assertTrue( NetworkTeamInvitationsMigration::isComplete() );
		$this->assertFalse( NetworkTeamInvitationsMigration::inspect()['tables']['wp_7_ec_team_claims']['exists'] );
		$this->assertTrue( NetworkTeamInvitationsMigration::apply()['success'] );
		$this->assertCount( 2, $db->drops );
	}
}
