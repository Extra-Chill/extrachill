<?php
/**
 * Password-gated, capacity-limited guest list (extrachill#433).
 *
 * Covers capacity enforcement in RsvpPassesTable, the password gate on the
 * guest RSVP and the logged-in claim, the rule
 * that a bare Going mark (or the enable-time backfill) never issues a pass
 * on a gated event, and the door-facing counts.
 *
 * Shims share global keys with sibling files in the rsvp-perks-unit suite
 * (`event_management_test` posts, `event_perk_test` meta,
 * `rsvp_pass_mail_test` mail, `rsvp_guest_test` marks/transients), so load
 * order doesn't matter.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP function shims with the test case class.

use ExtraChillEvents\Core\RsvpPassesTable;

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		return $GLOBALS['event_management_test']['posts'][ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		unset( $single );
		return $GLOBALS['event_perk_test']['meta'][ $post_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['event_perk_test']['meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key ) {
		unset( $GLOBALS['event_perk_test']['meta'][ $post_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_hash_password' ) ) {
	function wp_hash_password( $password ) {
		return password_hash( (string) $password, PASSWORD_BCRYPT, array( 'cost' => 4 ) );
	}
}

if ( ! function_exists( 'wp_check_password' ) ) {
	function wp_check_password( $password, $hash, $user_id = '' ) {
		unset( $user_id );
		return password_verify( (string) $password, (string) $hash );
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return 7;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return $GLOBALS['rsvp_pass_mail_test']['users'][ $user_id ] ?? false;
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return trim( (string) $email );
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post ) {
		return 'https://events.extrachill.com/events/' . $post->ID;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) {
		unset( $domain );
		return esc_html( $text );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = null ) {
		unset( $domain );
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = null ) {
		unset( $domain );
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'ec_send_email_queued' ) ) {
	function ec_send_email_queued( array $args ) {
		$GLOBALS['rsvp_pass_mail_test']['sent'][] = $args;
		return $GLOBALS['rsvp_pass_mail_test']['result'] ?? array( 'success' => true );
	}
}

if ( ! function_exists( 'ec_users_get_event_attendees_full' ) ) {
	function ec_users_get_event_attendees_full( int $event_id, int $blog_id = 0, int $limit = 200 ): array {
		unset( $blog_id, $limit );
		return $GLOBALS['rsvp_pass_mail_test']['attendees'][ $event_id ] ?? array();
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['rsvp_guest_test']['transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		unset( $expiration );
		$GLOBALS['rsvp_guest_test']['transients'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'ec_users_mark_event' ) ) {
	/** Same double as RsvpGuestRsvpTest: fires ec_users_event_marked on a new mark. */
	function ec_users_mark_event( int $user_id, int $event_id, int $blog_id = 0 ) {
		$GLOBALS['rsvp_guest_test']['marks'][]         = array( $user_id, $event_id, $blog_id );
		$GLOBALS['rsvp_guest_test']['claim_at_mark'][] = extrachill_events_rsvp_claim_url_handoff( $user_id );

		$key = $user_id . ':' . $event_id;
		if ( isset( $GLOBALS['rsvp_guest_test']['marked'][ $key ] ) ) {
			return false;
		}
		$GLOBALS['rsvp_guest_test']['marked'][ $key ] = true;
		do_action( 'ec_users_event_marked', $user_id, $event_id, $blog_id );
		return true;
	}
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'EXTRACHILL_EVENTS_PLUGIN_DIR' ) ) {
	define( 'EXTRACHILL_EVENTS_PLUGIN_DIR', dirname( __DIR__, 3 ) . '/' );
}

require_once dirname( __DIR__, 3 ) . '/inc/admin/event-perks.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/RsvpPassesTable.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/rsvp-pass-service.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/rsvp-guest-service.php';

/** Stateful $wpdb matching RsvpPassesTable's query shapes, plus GET_LOCK and the count query. */
final class RsvpGuestListFakeWpdb {

	public $prefix = 'wp_7_';

	public $insert_id = 0;

	/** @var array<int, array<string, mixed>> */
	public $rows = array();

	/** @var bool Whether GET_LOCK succeeds. */
	public $lock_available = true;

	/** @var string[] */
	public $lock_calls = array();

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

	public function get_var( $query ) {
		if ( str_starts_with( $query, 'SELECT GET_LOCK' ) ) {
			$this->lock_calls[] = 'get';
			return $this->lock_available ? '1' : '0';
		}
		if ( str_starts_with( $query, 'SELECT RELEASE_LOCK' ) ) {
			$this->lock_calls[] = 'release';
			return '1';
		}
		return null;
	}

