<?php
/**
 * A venue's or promoter's permanent owner's guest list (extrachill#1130).
 *
 * Covers: only the venue's team, the promoter's members, and admins manage
 * a list (outsiders and the other owner's team are refused); one durable
 * pass checks in once per event and again at the next event; it works at
 * any published show tagged with the owner and nowhere else ("Not on the
 * list"); removing someone kills the pass at once; owner check-ins never
 * touch the event's guest list spots; the door list carries the people
 * labelled "Venue list" / "Promoter list"; and the /rsvp-verify/ scan view
 * stays non-enumerating.
 *
 * Since extrachill#1521 each person is an owner pass in the shared passes
 * table (event_id 0 + owner_type/owner_term_id) with per-show check-ins in
 * the pass check-ins table: one code namespace with per-event passes,
 * which must keep working exactly as before.
 *
 * Reuses the shims loaded by RsvpGuestListRequestsTest.php and the
 * membership row shapes from EventManagementAuthorityTest.php.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP function shims, a $wpdb double, and the test case class.

use ExtraChillEvents\Abilities\OwnerGuestListAbilities;
use ExtraChillEvents\Abilities\RsvpPassAbilities;
use ExtraChillEvents\Core\RsvpPassesTable;

require_once __DIR__ . '/../../Support/team-members-fake.php';
require_once __DIR__ . '/RsvpGuestListRequestsTest.php';

if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy = '' ) {
		$term = $GLOBALS['owner_list_test']['terms'][ $taxonomy ][ (int) $term_id ] ?? null;
		return $term ? $term : null;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) ( $GLOBALS['owner_list_test']['current_user'] ?? 0 );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://events.extrachill.com' . $path;
	}
}

require_once __DIR__ . '/../../../../extrachill-users/inc/teams/TeamMembershipRepository.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/PromoterAuthorityRepository.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/TeamAuthorization.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/VenueMembershipRepository.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/owner-guest-list.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/RsvpPassAbilities.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/OwnerGuestListAbilities.php';

/** Stateful $wpdb for the passes and pass check-ins tables, and membership lookups. */
final class OwnerGuestListFakeWpdb {

	public $base_prefix = 'wp_';
	public $prefix     = 'wp_7_';
	public $last_error = '';
	public $insert_id  = 0;

	/** @var array<string, array<int, array<string, mixed>>> Rows by table name. */
	public $tables = array();

	private $next_id = 1;

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

	/** @return array<int, array<string, mixed>> */
	public function rows( string $table ): array {
		return $this->tables[ $table ] ?? array();
	}

	private function table_in( string $sql ): string {
		return preg_match( '/FROM (\S+)/', $sql, $m ) ? $m[1] : '';
	}

