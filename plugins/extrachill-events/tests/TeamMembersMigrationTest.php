<?php
/**
 * Team membership table merge and legacy-table retirement tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.OneDeclarationPerFile.MultipleFound, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Standalone harness stubs the Data Machine migration contract.

namespace DataMachine\Core\Database\Migrations {
	if ( ! class_exists( Migration::class ) ) {
		/** Minimal Data Machine migration contract for standalone tests. */
		abstract class Migration {
			abstract public function id(): string;
			abstract public function description(): string;
			abstract public function inspect(): array;
			abstract public function apply( int $limit ): array;
			abstract public function isComplete(): bool;
		}
	}
}

namespace {
	use ExtraChillEvents\Core\TeamMembersMigration;
	use ExtraChillEvents\Core\TeamMembershipRepository;
	use ExtraChillEvents\Core\VenueSchema;
	use PHPUnit\Framework\TestCase;

	require_once dirname( __DIR__ ) . '/inc/Core/TeamMembersMigration.php';

	/** In-memory database with the two legacy tables and the team table. */
	class TeamMembersMigrationWpdb {
		public $prefix     = 'wp_7_';
		public $last_error = '';
		public $tables     = array();

		public function prepare( $query, ...$args ) {
			$args = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
			$i    = 0;
			return preg_replace_callback(
				'/%[ds]/',
				static function ( $matched ) use ( $args, &$i ) {
					$value = $args[ $i++ ];
					return '%d' === $matched[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
				},
				$query
			);
		}

		public function get_var( $query ) {
			if ( preg_match( "/^SHOW TABLES LIKE '(\w+)'$/", $query, $match ) ) {
				return isset( $this->tables[ $match[1] ] ) ? $match[1] : null;
			}
			if ( preg_match( '/^SELECT COUNT\(\*\) FROM (\w+)$/', $query, $match ) ) {
				return (string) count( $this->tables[ $match[1] ] );
			}
			if ( preg_match( "/^SELECT COUNT\(\*\) FROM (\w+) AS legacy LEFT JOIN (\w+) AS team ON team.team_type = '(\w+)' AND team.team_term_id = legacy.(\w+) AND/", $query, $match ) ) {
				return (string) count( array_filter( $this->tables[ $match[1] ], fn( $row ) => ! $this->in_team( $match[3], (int) $row[ $match[4] ], (int) $row['user_id'] ) ) );
			}
			throw new RuntimeException( 'Unexpected get_var: ' . $query );
		}

		public function query( $query ) {
			if ( preg_match( '/^DROP TABLE IF EXISTS `(\w+)`$/', $query, $match ) ) {
				unset( $this->tables[ $match[1] ] );
				return 1;
			}
			if ( preg_match( "/^INSERT IGNORE INTO (\w+) \(.*\) SELECT '(\w+)', legacy.(\w+), .* legacy.version, legacy.created_by_user_id, legacy.created_at, legacy.updated_at, (legacy.revoked_by_user_id|NULL), legacy.revoked_at FROM (\w+) AS legacy$/", $query, $match ) ) {
				$copied = 0;
				foreach ( $this->tables[ $match[5] ] as $row ) {
					if ( $this->in_team( $match[2], (int) $row[ $match[3] ], (int) $row['user_id'] ) ) {
						continue;
					}
					$team_row = array(
						'team_type'          => $match[2],
						'team_term_id'       => (int) $row[ $match[3] ],
						'revoked_by_user_id' => 'NULL' === $match[4] ? null : $row['revoked_by_user_id'],
					) + array_diff_key( $row, array( $match[3] => true ) );
					$this->tables[ $match[1] ][] = $team_row;
					++$copied;
				}
				return $copied;
			}
			throw new RuntimeException( 'Unexpected query: ' . $query );
		}

		private function in_team( string $type, int $term_id, int $user_id ): bool {
			foreach ( $this->tables['wp_7_ec_team_members'] ?? array() as $row ) {
				if ( $type === $row['team_type'] && $term_id === (int) $row['team_term_id'] && $user_id === (int) $row['user_id'] ) {
					return true;
				}
			}
			return false;
		}
	}

	/** The team table adopts both legacy tables and the migration retires them safely. */
	final class TeamMembersMigrationTest extends TestCase {
		private $original_wpdb;
		private $original_state;