	public function insert( $table, $data ) {
		unset( $table );
		$data['id']      = $this->next_id++;
		$this->rows[]    = $data;
		$this->insert_id = $data['id'];
		return 1;
	}

	public function update( $table, $data, $where ) {
		unset( $table );
		$count = 0;
		foreach ( $this->rows as $index => $row ) {
			foreach ( $where as $key => $value ) {
				if ( ( $row[ $key ] ?? null ) != $value ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Test double.
					continue 2;
				}
			}
			$this->rows[ $index ] = array_merge( $row, $data );
			++$count;
		}
		return $count;
	}

	public function get_row( $query, $output = null ) {
		unset( $output );
		if ( preg_match( '/SUM\(.*WHERE event_id = (\d+)/', $query, $match ) ) {
			$claimed    = 0;
			$checked_in = 0;
			foreach ( $this->rows as $row ) {
				if ( (int) $row['event_id'] !== (int) $match[1] ) {
					continue;
				}
				$claimed    += in_array( $row['status'], array( 'active', 'redeemed' ), true ) ? 1 : 0;
				$checked_in += 'redeemed' === $row['status'] ? 1 : 0;
			}
			return array(
				'claimed'    => (string) $claimed,
				'checked_in' => (string) $checked_in,
			);
		}
		if ( preg_match( "/WHERE code = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->rows as $row ) {
				if ( $row['code'] === $match[1] ) {
					return $row;
				}
			}
			return null;
		}
		if ( preg_match( '/WHERE event_id = (\d+) AND user_id = (\d+)/', $query, $match ) ) {
			foreach ( $this->rows as $row ) {
				if ( (int) $row['event_id'] === (int) $match[1] && isset( $row['user_id'] ) && (int) $row['user_id'] === (int) $match[2] ) {
					return $row;
				}
			}
		}
		return null;
	}

	public function get_results( $query, $output = null ) {
		unset( $output );
		if ( preg_match( '/WHERE event_id = (\d+) AND user_id IS NULL/', $query, $match ) ) {
			return array_values(
				array_filter(
					$this->rows,
					static function ( $row ) use ( $match ) {
						return (int) $row['event_id'] === (int) $match[1] && ! isset( $row['user_id'] );
					}
				)
			);
		}
		return array();
	}

	public function query( $query ) {
		// Redeem compare-and-set: UPDATE ... WHERE id = N AND status = 'active'.
		if ( preg_match( "/SET status = '(\w+)', redeemed_at = '([^']+)', redeemed_by_user_id = (\d+).*WHERE id = (\d+) AND status = '(\w+)'/", $query, $m ) ) {
			foreach ( $this->rows as $index => $row ) {
				if ( (int) $row['id'] === (int) $m[4] && $row['status'] === $m[5] ) {
					$this->rows[ $index ]['status']              = $m[1];
					$this->rows[ $index ]['redeemed_at']         = $m[2];
					$this->rows[ $index ]['redeemed_by_user_id'] = (int) $m[3];
					return 1;
				}
			}
		}
		return 0;
	}

	/** Seed a pass row directly. */
	public function seed( int $event_id, int $user_id, string $status ): void {
		$this->insert(
			'',
			array(
				'event_id' => $event_id,
				'user_id'  => $user_id,
				'code'     => 'SEED-' . $user_id,
				'status'   => $status,
			)
		);
	}
}

/** Double of the extrachill-users resolve-account-by-email ability: one user per email. */
final class RsvpGuestListResolveAbility {
	/** @var array<int, array> */
	public array $calls = array();

	public function execute( array $input ) {
		$this->calls[] = $input;
		return array(
			'user_id'   => 5000 + count( $this->calls ),
			'created'   => true,
			'unclaimed' => false,
			'claim_url' => '',
		);
	}
}

final class RsvpGuestListTest extends PHPUnit\Framework\TestCase {

	private const EVENT_ID = 640348;
	private const PASSWORD = 'boogieman';

	private RsvpGuestListResolveAbility $ability;

	private RsvpGuestListFakeWpdb $db;

	/** @var mixed */
	private $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db            = new RsvpGuestListFakeWpdb();
		$GLOBALS['wpdb']     = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated test double.

		$this->ability                       = new RsvpGuestListResolveAbility();
		$ability                             = $this->ability;
		$GLOBALS['ec_test_ability_resolver'] = static function ( $name ) use ( $ability ) {
			return 'extrachill/resolve-account-by-email' === $name ? $ability : null;
		};