	/**
	 * Rows matching a WHERE clause of `col = value` / `col IS NULL` terms
	 * joined by AND, with top-level OR between groups.
	 *
	 * @param string $sql Query.
	 * @return array<int, array<string, mixed>>
	 */
	private function matching( string $sql ): array {
		$rows = $this->rows( $this->table_in( $sql ) );
		if ( ! preg_match( '/WHERE (.+?)(?: ORDER BY| LIMIT|$)/', $sql, $where ) ) {
			return $rows;
		}
		$groups = array();
		foreach ( explode( ' OR ', $where[1] ) as $group ) {
			preg_match_all( "/(\w+) = ('([^']*)'|\d+)/", $group, $pairs, PREG_SET_ORDER );
			preg_match_all( '/(\w+) IS NULL/', $group, $nulls );
			$groups[] = array( $pairs, $nulls[1] );
		}
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $groups ) {
					foreach ( $groups as $group ) {
						foreach ( $group[0] as $pair ) {
							$expected = "'" === $pair[2][0] ? $pair[3] : $pair[2];
							if ( (string) ( $row[ $pair[1] ] ?? '' ) !== (string) $expected ) {
								continue 2;
							}
						}
						foreach ( $group[1] as $column ) {
							if ( null !== ( $row[ $column ] ?? null ) ) {
								continue 2;
							}
						}
						return true;
					}
					return false;
				}
			)
		);
	}

	public function insert( $table, $data ) {

		list( $table, $data ) = ec_team_fake_write( $table, $data );
		if ( false !== strpos( $table, 'ec_event_pass_checkins' ) ) {
			foreach ( $this->rows( $table ) as $row ) {
				if ( (int) $row['pass_id'] === (int) $data['pass_id'] && (int) $row['event_id'] === (int) $data['event_id'] ) {
					return false; // Unique (pass_id, event_id).
				}
			}
		}
		$data['id']               = $this->next_id++;
		$this->insert_id          = $data['id'];
		$this->tables[ $table ][] = $data;
		return 1;
	}

	public function update( $table, $data, $where ) {

		list( $table, $data, $where ) = ec_team_fake_write( $table, $data, $where );
		$count = 0;
		foreach ( $this->rows( $table ) as $index => $row ) {
			foreach ( $where as $key => $value ) {
				if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
					continue 2;
				}
			}
			$this->tables[ $table ][ $index ] = array_merge( $row, $data );
			++$count;
		}
		return $count;
	}

	public function get_row( $query, $output = null ) {

		$query = ec_team_fake_sql( $query );
		unset( $output );
		$this->last_error = '';
		if ( preg_match( '/SUM\(/', $query ) ) {
			$claimed    = 0;
			$checked_in = 0;
			foreach ( $this->matching( $query ) as $row ) {
				$claimed    += in_array( $row['status'], array( 'active', 'redeemed' ), true ) ? 1 : 0;
				$checked_in += 'redeemed' === $row['status'] ? 1 : 0;
			}
			return array(
				'claimed'    => (string) $claimed,
				'checked_in' => (string) $checked_in,
			);
		}
		$rows = $this->matching( $query );
		return $rows ? $rows[0] : null;
	}

	public function get_results( $query, $output = null ) {

		$query = ec_team_fake_sql( $query );
		unset( $output );
		$this->last_error = '';
		return $this->matching( $query );
	}

	public function get_col( $query ) {

		$query = ec_team_fake_sql( $query );
		$this->last_error = '';
		$column           = preg_match( '/SELECT (\w+) FROM/', $query, $m ) ? $m[1] : '';
		return array_column( $this->matching( $query ), $column );
	}

	public function get_var( $query ) {

		$query = ec_team_fake_sql( $query );
		unset( $query );
		return '1';
	}

	/** Applies the per-event redeem compare-and-set (`UPDATE t SET ... WHERE ...`). */
	public function query( $query ) {

		$query = ec_team_fake_sql( $query );
		if ( ! preg_match( '/^UPDATE (\S+) SET (.+) WHERE (.+)$/', $query, $m ) ) {
			return 0;
		}
		preg_match_all( "/(\w+) = ('([^']*)'|\d+)/", $m[2], $sets, PREG_SET_ORDER );
		$data = array();
		foreach ( $sets as $set ) {
			$data[ $set[1] ] = "'" === $set[2][0] ? $set[3] : $set[2];
		}
		$count = 0;
		foreach ( $this->matching( "SELECT * FROM {$m[1]} WHERE {$m[3]}" ) as $match ) {
			$count += $this->update( $m[1], $data, array( 'id' => $match['id'] ) );
		}
		return $count;
	}
}

final class OwnerGuestListTest extends PHPUnit\Framework\TestCase {

	private const VENUE_ID       = 601;
	private const OTHER_VENUE_ID = 602;
	private const PROMOTER_ID    = 501;

	private const VENUE_SHOW    = 700001; // Lo-Fi Brewing, no promoter.
	private const VENUE_SHOW_2  = 700002; // Lo-Fi Brewing, next week.
	private const PROMOTER_SHOW = 700003; // Promoter show at the other venue.
	private const OTHER_SHOW    = 700004; // Other venue, no promoter.
	private const DRAFT_SHOW    = 700005; // Lo-Fi Brewing, unpublished.

	private const ADMIN_ID         = 1;
	private const VENUE_MEMBER     = 21;
	private const PROMOTER_MEMBER  = 22;
	private const OTHER_VENUE_TEAM = 23;
	private const OUTSIDER         = 99;

	private OwnerGuestListFakeWpdb $db;

	/** @var mixed */
	private $original_wpdb;

	/** @var array */
	private $original_management;

