<?php
/**
 * RSVP pass storage: issuance idempotency, revocation, and atomic redemption.
 *
 * Pure-PHP unit test (no WordPress test framework, no real database) —
 * matches the qualify-v2-unit convention (see tests/PromoterAuthorityTest.php):
 * a bespoke, regex-matched fake $wpdb tailored to this class's own known
 * query shapes, seeded and asserted against directly.
 *
 * True concurrent-race safety for redeem() is a MySQL row-locking guarantee
 * on `UPDATE ... WHERE status = 'active'` (a real database primitive this
 * fake cannot reproduce under genuine concurrency) — what IS verified here
 * is the decision logic on both sides of that race: a losing/second call
 * against an already-redeemed row returns the existing redemption record
 * rather than erroring or double-redeeming.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs the fake $wpdb double with its test case class.

use ExtraChillEvents\Core\RsvpPassesTable;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

require_once dirname( __DIR__, 3 ) . '/inc/Core/RsvpPassesTable.php';

/** Bespoke $wpdb fake matching RsvpPassesTable's exact query shapes. */
final class RsvpPassesTableFakeWpdb {

	public $prefix = 'wp_';

	public $insert_id = 0;

	/** @var array<string, array<int, array<string, mixed>>> */
	public $rows = array();

	private $next_id = 1;

