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
				public $prefix = 'wp_7_';
				public $last_error = '';
				public $queries = array();

				public function prepare( $query, ...$values ) {
					return vsprintf( $query, $values );
				}

				public function get_results( $query, $format ) {
					$this->queries[] = $query;
					$this->last_error = 'lock timeout';
					return null;
				}
			};
			$repository = new VenueMembershipRepository();
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
				if ( 'php' !== $file->getExtension() || str_contains( $path, '/tests/' ) || in_array( $file->getFilename(), array( 'VenueSchema.php', 'VenueMembershipRepository.php' ), true ) ) {
					continue;
				}
				$this->assertDoesNotMatchRegularExpression( '/(?<![A-Za-z])VenueSchema::memberships_table\\s*\\(/', file_get_contents( $path ), $path );
				if ( ! in_array( $file->getFilename(), array( 'PromoterAuthorityRepository.php', 'PromoterAuthoritySchema.php' ), true ) ) {
					$this->assertStringNotContainsString( 'memberships_table()', file_get_contents( $path ), $path );
				}
			}
		}
	}

	/** Empty membership ranges are successful locks, not database errors. */
	public function test_empty_lock_results(): void {
		$repository = new VenueMembershipRepository();
		$this->assertSame( array(), $repository->lock_for_venue( 99999 ) );
		$this->assertSame( array(), $repository->lock_member( 99999, 99999 ) );
	}
}