	protected function setUp(): void {
		$this->original_wpdb       = $GLOBALS['wpdb'] ?? null;
		$this->original_management = $GLOBALS['event_management_test'] ?? array();
		$this->db                  = new OwnerGuestListFakeWpdb();
		$GLOBALS['wpdb']           = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated test double.

		$venue    = array(
			(object) array(
				'term_id' => self::VENUE_ID,
				'name'    => 'Lo-Fi Brewing',
			),
		);
		$other    = array(
			(object) array(
				'term_id' => self::OTHER_VENUE_ID,
				'name'    => 'The Royal American',
			),
		);
		$promoter = array(
			(object) array(
				'term_id' => self::PROMOTER_ID,
				'name'    => 'Holy City Shows',
			),
		);

		$GLOBALS['owner_list_test'] = array(
			'current_user' => 0,
			'terms'        => array(
				'venue'    => array(
					self::VENUE_ID       => $venue[0],
					self::OTHER_VENUE_ID => $other[0],
				),
				'promoter' => array( self::PROMOTER_ID => $promoter[0] ),
			),
		);

		$posts = array();
		foreach ( array( self::VENUE_SHOW, self::VENUE_SHOW_2, self::PROMOTER_SHOW, self::OTHER_SHOW, self::DRAFT_SHOW ) as $id ) {
			$posts[ $id ] = (object) array(
				'ID'          => $id,
				'post_title'  => 'Show ' . $id,
				'post_type'   => 'data_machine_events',
				'post_status' => self::DRAFT_SHOW === $id ? 'draft' : 'publish',
				'post_author' => self::ADMIN_ID,
			);
		}

		$GLOBALS['event_management_test'] = array(
			'options'        => array(

				'time_format'                           => 'g:i a',
			),
			'caps'           => array( self::ADMIN_ID => array( 'manage_network_options' ) ),
			'editable_posts' => array(),
			'posts'          => $posts,
			'terms'          => array(
				self::VENUE_SHOW    => array( 'venue' => $venue ),
				self::VENUE_SHOW_2  => array( 'venue' => $venue ),
				self::DRAFT_SHOW    => array( 'venue' => $venue ),
				self::PROMOTER_SHOW => array(
					'venue'    => $other,
					'promoter' => $promoter,
				),
				self::OTHER_SHOW    => array( 'venue' => $other ),
			),
		);

		$this->seed_venue_member( self::VENUE_MEMBER, self::VENUE_ID );
		$this->seed_venue_member( self::OTHER_VENUE_TEAM, self::OTHER_VENUE_ID );
		$this->db->tables[ ec_team_fake_partition( 'promoter' ) ][] = array(
			'id'                 => 1,
			'promoter_term_id'   => self::PROMOTER_ID,
			'user_id'            => self::PROMOTER_MEMBER,
			'is_owner'           => 0,
			'status'             => 'active',
			'version'            => 1,
			'created_by_user_id' => 1,
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-01 00:00:00',
			'revoked_by_user_id' => null,
			'revoked_at'         => null,
		);

		$GLOBALS['rsvp_pass_mail_test'] = array(
			'sent'   => array(),
			'result' => array( 'success' => true ),
			'users'  => array(),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']                  = $this->original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the runtime database.
		$GLOBALS['event_management_test'] = $this->original_management;
		unset( $GLOBALS['owner_list_test'], $GLOBALS['test_current_user_id'], $GLOBALS['rsvp_pass_mail_test'], $GLOBALS['ec_test_filters']['extrachill_events_owner_list_door_event_ids'] );
		unset( $GLOBALS['event_perk_test']['meta'][ self::VENUE_SHOW ] );
	}

	private function seed_venue_member( int $user_id, int $venue_id ): void {
		$this->db->tables[ ec_team_fake_partition( 'venue' ) ][] = array(
			'id'                 => $user_id,
			'venue_term_id'      => $venue_id,
			'user_id'            => $user_id,
			'is_owner'           => 0,
			'status'             => 'active',
			'created_by_user_id' => 1,
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-01 00:00:00',
			'version'            => 1,
		);
	}

	private function as_user( int $user_id ): void {
		$GLOBALS['owner_list_test']['current_user'] = $user_id;
		// In qualify-v2-unit, Stubs/wp-stubs.php's get_current_user_id() loads first.
		$GLOBALS['test_current_user_id'] = $user_id;
	}

	/** Tonight's shows per owner, as the door-window query would find them. */
	private function tonight( array $by_owner ): void {
		add_filter(
			'extrachill_events_owner_list_door_event_ids',
			static function ( $pre, $owner_type, $term_id ) use ( $by_owner ) {
				unset( $pre );
				return $by_owner[ $owner_type . ':' . $term_id ] ?? array();
			},
			10,
			3
		);
	}

	private function add_to_venue_list( string $name = 'Sam the Engineer', string $email = '' ): array {
		$result = extrachill_events_add_owner_list_name( 'venue', self::VENUE_ID, $name, $email, self::VENUE_MEMBER );
		$this->assertIsArray( $result );
		return $result;
	}

	private function redeem( int $event_id, string $code ) {
		return ( new RsvpPassAbilities() )->redeem_event_pass(
			array(
				'event_id' => $event_id,
				'code'     => $code,
			)
		);
	}

	public function test_only_the_owning_team_and_admins_manage_a_list(): void {
		$abilities = new OwnerGuestListAbilities();
		$venue     = array(
			'owner_type' => 'venue',
			'owner_id'   => self::VENUE_ID,
		);
		$promoter  = array(
			'owner_type' => 'promoter',
			'owner_id'   => self::PROMOTER_ID,
		);

		$this->as_user( self::VENUE_MEMBER );
		$this->assertTrue( $abilities->can_manage_owner_list( $venue ), 'Venue team manages the venue list.' );
		$this->assertInstanceOf( WP_Error::class, $abilities->can_manage_owner_list( $promoter ), 'Venue team cannot touch a promoter list.' );

		$this->as_user( self::PROMOTER_MEMBER );
		$this->assertTrue( $abilities->can_manage_owner_list( $promoter ), 'Promoter members manage the promoter list.' );
		$this->assertInstanceOf( WP_Error::class, $abilities->can_manage_owner_list( $venue ), 'Promoter members cannot touch a venue list.' );

		$this->as_user( self::OTHER_VENUE_TEAM );
		$this->assertInstanceOf( WP_Error::class, $abilities->can_manage_owner_list( $venue ), 'Another venue\'s team is refused.' );

		$this->as_user( self::OUTSIDER );
		$denied = $abilities->can_manage_owner_list( $venue );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'owner_list_forbidden', $denied->get_error_code() );

		$this->as_user( 0 );
		$this->assertInstanceOf( WP_Error::class, $abilities->can_manage_owner_list( $venue ), 'Logged out is refused.' );

		$this->as_user( self::ADMIN_ID );
		$this->assertTrue( $abilities->can_manage_owner_list( $venue ) );
		$this->assertTrue( $abilities->can_manage_owner_list( $promoter ) );
	}