		$GLOBALS['rsvp_guest_test']     = array(
			'marks'         => array(),
			'claim_at_mark' => array(),
			'marked'        => array(),
			'transients'    => array(),
		);
		$users                          = array();
		foreach ( array_merge( range( 1, 10 ), range( 5001, 5010 ) ) as $id ) {
			$users[ $id ] = (object) array(
				'ID'           => $id,
				'user_email'   => "u{$id}@example.com",
				'display_name' => "u{$id}",
			);
		}
		$GLOBALS['rsvp_pass_mail_test'] = array(
			'sent'      => array(),
			'result'    => array( 'success' => true ),
			'users'     => $users,
			'attendees' => array(),
		);

		$GLOBALS['event_management_test']['posts'][ self::EVENT_ID ] = (object) array(
			'ID'          => self::EVENT_ID,
			'post_title'  => 'DRUZIN (Official Boogieman Fest After Party)',
			'post_type'   => 'data_machine_events',
			'post_status' => 'publish',
		);
		$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ]        = array(
			'_extrachill_event_perk_enabled' => true,
			'_extrachill_event_perk_text'    => 'Extra Chill guest list',
		);
		extrachill_events_set_perk_password( self::EVENT_ID, self::PASSWORD );
		extrachill_events_set_perk_capacity( self::EVENT_ID, 2 );

		remove_action( 'ec_users_event_marked', 'extrachill_events_rsvp_pass_on_marked', 10 );
		add_action( 'ec_users_event_marked', 'extrachill_events_rsvp_pass_on_marked', 10, 3 );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the runtime database.
		unset(
			$GLOBALS['ec_test_ability_resolver'],
			$GLOBALS['rsvp_guest_test'],
			$GLOBALS['rsvp_pass_mail_test'],
			$GLOBALS['event_management_test']['posts'][ self::EVENT_ID ],
			$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ]
		);
	}

	private function guest( string $email, string $password = self::PASSWORD ) {
		return extrachill_events_guest_rsvp( self::EVENT_ID, $email, array(), $password );
	}

	public function test_password_is_stored_hashed_never_plaintext(): void {
		$stored = $GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ][ EXTRACHILL_EVENTS_PERK_PASSWORD_META ];

		$this->assertNotSame( self::PASSWORD, $stored );
		$this->assertTrue( extrachill_events_perk_password_required( self::EVENT_ID ) );
		$this->assertTrue( extrachill_events_perk_check_password( self::EVENT_ID, self::PASSWORD ) );
		$this->assertFalse( extrachill_events_perk_check_password( self::EVENT_ID, 'wrong' ) );
		$this->assertFalse( extrachill_events_perk_check_password( self::EVENT_ID, '' ) );
	}

	public function test_clearing_password_opens_the_list(): void {
		extrachill_events_set_perk_password( self::EVENT_ID, '' );

		$this->assertFalse( extrachill_events_perk_password_required( self::EVENT_ID ) );
		$this->assertTrue( extrachill_events_perk_check_password( self::EVENT_ID, '' ) );
	}

	public function test_table_stops_issuing_at_capacity(): void {
		$this->assertNotNull( RsvpPassesTable::issue_or_reactivate( self::EVENT_ID, 1, 2 ) );
		$this->assertNotNull( RsvpPassesTable::issue_or_reactivate( self::EVENT_ID, 2, 2 ) );
		$this->assertNull( RsvpPassesTable::issue_or_reactivate( self::EVENT_ID, 3, 2 ) );

		// A holder still gets their pass back on a full list.
		$this->assertNotNull( RsvpPassesTable::issue_or_reactivate( self::EVENT_ID, 1, 2 ) );
		$this->assertSame( array( 'get', 'release', 'get', 'release', 'get', 'release' ), $this->db->lock_calls );
	}

	public function test_redeemed_counts_and_revoked_frees_a_spot(): void {
		$this->db->seed( self::EVENT_ID, 1, 'redeemed' );
		$this->db->seed( self::EVENT_ID, 2, 'revoked' );

		$this->assertNotNull( RsvpPassesTable::issue_or_reactivate( self::EVENT_ID, 3, 2 ) );
		$this->assertNull( RsvpPassesTable::issue_or_reactivate( self::EVENT_ID, 2, 2 ) );
		$this->assertSame(
			array(
				'claimed'    => 2,
				'checked_in' => 1,
			),
			RsvpPassesTable::count_for_event( self::EVENT_ID )
		);
	}

	public function test_no_lock_means_no_pass(): void {
		$this->db->lock_available = false;

		$this->assertNull( RsvpPassesTable::issue_or_reactivate( self::EVENT_ID, 1, 2 ) );
		$this->assertSame( array(), $this->db->rows );
	}

	public function test_unlimited_capacity_skips_the_lock(): void {
		$this->assertNotNull( RsvpPassesTable::issue_or_reactivate( self::EVENT_ID, 1 ) );
		$this->assertSame( array(), $this->db->lock_calls );
	}

	public function test_guest_with_password_gets_a_pass(): void {
		$this->assertSame( array( 'status' => 'rsvped' ), $this->guest( 'a@example.com' ) );
		$this->assertCount( 1, $this->db->rows );
		$this->assertCount( 1, $GLOBALS['rsvp_pass_mail_test']['sent'] );
	}

	public function test_wrong_or_missing_password_is_rejected_before_any_account_work(): void {
		$this->assertSame( 'invalid_password', $this->guest( 'a@example.com', 'nope' )->get_error_code() );
		$this->assertSame( 'invalid_password', $this->guest( 'a@example.com', '' )->get_error_code() );
		$this->assertSame( array(), $this->ability->calls );
		$this->assertSame( array(), $this->db->rows );
	}

	public function test_full_list_rejects_before_any_account_work(): void {
		$this->guest( 'a@example.com' );
		$this->guest( 'b@example.com' );
		$calls = count( $this->ability->calls );

		$this->assertSame( 'guest_list_full', $this->guest( 'c@example.com' )->get_error_code() );
		$this->assertCount( $calls, $this->ability->calls );
		$this->assertCount( 2, $this->db->rows );
	}

	public function test_bare_going_mark_never_issues_on_a_gated_event(): void {
		do_action( 'ec_users_event_marked', 1, self::EVENT_ID, 7 );

		$this->assertSame( array(), $this->db->rows );
		$this->assertSame( array(), $GLOBALS['rsvp_pass_mail_test']['sent'] );
	}

	public function test_backfill_skips_gated_events(): void {
		$GLOBALS['rsvp_pass_mail_test']['attendees'][ self::EVENT_ID ] = array( array( 'user_id' => 1 ) );

		$this->assertSame( 0, extrachill_events_backfill_rsvp_passes( self::EVENT_ID ) );
		$this->assertSame( array(), $this->db->rows );
	}

	public function test_logged_in_claim_returns_the_pass(): void {
		$result = extrachill_events_claim_guest_list_pass( self::EVENT_ID, 1, self::PASSWORD );

		$this->assertTrue( $result['issued'] );
		$this->assertSame( 'active', $result['status'] );
		$this->assertSame( $this->db->rows[0]['code'], $result['code'] );
		$this->assertSame( 'Extra Chill guest list', $result['perk_text'] );
	}

	public function test_already_going_user_can_claim_with_the_password(): void {
		do_action( 'ec_users_event_marked', 1, self::EVENT_ID, 7 );
		$GLOBALS['rsvp_guest_test']['marked'][ '1:' . self::EVENT_ID ] = true;

		$result = extrachill_events_claim_guest_list_pass( self::EVENT_ID, 1, self::PASSWORD );

		$this->assertTrue( $result['issued'] );
		$this->assertCount( 1, $this->db->rows );
		$this->assertCount( 1, $GLOBALS['rsvp_pass_mail_test']['sent'] );
	}

	public function test_logged_in_claim_needs_the_password_and_a_spot(): void {
		$this->assertSame( 'invalid_password', extrachill_events_claim_guest_list_pass( self::EVENT_ID, 1, 'nope' )->get_error_code() );

		extrachill_events_claim_guest_list_pass( self::EVENT_ID, 1, self::PASSWORD );
		extrachill_events_claim_guest_list_pass( self::EVENT_ID, 2, self::PASSWORD );

		$this->assertSame( 'guest_list_full', extrachill_events_claim_guest_list_pass( self::EVENT_ID, 3, self::PASSWORD )->get_error_code() );
		// The holder can still pull their pass up on a full list.
		$this->assertTrue( extrachill_events_claim_guest_list_pass( self::EVENT_ID, 1, self::PASSWORD )['issued'] );
	}

	public function test_counts_and_door_summary(): void {
		$this->db->seed( self::EVENT_ID, 1, 'redeemed' );

		$counts = extrachill_events_get_guest_list_counts( self::EVENT_ID );

		$this->assertSame( 2, $counts['capacity'] );
		$this->assertSame( 1, $counts['spots_left'] );
		$this->assertFalse( $counts['full'] );
		$this->assertSame( '1 of 2 checked in. 1 spot left.', extrachill_events_format_guest_list_count( $counts ) );

		extrachill_events_set_perk_capacity( self::EVENT_ID, 0 );
		$this->assertNull( extrachill_events_get_guest_list_counts( self::EVENT_ID )['spots_left'] );
	}

	public function test_guest_list_pass_needs_name_and_password_only(): void {
		$this->assertSame( 'guest_name_required', extrachill_events_issue_guest_list_pass( self::EVENT_ID, self::PASSWORD, '  ' )->get_error_code() );
		$this->assertSame( 'invalid_password', extrachill_events_issue_guest_list_pass( self::EVENT_ID, 'nope', 'Sarah' )->get_error_code() );
		$this->assertSame( array(), $this->db->rows, 'Rejected requests leave no trace.' );

		$pass = extrachill_events_issue_guest_list_pass( self::EVENT_ID, self::PASSWORD, 'Sarah' );

		$this->assertSame( 'Sarah', $pass['name'] );
		$this->assertMatchesRegularExpression( '/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/', $pass['code'] );
		$this->assertFalse( $pass['emailed'] );
		$this->assertSame( 1, $pass['spots_left'] );
		$this->assertArrayNotHasKey( 'user_id', $this->db->rows[0], 'No account is attached.' );
		$this->assertSame( 'Sarah', $this->db->rows[0]['guest_name'] );
		$this->assertSame( array(), $this->ability->calls, 'No account is resolved or created.' );
		$this->assertSame( array(), $GLOBALS['rsvp_pass_mail_test']['sent'] );
	}

	public function test_guest_list_passes_stop_at_capacity_under_the_lock(): void {
		extrachill_events_issue_guest_list_pass( self::EVENT_ID, self::PASSWORD, 'A' );
		extrachill_events_issue_guest_list_pass( self::EVENT_ID, self::PASSWORD, 'B' );

		$this->assertSame( 'guest_list_full', extrachill_events_issue_guest_list_pass( self::EVENT_ID, self::PASSWORD, 'C' )->get_error_code() );
		$this->assertCount( 2, $this->db->rows );
		$this->assertSame( array( 'get', 'release', 'get', 'release', 'get', 'release' ), $this->db->lock_calls );

		$this->db->lock_available = false;
		$this->db->rows           = array();
		$this->assertSame( 'guest_list_full', extrachill_events_issue_guest_list_pass( self::EVENT_ID, self::PASSWORD, 'D' )->get_error_code(), 'Fails closed without the lock.' );
	}

	public function test_guest_list_pass_requires_a_gated_list(): void {
		extrachill_events_set_perk_password( self::EVENT_ID, '' );

		$this->assertSame( 'guest_list_unavailable', extrachill_events_issue_guest_list_pass( self::EVENT_ID, '', 'Sarah' )->get_error_code() );
	}

	public function test_guest_list_pass_rejects_a_bad_optional_email(): void {
		$this->assertSame( 'invalid_email', extrachill_events_issue_guest_list_pass( self::EVENT_ID, self::PASSWORD, 'Sarah', 'not-an-email' )->get_error_code() );
		$this->assertSame( array(), $this->db->rows );
	}

	public function test_guest_list_pass_redeems_once_by_code_on_its_own_event(): void {
		$pass = extrachill_events_issue_guest_list_pass( self::EVENT_ID, self::PASSWORD, 'Sarah' );

		$this->assertSame( 'rsvp_pass_not_found', RsvpPassesTable::redeem_by_code( 999, $pass['code'], 38 )->get_error_code() );

		$first = RsvpPassesTable::redeem_by_code( self::EVENT_ID, $pass['code'], 38 );
		$this->assertFalse( $first['already_redeemed'] );
		$this->assertSame( 38, $first['redeemed_by_user_id'] );

		$this->assertTrue( RsvpPassesTable::redeem_by_code( self::EVENT_ID, $pass['code'], 38 )['already_redeemed'] );
		$this->assertSame( 1, extrachill_events_get_guest_list_counts( self::EVENT_ID )['checked_in'] );

		$guests = RsvpPassesTable::list_guest_passes( self::EVENT_ID );
		$this->assertCount( 1, $guests );
		$this->assertSame( 'redeemed', $guests[0]['status'] );
	}
}
