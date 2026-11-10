<?php
/**
 * Repository transaction-lock contract tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\VenueMembershipRepository;
use PHPUnit\Framework\TestCase;

/** Tests raw lock results and database failures without owning the transaction. */
final class VenueMembershipLockTest extends TestCase {
	/** Both lock methods preserve database error details. */
	public function test_lock_database_errors(): void {
		$original = $GLOBALS['wpdb'];
		try {
			$GLOBALS['wpdb'] = new class() {
				public $base_prefix = 'wp_';
	public $prefix     = 'wp_7_';
				public $last_error = '';
				public $queries    = array();

				public function prepare( $query, ...$values ) {
					return vsprintf( $query, 1 === count( $values ) && is_array( $values[0] ) ? $values[0] : $values );
				}

				public function get_results( $query, $format ) {
					$this->queries[]  = $query;
					$this->last_error = 'lock timeout';
					return null;
				}
			};
			$repository      = new VenueMembershipRepository();
			foreach ( array( $repository->lock_for_venue( 55 ), $repository->lock_member( 55, 22 ) ) as $error ) {
				$this->assertInstanceOf( WP_Error::class, $error );
				$this->assertSame( 'venue_membership_read_failed', $error->get_error_code() );
				$this->assertSame( 'Venue memberships could not be locked.', $error->get_error_message() );
				$this->assertSame( array( 'database_error' => 'lock timeout' ), $error->get_error_data() );
			}
			$columns = 'id, team_id AS venue_term_id, user_id, is_owner, status, version, created_by_user_id, created_at, updated_at, revoked_at';
			$this->assertSame(
				array(
					"SELECT {$columns} FROM wp_ec_team_members WHERE team_type = venue AND team_id = 55 ORDER BY id ASC FOR UPDATE",
					"SELECT {$columns} FROM wp_ec_team_members WHERE team_type = venue AND team_id = 55 AND user_id = 22 ORDER BY id ASC FOR UPDATE",
				),
				$GLOBALS['wpdb']->queries
			);
		} finally {
			$GLOBALS['wpdb'] = $original;
		}
	}

	/** Team membership storage is reachable only through its repository and schema. */
	public function test_membership_storage_boundary(): void {
		$owners = array( 'TeamMembershipRepository.php' );
		foreach ( array( dirname( __DIR__ ), dirname( __DIR__, 2 ) . '/extrachill-booking', dirname( __DIR__, 2 ) . '/extrachill-users' ) as $root ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
			foreach ( $files as $file ) {
				$path = $file->getPathname();
				if ( 'php' !== $file->getExtension() || str_contains( $path, '/tests/' ) || str_contains( $path, '/vendor/' ) ) {
					continue;
				}
				$source = file_get_contents( $path );
				$this->assertStringNotContainsString( 'memberships_table(', $source, $path );
				if ( ! in_array( $file->getFilename(), $owners, true ) && ! str_ends_with( $path, '/extrachill-users/inc/teams/db.php' ) ) {
					$this->assertDoesNotMatchRegularExpression( '/ec_(team|venue|promoter)_members/', $source, $path );
				}
			}
		}
	}

	/** Every statement is scoped to one supported team type, so venue and promoter rows never mix. */
	public function test_team_type_isolation(): void {
		$original = $GLOBALS['wpdb'];
		try {
			$GLOBALS['wpdb'] = new class() {
				public $base_prefix = 'wp_';
	public $prefix        = 'wp_7_';
				public $term_taxonomy = 'wp_7_term_taxonomy';
				public $last_error    = '';
				public $queries       = array();
				public $writes        = array();

				public function prepare( $query, ...$values ) {
					$values = 1 === count( $values ) && is_array( $values[0] ) ? $values[0] : $values;
					return vsprintf( str_replace( '%s', "'%s'", $query ), $values );
				}

				public function get_results( $query ) {
					$this->queries[] = $query;
					return array();
				}

				public function get_row( $query ) {
					$this->queries[] = $query;
					return null;
				}

				public function get_col( $query ) {
					$this->queries[] = $query;
					return array();
				}

				public function get_var( $query ) {
					$this->queries[] = $query;
					return null;
				}

				public function query( $query ) {
					$this->queries[] = $query;
					return 1;
				}

				public function insert( $table, $row ) {
					$this->writes[] = array( $table, $row );
					return 1;
				}

				public function update( $table, $data, $where ) {
					$this->writes[] = array( $table, $where );
					return 1;
				}
			};
			$repository      = new \ExtraChill\Users\Teams\TeamMembershipRepository();
			foreach ( array( 'venue', 'promoter' ) as $type ) {
				$GLOBALS['wpdb']->queries = array();
				$GLOBALS['wpdb']->writes  = array();
				$repository->get( $type, 55, 22 );
				$repository->lock_team( $type, 55, 10 );
				$repository->lock_member( $type, 55, 22 );
				$repository->select( $type, array( 'user_id' => 22 ), 'id ASC', null, true );
				$repository->select( $type, array( 'user_id' => 22, 'status' => 'active' ), 'id ASC', 10, false, 5 );
				$repository->select( $type, array( 'term' => 55, 'is_owner' => true ), 'id ASC', 10 );
				$repository->term_ids( $type, array( 'status' => 'active' ) );
				$repository->list_team( $type, 55, array( 'is_owner' => true ), 10, 0, 22 );
				'venue' === $type ? ( new \ExtraChillEvents\Core\VenueMembershipRepository() )->has_active_venue_for_user( 22 ) : ( new \ExtraChillEvents\Core\PromoterAuthorityRepository() )->has_active_organization_for_user( 22 );
				$repository->mutate( $type, 55, 22, 1, true, false, 9 );
				$repository->insert( $type, $repository->new_row( $type, 55, 22, false, 9 ) );
				$repository->update( $type, 55, 22, 1, array( 'status' => 'active' ) );
				$this->assertCount( 10, $GLOBALS['wpdb']->queries );
				foreach ( $GLOBALS['wpdb']->queries as $query ) {
					$this->assertStringContainsString( 'wp_ec_team_members', $query );
					$this->assertMatchesRegularExpression( "/(^|\\.| )team_type = '{$type}'/", $query );
				}
				$this->assertSame( array( 'wp_ec_team_members', $type, 55 ), array( $GLOBALS['wpdb']->writes[0][0], $GLOBALS['wpdb']->writes[0][1]['team_type'], $GLOBALS['wpdb']->writes[0][1]['team_id'] ) );
				$this->assertArrayNotHasKey( $type . '_term_id', $GLOBALS['wpdb']->writes[0][1] );
				$this->assertSame( array( 'team_type' => $type, 'team_id' => 55, 'user_id' => 22, 'version' => 1 ), $GLOBALS['wpdb']->writes[1][1] );
			}
			$this->expectException( InvalidArgumentException::class );
			$repository->lock_team( 'artist', 55 );
		} finally {
			$GLOBALS['wpdb'] = $original;
		}
	}