	public function test_add_lists_and_remove_through_the_abilities(): void {
		$this->as_user( self::VENUE_MEMBER );
		$abilities = new OwnerGuestListAbilities();

		$added = $abilities->add_member(
			array(
				'owner_type' => 'venue',
				'owner_id'   => self::VENUE_ID,
				'name'       => '  Sam the Engineer ',
				'email'      => 'sam@example.com',
			)
		);
		$this->assertSame( 'Sam the Engineer', $added['name'] );
		$this->assertSame( 'Lo-Fi Brewing', $added['owner_name'] );
		$this->assertSame( 'Venue list', $added['label'] );
		$this->assertMatchesRegularExpression( '/^[A-Z2-9]{5}(-[A-Z2-9]{5}){2}$/', $added['code'] );
		$this->assertStringContainsString( '/owner-pass?code=' . $added['code'], $added['pass_url'] );
		$this->assertTrue( $added['emailed'] );
		$this->assertSame( array( 'sam@example.com' ), array_column( $GLOBALS['rsvp_pass_mail_test']['sent'], 'to' ) );
		$this->assertStringContainsString( $added['code'], $GLOBALS['rsvp_pass_mail_test']['sent'][0]['context']['body_html'] );
		$this->assertSame( $added['pass_url'], $GLOBALS['rsvp_pass_mail_test']['sent'][0]['context']['cta_url'] );

		$abilities->add_member(
			array(
				'owner_type' => 'venue',
				'owner_id'   => self::VENUE_ID,
				'name'       => 'Rae',
			)
		);
		$this->assertCount( 1, $GLOBALS['rsvp_pass_mail_test']['sent'], 'No email given, no email sent.' );

		$listed = $abilities->list_members(
			array(
				'owner_type' => 'venue',
				'owner_id'   => self::VENUE_ID,
			)
		);
		$this->assertSame( array( 'Sam the Engineer', 'Rae' ), array_column( $listed['members'], 'name' ) );

		$removed = $abilities->remove_member(
			array(
				'owner_type' => 'venue',
				'owner_id'   => self::VENUE_ID,
				'member_id'  => $added['member_id'],
			)
		);
		$this->assertTrue( $removed['removed'] );
		$listed = extrachill_events_list_owner_list( 'venue', self::VENUE_ID );
		$this->assertSame( array( 'Rae' ), array_column( $listed['members'], 'name' ) );

		// The id must belong to the list named: a promoter cannot remove a venue's guest by id.
		$rae = $listed['members'][0]['member_id'];
		$this->assertInstanceOf( WP_Error::class, extrachill_events_remove_owner_list_name( 'promoter', self::PROMOTER_ID, $rae ) );
		$this->assertCount( 1, extrachill_events_list_owner_list( 'venue', self::VENUE_ID )['members'] );
	}

