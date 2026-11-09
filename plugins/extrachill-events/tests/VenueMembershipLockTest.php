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
				public $prefix     = 'wp_7_';
				public $last_error = '';
				public $queries    = array();

				public function prepare( $query, ...$values ) {
					return vsprintf( $query, $values );
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
			$this->assertSame( array( 'SELECT * FROM wp_7_ec_venue_members WHERE venue_term_id = 55 ORDER BY id ASC FOR UPDATE', 'SELECT * FROM wp_7_ec_venue_members WHERE venue_term_id = 55 AND user_id = 22 FOR UPDATE' ), $GLOBALS['wpdb']->queries );
		} finally {
			$GLOBALS['wpdb'] = $original;
		}
	}

	/** Venue membership table access stays behind its persistence boundary. */
	public function test_membership_storage_boundary(): void {
		foreach ( array( dirname( __DIR__ ), dirname( __DIR__, 2 ) . '/extrachill-booking' ) as $root ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
			foreach ( $files as $file ) {
				$path = $file->getPathname();
				if ( 'php' !== $file->getExtension() || str_contains( $path, '/tests/' ) || in_array( $file->getFilename(), array( 'VenueSchema.php', 'VenueMembershipRepository.php', 'TeamMembershipRepository.php' ), true ) ) {
					continue;
				}
				$this->assertDoesNotMatchRegularExpression( '/(?<![A-Za-z])VenueSchema::memberships_table\\s*\\(/', file_get_contents( $path ), $path );
				if ( ! in_array( $file->getFilename(), array( 'PromoterAuthorityRepository.php', 'PromoterAuthoritySchema.php' ), true ) ) {
					$this->assertStringNotContainsString( 'memberships_table()', file_get_contents( $path ), $path );
				}
			}
		}
	}

	/** Shared hydration preserves typed keys, status rules and nullable revokers. */
	public function test_shared_typed_hydration(): void {
		$repository = new \ExtraChillEvents\Core\TeamMembershipRepository();
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
			$repository      = new \ExtraChillEvents\Core\TeamMembershipRepository();
			foreach ( array( 'venue', 'promoter' ) as $type ) {
				$this->assertSame( 0, $repository->mutate( $type, 55, 22, 3, false, false, 9 ) );
				$this->assertStringContainsString( "WHERE {$type}_term_id = 55 AND user_id = 22 AND version = 3", $GLOBALS['wpdb']->query );
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