	/** Shared hydration preserves typed keys, status rules and nullable revokers. */
	public function test_shared_typed_hydration(): void {
		$repository = new \ExtraChill\Users\Teams\TeamMembershipRepository();
		$row        = array(
			'id'                 => '1',
			'venue_term_id'      => '55',
			'promoter_term_id'   => '55',
			'user_id'            => '22',
			'is_owner'           => '1',
			'status'             => 'active',
			'version'            => '3',
			'created_by_user_id' => '9',
			'revoked_at'         => '',
			'revoked_by_user_id' => '9',
		);
		foreach ( array( 'venue', 'promoter' ) as $type ) {
			$hydrated = $repository->hydrate_rows( $type, array( $row ) );
			$this->assertSame( 55, $hydrated[0][ $type . '_term_id' ] );
			$this->assertSame( 3, $hydrated[0]['version'] );
			$this->assertTrue( $hydrated[0]['is_owner'] );
			$this->assertNull( $hydrated[0]['revoked_at'] );
			$corrupt             = $row;
			$corrupt['is_owner'] = '2';
			$this->assertSame( $type . '_membership_corrupt_owner', $repository->hydrate_rows( $type, array( $row, $corrupt ) )->get_error_code() );
		}
		$this->assertSame( 9, $repository->hydrate( 'promoter', $row )['revoked_by_user_id'] );
		$row['status'] = 'invited';
		$this->assertSame( 'promoter_membership_corrupt_status', $repository->hydrate( 'promoter', $row )->get_error_code() );
	}

	/** Shared writes always bind the natural key and expected version. */
	public function test_shared_write_version_guard(): void {
		$original = $GLOBALS['wpdb'];
		try {
			$GLOBALS['wpdb'] = new class() {
				public $base_prefix = 'wp_';
	public $prefix = 'wp_7_';
				public $query;

				public function prepare( $query, $values ) {
					return vsprintf( str_replace( '%s', "'%s'", $query ), $values );
				}

				public function query( $query ) {
					$this->query = $query;
					return 0;
				}
			};
			$repository      = new \ExtraChill\Users\Teams\TeamMembershipRepository();
			foreach ( array( 'venue', 'promoter' ) as $type ) {
				$this->assertSame( 0, $repository->mutate( $type, 55, 22, 3, false, false, 9 ) );
				$this->assertStringContainsString( "WHERE team_type = '{$type}' AND team_id = 55 AND user_id = 22 AND version = 3", $GLOBALS['wpdb']->query );
				$this->assertStringContainsString( 'version = version + 1', $GLOBALS['wpdb']->query );
				$this->assertSame( 0, $repository->mutate( $type, 55, 22, 3, null, true, 9 ) );
				$this->assertStringContainsString( "status = 'revoked'", $GLOBALS['wpdb']->query );
				$this->assertSame( 'promoter' === $type, str_contains( $GLOBALS['wpdb']->query, 'revoked_by_user_id = 9' ) );
			}
		} finally {
			$GLOBALS['wpdb'] = $original;
		}
	}

	/** Empty membership ranges are successful locks, not database errors. */
	public function test_empty_lock_results(): void {
		$repository = new VenueMembershipRepository();
		$this->assertSame( array(), $repository->lock_for_venue( 99999 ) );
		$this->assertSame( array(), $repository->lock_member( 99999, 99999 ) );
	}
}