	public function test_add_rejects_bad_input(): void {
		$this->assertSame( 'guest_name_required', extrachill_events_add_owner_list_name( 'venue', self::VENUE_ID, '  ' )->get_error_code() );
		$this->assertSame( 'invalid_email', extrachill_events_add_owner_list_name( 'venue', self::VENUE_ID, 'Sam', 'not-an-email' )->get_error_code() );
		$this->assertSame( 'owner_list_invalid_owner', extrachill_events_add_owner_list_name( 'artist', self::VENUE_ID, 'Sam' )->get_error_code() );
		$this->assertSame( 'owner_list_owner_not_found', extrachill_events_add_owner_list_name( 'venue', 424242, 'Sam' )->get_error_code() );
		$this->assertSame( array(), $this->db->rows( RsvpPassesTable::table_name() ) );
	}

	public function test_one_pass_checks_in_once_per_event_across_events(): void {
		$code = $this->add_to_venue_list()['code'];
		$this->as_user( self::VENUE_MEMBER );

		$first = $this->redeem( self::VENUE_SHOW, $code );
		$this->assertFalse( $first['already_redeemed'] );
		$this->assertSame( 'Sam the Engineer', $first['name'] );
		$this->assertSame( 'Venue list', $first['label'] );

		$again = $this->redeem( self::VENUE_SHOW, $code );
		$this->assertTrue( $again['already_redeemed'], 'A second scan at the same show is the existing check-in.' );

		$next_week = $this->redeem( self::VENUE_SHOW_2, $code );
		$this->assertFalse( $next_week['already_redeemed'], 'The same pass checks in fresh at the next show.' );

		$this->assertCount( 2, $this->db->rows( RsvpPassesTable::checkins_table_name() ) );
	}

	public function test_lost_race_reports_the_winner(): void {
		$member = RsvpPassesTable::find_by_code( $this->add_to_venue_list()['code'] );
		$this->db->tables[ RsvpPassesTable::checkins_table_name() ][] = array(
			'id'                    => 999,
			'pass_id'               => $member['id'],
			'event_id'              => self::VENUE_SHOW,
			'checked_in_at'         => '2026-06-01 22:00:00',
			'checked_in_by_user_id' => self::ADMIN_ID,
		);

		$result = RsvpPassesTable::check_in( (int) $member['id'], self::VENUE_SHOW, self::VENUE_MEMBER );
		$this->assertTrue( $result['already_redeemed'] );
		$this->assertSame( '2026-06-01 22:00:00', $result['redeemed_at'] );
		$this->assertSame( self::ADMIN_ID, $result['redeemed_by_user_id'] );
	}