		protected function setUp(): void {
			$this->original_wpdb  = $GLOBALS['wpdb'] ?? null;
			$this->original_state = $GLOBALS['venue_membership_test'] ?? null;
			$GLOBALS['venue_membership_test']['options'][ VenueSchema::VERSION_OPTION ] = VenueSchema::SCHEMA_VERSION;

			$member          = array(
				'user_id'            => 22,
				'is_owner'           => 1,
				'status'             => 'active',
				'version'            => 2,
				'created_by_user_id' => 1,
				'created_at'         => '2026-01-01 00:00:00',
				'updated_at'         => '2026-01-02 00:00:00',
				'revoked_at'         => null,
			);
			$GLOBALS['wpdb'] = new TeamMembersMigrationWpdb();
			$GLOBALS['wpdb']->tables = array(
				'wp_7_ec_team_members'     => array(),
				'wp_7_ec_venue_members'    => array( array( 'id' => 1, 'venue_term_id' => 55 ) + $member, array( 'id' => 2, 'venue_term_id' => 55, 'user_id' => 23 ) + $member ),
				// Same term ID and user as a venue member: the team type keeps them distinct.
				'wp_7_ec_promoter_members' => array( array( 'id' => 1, 'promoter_term_id' => 55, 'revoked_by_user_id' => 9 ) + $member ),
			);
		}

		protected function tearDown(): void {
			$GLOBALS['wpdb'] = $this->original_wpdb;
			if ( null === $this->original_state ) {
				unset( $GLOBALS['venue_membership_test'] );
			} else {
				$GLOBALS['venue_membership_test'] = $this->original_state;
			}
		}

		/** Both legacy tables land in one table, keyed by team type, and repeat copies are no-ops. */
		public function test_adoption_copies_every_team_type_once(): void {
			$status = VenueSchema::adopt_legacy_memberships();
			$this->assertSame(
				array(
					'wp_7_ec_venue_members'    => array( 'exists' => true, 'rows' => 2, 'missing' => 0 ),
					'wp_7_ec_promoter_members' => array( 'exists' => true, 'rows' => 1, 'missing' => 0 ),
				),
				$status
			);
			$this->assertSame( $status, VenueSchema::adopt_legacy_memberships() );

			$team = $GLOBALS['wpdb']->tables[ TeamMembershipRepository::table() ];
			$this->assertSame( array( 'venue', 'venue', 'promoter' ), array_column( $team, 'team_type' ) );
			$this->assertSame( array( 55, 55, 55 ), array_column( $team, 'team_term_id' ) );
			$this->assertSame( array( null, null, 9 ), array_column( $team, 'revoked_by_user_id' ) );
			$this->assertSame( array( 2, 2, 2 ), array_column( $team, 'version' ) );
		}

		/** Inspection reports without writing; apply copies, verifies and drops; reruns are no-ops. */
		public function test_migration_drops_legacy_tables_only_after_full_copy(): void {
			$migration = new TeamMembersMigration();
			$this->assertSame( 'extrachill-events.team-members', $migration->id() );

			$inspect = $migration->inspect();
			$this->assertSame( 'pending', $inspect['status'] );
			$this->assertSame( 2, $inspect['tables']['wp_7_ec_venue_members']['missing'] );
			$this->assertSame( array(), $GLOBALS['wpdb']->tables['wp_7_ec_team_members'] );
			$this->assertFalse( $migration->isComplete() );

			$result = $migration->apply( 250 );
			$this->assertTrue( $result['success'] );
			$this->assertSame( array( 'wp_7_ec_venue_members', 'wp_7_ec_promoter_members' ), $result['dropped'] );
			$this->assertSame( array( 'wp_7_ec_team_members' ), array_keys( $GLOBALS['wpdb']->tables ) );
			$this->assertCount( 3, $GLOBALS['wpdb']->tables['wp_7_ec_team_members'] );
			$this->assertTrue( $migration->isComplete() );
			$this->assertSame( 'complete', $migration->inspect()['status'] );
			$this->assertTrue( $migration->apply( 250 )['success'] );
		}

		/** A row that cannot be copied keeps every legacy table in place. */
		public function test_migration_refuses_to_drop_with_uncopied_rows(): void {
			$GLOBALS['wpdb'] = new class() extends TeamMembersMigrationWpdb {
				public function query( $query ) {
					return 0 === strpos( $query, 'INSERT IGNORE' ) ? 0 : parent::query( $query );
				}
			};
			$GLOBALS['wpdb']->tables = array(
				'wp_7_ec_team_members'  => array(),
				'wp_7_ec_venue_members' => array( array( 'id' => 1, 'venue_term_id' => 55, 'user_id' => 22 ) ),
			);
			$result = ( new TeamMembersMigration() )->apply( 250 );
			$this->assertFalse( $result['success'] );
			$this->assertStringContainsString( '1 row(s) from wp_7_ec_venue_members', $result['error'] );
			$this->assertArrayHasKey( 'wp_7_ec_venue_members', $GLOBALS['wpdb']->tables );
		}

		/** The migration waits for the team table instead of touching legacy data. */
		public function test_migration_is_blocked_until_the_team_table_is_installed(): void {
			$GLOBALS['venue_membership_test']['options'][ VenueSchema::VERSION_OPTION ] = '2';
			$migration = new TeamMembersMigration();
			$this->assertSame( 'blocked', $migration->inspect()['status'] );
			$this->assertFalse( $migration->apply( 250 )['success'] );
			$this->assertCount( 3, $GLOBALS['wpdb']->tables );
		}
	}
}