	public function get_charset_collate() {
		return '';
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			static function ( $matched ) use ( &$args, &$i ) {
				$value = $args[ $i++ ];
				return '%d' === $matched[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function insert( $table, $data ) {
		$data['id']             = $this->next_id++;
		$this->rows[ $table ][] = $data;
		$this->insert_id        = $data['id'];
		return 1;
	}

	public function update( $table, $data, $where ) {
		$count = 0;
		foreach ( $this->rows[ $table ] ?? array() as $index => $row ) {
			$matches = true;
			foreach ( $where as $key => $value ) {
				if ( ( $row[ $key ] ?? null ) != $value ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Test double; loose comparison tolerates int/string id mismatches.
					$matches = false;
					break;
				}
			}
			if ( $matches ) {
				foreach ( $data as $key => $value ) {
					$row[ $key ] = $value;
				}
				$this->rows[ $table ][ $index ] = $row;
				++$count;
			}
		}
		return $count;
	}

	public function get_row( $query, $output = null ) {
		unset( $output );
		if ( preg_match( '/FROM (\S+) WHERE event_id = (\d+) AND user_id = (\d+)/', $query, $match ) ) {
			foreach ( $this->rows[ $match[1] ] ?? array() as $row ) {
				if ( (int) $row['event_id'] === (int) $match[2] && (int) $row['user_id'] === (int) $match[3] ) {
					return $row;
				}
			}
			return null;
		}
		if ( preg_match( "/FROM (\S+) WHERE code = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->rows[ $match[1] ] ?? array() as $row ) {
				if ( stripslashes( $match[2] ) === $row['code'] ) {
					return $row;
				}
			}
			return null;
		}
		return null;
	}

	public function get_results( $query, $output = null ) {
		unset( $output );
		if ( preg_match( '/FROM (\S+) WHERE event_id = (\d+) ORDER BY/', $query, $match ) ) {
			return array_values(
				array_filter(
					$this->rows[ $match[1] ] ?? array(),
					static function ( $row ) use ( $match ) {
						return (int) $row['event_id'] === (int) $match[2];
					}
				)
			);
		}
		return array();
	}

	public function query( $query ) {
		if ( 'START TRANSACTION' === $query || 'COMMIT' === $query || 'ROLLBACK' === $query ) {
			return 1;
		}
		if ( preg_match(
			"/UPDATE (\S+) SET status = '([^']+)', redeemed_at = '([^']+)', redeemed_by_user_id = (\d+), updated_at = '([^']+)' WHERE id = (\d+) AND status = '([^']+)'/",
			$query,
			$match
		) ) {
			$table = $match[1];
			foreach ( $this->rows[ $table ] ?? array() as $index => $row ) {
				if ( (int) $row['id'] === (int) $match[6] && $row['status'] === $match[7] ) {
					$row['status']                  = $match[2];
					$row['redeemed_at']             = $match[3];
					$row['redeemed_by_user_id']     = (int) $match[4];
					$row['updated_at']              = $match[5];
					$this->rows[ $table ][ $index ] = $row;
					return 1;
				}
			}
			return 0;
		}
		return false;
	}
}

final class RsvpPassesTableTest extends PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new RsvpPassesTableFakeWpdb();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_issue_creates_a_new_active_pass_with_an_unguessable_code(): void {
		$pass = RsvpPassesTable::issue_or_reactivate( 100, 7 );

		$this->assertNotNull( $pass );
		$this->assertSame( RsvpPassesTable::STATUS_ACTIVE, $pass['status'] );
		$this->assertMatchesRegularExpression(
			'/^[23456789A-HJ-NP-Z]{5}-[23456789A-HJ-NP-Z]{5}-[23456789A-HJ-NP-Z]{5}$/',
			$pass['code']
		);
		// Not derived from the event/user IDs.
		$this->assertStringNotContainsString( '100', $pass['code'] );
		$this->assertStringNotContainsString( '007', $pass['code'] );
	}

	public function test_issue_is_idempotent_when_already_active(): void {
		$first  = RsvpPassesTable::issue_or_reactivate( 100, 7 );
		$second = RsvpPassesTable::issue_or_reactivate( 100, 7 );

		$this->assertSame( $first['code'], $second['code'] );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows[ RsvpPassesTable::table_name() ] );
	}

	public function test_revoke_deactivates_an_active_pass(): void {
		RsvpPassesTable::issue_or_reactivate( 100, 7 );

		$revoked = RsvpPassesTable::revoke( 100, 7 );

		$this->assertTrue( $revoked );
		$pass = RsvpPassesTable::find_for_user_event( 100, 7 );
		$this->assertSame( RsvpPassesTable::STATUS_REVOKED, $pass['status'] );
	}

	public function test_revoke_is_a_no_op_against_an_already_revoked_pass(): void {
		RsvpPassesTable::issue_or_reactivate( 100, 7 );
		RsvpPassesTable::revoke( 100, 7 );

		$this->assertFalse( RsvpPassesTable::revoke( 100, 7 ) );
	}

	public function test_reactivating_a_revoked_pass_issues_a_fresh_code(): void {
		$original = RsvpPassesTable::issue_or_reactivate( 100, 7 );
		RsvpPassesTable::revoke( 100, 7 );

		$reactivated = RsvpPassesTable::issue_or_reactivate( 100, 7 );

		$this->assertSame( RsvpPassesTable::STATUS_ACTIVE, $reactivated['status'] );
		$this->assertNotSame( $original['code'], $reactivated['code'] );
		$this->assertNull( $reactivated['revoked_at'] );
		// Still the same logical row — reactivation, not a duplicate.
		$this->assertCount( 1, $GLOBALS['wpdb']->rows[ RsvpPassesTable::table_name() ] );
	}

	public function test_revoke_never_erases_an_existing_redemption(): void {
		RsvpPassesTable::issue_or_reactivate( 100, 7 );
		RsvpPassesTable::redeem( 100, 7, 3 );

		$revoked = RsvpPassesTable::revoke( 100, 7 );

		$this->assertFalse( $revoked );
		$pass = RsvpPassesTable::find_for_user_event( 100, 7 );
		$this->assertSame( RsvpPassesTable::STATUS_REDEEMED, $pass['status'] );
		$this->assertSame( 3, (int) $pass['redeemed_by_user_id'] );
	}

	public function test_redeem_marks_the_pass_redeemed_and_records_the_host(): void {
		RsvpPassesTable::issue_or_reactivate( 100, 7 );

		$result = RsvpPassesTable::redeem( 100, 7, 3 );

		$this->assertFalse( $result['already_redeemed'] );
		$this->assertSame( 3, $result['redeemed_by_user_id'] );
		$pass = RsvpPassesTable::find_for_user_event( 100, 7 );
		$this->assertSame( RsvpPassesTable::STATUS_REDEEMED, $pass['status'] );
	}

	public function test_redeem_is_idempotent_against_a_double_tap(): void {
		RsvpPassesTable::issue_or_reactivate( 100, 7 );
		$first = RsvpPassesTable::redeem( 100, 7, 3 );

		// A second host taps Redeem on the same pass (or the same host
		// double-taps): the pass is already redeemed, so this must report
		// the existing redemption rather than erroring or re-redeeming.
		$second = RsvpPassesTable::redeem( 100, 7, 9 );

		$this->assertFalse( $first['already_redeemed'] );
		$this->assertTrue( $second['already_redeemed'] );
		$this->assertSame( $first['redeemed_by_user_id'], $second['redeemed_by_user_id'] );
	}

	public function test_redeem_rejects_a_revoked_pass(): void {
		RsvpPassesTable::issue_or_reactivate( 100, 7 );
		RsvpPassesTable::revoke( 100, 7 );

		$result = RsvpPassesTable::redeem( 100, 7, 3 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rsvp_pass_revoked', $result->get_error_code() );
	}

	public function test_redeem_rejects_a_nonexistent_pass(): void {
		$result = RsvpPassesTable::redeem( 100, 7, 3 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rsvp_pass_not_found', $result->get_error_code() );
	}

	public function test_list_for_event_is_keyed_by_user_id(): void {
		RsvpPassesTable::issue_or_reactivate( 100, 7 );
		RsvpPassesTable::issue_or_reactivate( 100, 9 );
		RsvpPassesTable::issue_or_reactivate( 200, 7 ); // Different event; must not leak in.

		$list = RsvpPassesTable::list_for_event( 100 );

		$this->assertCount( 2, $list );
		$this->assertArrayHasKey( 7, $list );
		$this->assertArrayHasKey( 9, $list );
	}
}