	public function test_works_only_at_published_shows_tagged_with_the_owner(): void {
		$venue_code    = $this->add_to_venue_list()['code'];
		$promoter_code = extrachill_events_add_owner_list_name( 'promoter', self::PROMOTER_ID, 'Dee', '', self::PROMOTER_MEMBER )['code'];

		foreach ( array( self::OTHER_SHOW, self::PROMOTER_SHOW, self::DRAFT_SHOW ) as $not_theirs ) {
			$denied = $this->redeem( $not_theirs, $venue_code );
			$this->assertInstanceOf( WP_Error::class, $denied );
			$this->assertSame( 'owner_list_not_eligible', $denied->get_error_code() );
			$this->assertStringStartsWith( 'Not on the list', $denied->get_error_message() );
		}

		$this->assertIsArray( $this->redeem( self::PROMOTER_SHOW, $promoter_code ), 'Promoter list works at the promoter\'s show at any venue.' );
		$this->assertSame( 'owner_list_not_eligible', $this->redeem( self::VENUE_SHOW, $promoter_code )->get_error_code() );

		$unknown = $this->redeem( self::VENUE_SHOW, 'ZZZZZ-ZZZZZ-ZZZZZ' );
		$this->assertSame( 'rsvp_pass_not_found', $unknown->get_error_code() );
		$this->assertCount( 1, $this->db->rows( RsvpPassesTable::checkins_table_name() ) );
	}

	public function test_removed_pass_is_rejected_immediately(): void {
		$added = $this->add_to_venue_list();
		$this->assertIsArray( $this->redeem( self::VENUE_SHOW, $added['code'] ) );

		extrachill_events_remove_owner_list_name( 'venue', self::VENUE_ID, $added['member_id'], self::VENUE_MEMBER );

		$denied = $this->redeem( self::VENUE_SHOW_2, $added['code'] );
		$this->assertSame( 'owner_list_removed', $denied->get_error_code() );
		$this->assertStringStartsWith( 'Not on the list', $denied->get_error_message() );
		$this->assertSame( array(), $this->owner_door_rows( self::VENUE_SHOW_2 ), 'Gone from the door list too.' );
		$this->assertSame( 'revoked', RsvpPassesTable::find_by_code( $added['code'] )['status'], 'Removal is the pass revocation.' );

		$this->assertInstanceOf( WP_Error::class, extrachill_events_remove_owner_list_name( 'venue', self::VENUE_ID, $added['member_id'] ), 'Removing twice is a miss.' );
	}

	public function test_owner_check_ins_never_use_the_event_guest_list_spots(): void {
		$GLOBALS['event_perk_test']['meta'][ self::VENUE_SHOW ] = array(
			'_extrachill_event_perk_enabled' => true,
			'_extrachill_event_perk_text'    => 'Extra Chill guest list',
		);
		extrachill_events_set_perk_password( self::VENUE_SHOW, 'boogieman' );
		extrachill_events_set_perk_capacity( self::VENUE_SHOW, 1 );
		$before = extrachill_events_get_guest_list_counts( self::VENUE_SHOW );

		foreach ( array( 'Sam', 'Rae', 'Dee' ) as $name ) {
			$this->assertIsArray( $this->redeem( self::VENUE_SHOW, $this->add_to_venue_list( $name )['code'] ) );
		}

		$after = extrachill_events_get_guest_list_counts( self::VENUE_SHOW );
		$this->assertSame( $before, $after, 'Claimed, checked in, and spots left are unchanged.' );
		$this->assertSame( 1, (int) $after['spots_left'] );
		$this->assertSame( array( 0, 0, 0 ), array_map( 'intval', array_column( $this->db->rows( RsvpPassesTable::table_name() ), 'event_id' ) ), 'Only owner passes, no per-event pass rows.' );
	}

	public function test_door_list_rows_are_labelled_per_owner_with_check_in_state(): void {
		$sam = $this->add_to_venue_list( 'Sam' );
		extrachill_events_add_owner_list_name( 'promoter', self::PROMOTER_ID, 'Dee', '', self::PROMOTER_MEMBER );
		$this->redeem( self::VENUE_SHOW, $sam['code'] );

		$rows = $this->owner_door_rows( self::VENUE_SHOW );
		$this->assertCount( 1, $rows, 'A venue show lists the venue list only.' );
		$this->assertSame( 'Sam (Venue list)', $rows[0]['label'] );
		$this->assertSame( 'Venue list', $rows[0]['source'] );
		$this->assertSame( 'redeemed', $rows[0]['status'] );
		$this->assertTrue( $rows[0]['owner_list'] );
		$this->assertSame( $sam['code'], $rows[0]['code'] );

		$next_week = $this->owner_door_rows( self::VENUE_SHOW_2 );
		$this->assertSame( 'active', $next_week[0]['status'], 'Check-in is per event.' );

		$promoter_show = $this->owner_door_rows( self::PROMOTER_SHOW );
		$this->assertSame( array( 'Dee (Promoter list)' ), array_column( $promoter_show, 'label' ) );

		$this->assertSame( array(), $this->owner_door_rows( self::DRAFT_SHOW ) );
		$this->assertSame( array(), $this->owner_door_rows( self::OTHER_SHOW ) );
	}

	public function test_door_list_template_labels_and_never_offers_permanent_removal(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/templates/door-list.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level template read.
		$this->assertStringContainsString( "empty( \$guest_row['owner_list'] )", $source );
		$this->assertStringContainsString( 'empty( $guest_rows )', $source );

		$integration = (string) file_get_contents( dirname( __DIR__, 3 ) . '/inc/core/rsvp-pass-integration.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level read.
		$this->assertStringContainsString( "extrachill_events_door_list_guest_rows( \$event_id, '' !== \$guest_list_url )", $integration );
	}

	public function test_scan_view_for_tonight_show(): void {
		$sam = $this->add_to_venue_list( 'Sam' );
		$this->tonight( array( 'venue:' . self::VENUE_ID => array( self::VENUE_SHOW ) ) );

		$view = $this->verify_view( strtolower( $sam['code'] ), self::VENUE_MEMBER );
		$this->assertSame( 'eligible', $view['state'] );
		$this->assertSame( 'Sam', $view['name'] );
		$this->assertSame( 'Venue list', $view['owner']['label'] );
		$this->assertSame( array( self::VENUE_SHOW ), array_column( $view['events'], 'event_id' ) );
		$this->assertSame( '', $view['events'][0]['checked_in_at'] );

		$this->redeem( self::VENUE_SHOW, $sam['code'] );
		$view = $this->verify_view( $sam['code'], self::VENUE_MEMBER );
		$this->assertNotSame( '', $view['events'][0]['checked_in_at'], 'Shows the existing check-in.' );

		$this->assertNull( $this->verify_view( $sam['code'], self::OUTSIDER ), 'Outsiders get the generic message.' );
		$this->assertNull( $this->verify_view( $sam['code'], 0 ), 'Logged out gets the generic message.' );
		$this->assertNull( $this->verify_view( 'ZZZZZ-ZZZZZ-ZZZZZ', self::VENUE_MEMBER ) );
	}

	public function test_scan_view_at_a_show_that_is_not_theirs_says_not_on_the_list(): void {
		$sam = $this->add_to_venue_list( 'Sam' );
		// The other venue's door staff scans Sam at their own show tonight.
		$this->tonight( array( 'venue:' . self::OTHER_VENUE_ID => array( self::OTHER_SHOW ) ) );

		$view = $this->verify_view( $sam['code'], self::OTHER_VENUE_TEAM );
		$this->assertSame( 'not_eligible', $view['state'] );
		$this->assertArrayNotHasKey( 'name', $view, 'No name for a pass that is not for this show.' );

		$pinned = $this->verify_view( $sam['code'], self::OTHER_VENUE_TEAM, self::OTHER_SHOW );
		$this->assertSame( 'not_eligible', $pinned['state'] );

		$this->assertNull( $this->verify_view( $sam['code'], self::OUTSIDER, self::OTHER_SHOW ), 'Pinning a show you do not run reveals nothing.' );
	}

	public function test_scan_view_after_removal(): void {
		$sam = $this->add_to_venue_list( 'Sam' );
		extrachill_events_remove_owner_list_name( 'venue', self::VENUE_ID, $sam['member_id'] );

		$view = $this->verify_view( $sam['code'], self::VENUE_MEMBER );
		$this->assertSame( 'removed', $view['state'] );
		$this->assertArrayNotHasKey( 'name', $view );
		$this->assertNull( $this->verify_view( $sam['code'], self::OUTSIDER ) );
	}

	public function test_codes_resolve_across_both_scopes_and_per_event_passes_are_unaffected(): void {
		$this->as_user( self::VENUE_MEMBER );
		$sam   = $this->add_to_venue_list( 'Sam' );
		$guest = RsvpPassesTable::issue_anonymous( self::VENUE_SHOW, 0, 'Jo', self::VENUE_MEMBER );
		$this->assertIsArray( $guest );
		$this->assertFalse( RsvpPassesTable::is_owner_pass( $guest ) );
		$this->assertTrue( RsvpPassesTable::is_owner_pass( RsvpPassesTable::find_by_code( $sam['code'] ) ) );

		// A per-event pass redeems once, at its own event only, on the row.
		$this->assertSame( 'rsvp_pass_not_found', $this->redeem( self::VENUE_SHOW_2, $guest['code'] )->get_error_code() );
		$first = $this->redeem( self::VENUE_SHOW, $guest['code'] );
		$this->assertFalse( $first['already_redeemed'] );
		$this->assertArrayNotHasKey( 'label', $first, 'Per-event redemption is not an owner check-in.' );
		$this->assertSame( 'redeemed', RsvpPassesTable::find_by_code( $guest['code'] )['status'] );
		$this->assertTrue( $this->redeem( self::VENUE_SHOW, $guest['code'] )['already_redeemed'] );
		$this->assertSame( array(), $this->db->rows( RsvpPassesTable::checkins_table_name() ), 'Per-event passes never write check-ins.' );

		// An owner pass never redeems through the per-event path, and is never `redeemed`.
		$this->assertSame( 'rsvp_pass_not_found', RsvpPassesTable::redeem_by_code( self::VENUE_SHOW, $sam['code'], self::VENUE_MEMBER )->get_error_code() );
		$this->assertFalse( $this->redeem( self::VENUE_SHOW, strtolower( $sam['code'] ) )['already_redeemed'], 'The door may type the code in lower case.' );
		$this->assertSame( 'active', RsvpPassesTable::find_by_code( $sam['code'] )['status'] );

		// One door query: the event's guest and the venue's standing list, labelled.
		$rows = extrachill_events_door_list_guest_rows( self::VENUE_SHOW );
		$rows = array_column( $rows, null, 'label' );
		$this->assertEqualsCanonicalizing( array( 'Jo', 'Sam (Venue list)' ), array_keys( $rows ) );
		$this->assertSame( array( 'redeemed', 'redeemed' ), array_column( $rows, 'status' ) );
		$this->assertArrayNotHasKey( 'owner_list', $rows['Jo'] );
		$this->assertTrue( $rows['Sam (Venue list)']['owner_list'] );
		$this->assertSame( array( 'Sam (Venue list)' ), array_column( extrachill_events_door_list_guest_rows( self::VENUE_SHOW, false ), 'label' ), 'No guest list page: only the standing list.' );
	}

	public function test_owner_pass_storage_shape(): void {
		$sam  = $this->add_to_venue_list( 'Sam', 'sam@example.com' );
		$pass = RsvpPassesTable::find_by_code( $sam['code'] );
		$this->assertSame( 0, (int) $pass['event_id'] );
		$this->assertSame( 'venue', $pass['owner_type'] );
		$this->assertSame( self::VENUE_ID, (int) $pass['owner_term_id'] );
		$this->assertSame( 'sam@example.com', $pass['email'] );
		$this->assertSame( self::VENUE_MEMBER, (int) $pass['issued_by_user_id'] );
		$this->assertSame( (int) $pass['id'], $sam['member_id'], 'member_id is the pass ID.' );
		$this->assertSame( 'active', $sam['status'] );
		$this->assertSame( 'removed', extrachill_events_owner_list_member_summary( array( 'status' => 'revoked' ) + $pass )['status'], 'A revoked pass still reads `removed` in the ability contract.' );
		$this->assertSame( '9', RsvpPassesTable::SCHEMA_VERSION );
	}

	/** The scan view, fed the one code lookup the verify page does. */
	private function verify_view( string $code, int $user_id, int $event_id = 0 ): ?array {
		return extrachill_events_owner_list_verify_view( RsvpPassesTable::find_by_code( strtoupper( $code ) ), $user_id, $event_id );
	}

	/** Door rows for an event with no guest list page: the standing lists only. */
	private function owner_door_rows( int $event_id ): array {
		return extrachill_events_door_list_guest_rows( $event_id, false );
	}
}
