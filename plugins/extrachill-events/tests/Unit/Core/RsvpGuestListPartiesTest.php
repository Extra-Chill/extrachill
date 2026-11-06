<?php
/**
 * Venue invites bands / management to fill guest list spots (extrachill#1128).
 *
 * Covers: allotments off means only the event's overall capacity applies;
 * with allotments on, each party is capped (under the capacity lock);
 * revoked and invalid invite tokens are rejected; only token hashes are
 * stored; and the door list attributes each guest to the party that added
 * them ("Jake (Slim S.O.U.L.)").
 *
 * Shims share global keys with sibling files in the rsvp-perks-unit suite
 * (`event_management_test` posts, `event_perk_test` meta,
 * `rsvp_pass_mail_test` mail/users), so load order doesn't matter.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP function shims with the test case class.

use ExtraChillEvents\Core\GuestListPartiesTable;
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

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-' . $scheme;
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

if ( ! function_exists( 'mysql2date' ) ) {
	function mysql2date( $format, $date ) {
		unset( $format );
		return (string) $date;
	}
}

if ( ! function_exists( 'get_date_from_gmt' ) ) {
	function get_date_from_gmt( $date ) {
		return (string) $date;
	}
}

if ( ! function_exists( 'ec_send_email_queued' ) ) {
	function ec_send_email_queued( array $args ) {
		$GLOBALS['rsvp_pass_mail_test']['sent'][] = $args;
		return $GLOBALS['rsvp_pass_mail_test']['result'] ?? array( 'success' => true );
	}
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'extrachill_events_guest_list_url' ) ) {
	function extrachill_events_guest_list_url( int $event_id ): string {
		return 'https://events.extrachill.com/events/' . $event_id . '/guest-list';
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	// Same shape as RsvpPassQrUrlsTest's stub, which loads first in the suite.
	function add_query_arg( ...$args ): string {
		if ( 2 === count( $args ) && is_array( $args[0] ) ) {
			$pairs = array();
			foreach ( $args[0] as $k => $v ) {
				$pairs[] = $k . '=' . $v;
			}
			return $args[1] . ( false === strpos( $args[1], '?' ) ? '?' : '&' ) . implode( '&', $pairs );
		}
		list( $key, $value, $url ) = $args;
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value;
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/admin/event-perks.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/RsvpPassesTable.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/GuestListPartiesTable.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/rsvp-pass-service.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/rsvp-guest-service.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/guest-list-parties.php';

/** Stateful $wpdb covering the passes table and the party table query shapes. */
final class RsvpGuestListPartiesFakeWpdb {

	public $prefix = 'wp_7_';

	public $insert_id = 0;

	/** @var array<int, array<string, mixed>> Pass rows. */
	public $passes = array();

	/** @var array<int, array<string, mixed>> Party rows. */
	public $parties = array();

	/** @var string[] */
	public $lock_calls = array();

	private $next_id = 1;

	public function prepare( $query, ...$args ) {
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

	private function is_party_table( string $sql_or_table ): bool {
		return false !== strpos( $sql_or_table, 'guest_list_parties' );
	}

	public function get_var( $query ) {
		if ( str_starts_with( $query, 'SELECT GET_LOCK' ) ) {
			$this->lock_calls[] = 'get';
			return '1';
		}
		if ( str_starts_with( $query, 'SELECT RELEASE_LOCK' ) ) {
			$this->lock_calls[] = 'release';
			return '1';
		}
		if ( preg_match( '/COUNT\(\*\).*WHERE event_id = (\d+) AND party_id = (\d+)/', $query, $m ) ) {
			// "status = 'pending'" counts names waiting for approval (extrachill#1129); otherwise spots held.
			$statuses = preg_match( "/status = '(\w+)'/", $query, $s ) ? array( $s[1] ) : array( 'active', 'redeemed' );
			$count    = 0;
			foreach ( $this->passes as $row ) {
				$count += ( (int) $row['event_id'] === (int) $m[1] && (int) ( $row['party_id'] ?? 0 ) === (int) $m[2] && in_array( $row['status'], $statuses, true ) ) ? 1 : 0;
			}
			return (string) $count;
		}
		return null;
	}

	public function insert( $table, $data ) {
		$data['id']      = $this->next_id++;
		$this->insert_id = $data['id'];
		if ( $this->is_party_table( $table ) ) {
			$this->parties[] = $data;
		} else {
			$this->passes[] = $data;
		}
		return 1;
	}

	public function update( $table, $data, $where ) {
		$rows  = $this->is_party_table( $table ) ? 'parties' : 'passes';
		$count = 0;
		foreach ( $this->{$rows} as $index => $row ) {
			foreach ( $where as $key => $value ) {
				if ( ( $row[ $key ] ?? null ) != $value ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Test double.
					continue 2;
				}
			}
			$this->{$rows}[ $index ] = array_merge( $row, $data );
			++$count;
		}
		return $count;
	}

	public function get_row( $query, $output = null ) {
		unset( $output );
		if ( $this->is_party_table( $query ) ) {
			foreach ( $this->parties as $row ) {
				if ( preg_match( '/WHERE id = (\d+)/', $query, $m ) && (int) $row['id'] === (int) $m[1] ) {
					return $row;
				}
				if ( preg_match( "/WHERE token_hash = '([a-f0-9]+)'/", $query, $m ) && $row['token_hash'] === $m[1] ) {
					return $row;
				}
			}
			return null;
		}
		if ( preg_match( '/SUM\(.*WHERE event_id = (\d+)/', $query, $match ) ) {
			$claimed    = 0;
			$checked_in = 0;
			foreach ( $this->passes as $row ) {
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
			foreach ( $this->passes as $row ) {
				if ( $row['code'] === $match[1] ) {
					return $row;
				}
			}
		}
		return null;
	}

	public function get_results( $query, $output = null ) {
		unset( $output );
		if ( $this->is_party_table( $query ) && preg_match( '/WHERE event_id = (\d+)/', $query, $m ) ) {
			return array_values(
				array_filter(
					$this->parties,
					static function ( $row ) use ( $m ) {
						return (int) $row['event_id'] === (int) $m[1];
					}
				)
			);
		}
		if ( preg_match( '/WHERE event_id = (\d+) AND user_id IS NULL/', $query, $m ) ) {
			return array_values(
				array_filter(
					$this->passes,
					static function ( $row ) use ( $m ) {
						return (int) $row['event_id'] === (int) $m[1] && ! isset( $row['user_id'] );
					}
				)
			);
		}
		return array();
	}

	public function query( $query ) {
		if ( preg_match( "/SET status = '(\w+)', redeemed_at = '([^']+)', redeemed_by_user_id = (\d+).*WHERE id = (\d+) AND status = '(\w+)'/", $query, $m ) ) {
			foreach ( $this->passes as $index => $row ) {
				if ( (int) $row['id'] === (int) $m[4] && $row['status'] === $m[5] ) {
					$this->passes[ $index ]['status']      = $m[1];
					$this->passes[ $index ]['redeemed_at'] = $m[2];
					return 1;
				}
			}
		}
		return 0;
	}
}

final class RsvpGuestListPartiesTest extends PHPUnit\Framework\TestCase {

	private const EVENT_ID = 640349;
	private const HOST_ID  = 38;

	private RsvpGuestListPartiesFakeWpdb $db;

	/** @var mixed */
	private $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db            = new RsvpGuestListPartiesFakeWpdb();
		$GLOBALS['wpdb']     = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated test double.

		$GLOBALS['rsvp_pass_mail_test'] = array(
			'sent'      => array(),
			'result'    => array( 'success' => true ),
			'users'     => array(
				self::HOST_ID => (object) array(
					'ID'           => self::HOST_ID,
					'user_email'   => 'host@example.com',
					'display_name' => 'Lo-Fi Host',
				),
			),
			'attendees' => array(),
		);

		$GLOBALS['event_management_test']['posts'][ self::EVENT_ID ] = (object) array(
			'ID'          => self::EVENT_ID,
			'post_title'  => 'Boogie Man After Party',
			'post_type'   => 'data_machine_events',
			'post_status' => 'publish',
		);
		$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ]        = array(
			'_extrachill_event_perk_enabled' => true,
			'_extrachill_event_perk_text'    => 'Extra Chill guest list',
		);
		extrachill_events_set_perk_password( self::EVENT_ID, 'boogieman' );
		extrachill_events_set_perk_capacity( self::EVENT_ID, 3 );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the runtime database.
		unset(
			$GLOBALS['rsvp_pass_mail_test'],
			$GLOBALS['event_management_test']['posts'][ self::EVENT_ID ],
			$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ]
		);
	}

	/**
	 * Invite a party and return its raw token.
	 *
	 * @param string   $name      Party name.
	 * @param int|null $allotment Allotment.
	 * @return string
	 */
	private function invite( string $name = 'Slim S.O.U.L.', ?int $allotment = null ): string {
		$result = extrachill_events_create_guest_list_party( self::EVENT_ID, $name, 'mgmt@example.com', $allotment, self::HOST_ID );
		$this->assertIsArray( $result );
		$this->assertSame( 1, preg_match( '#/guest-invite/([a-f0-9]{64})/$#', $result['invite_url'], $m ) );
		return $m[1];
	}

	public function test_allotments_are_off_by_default(): void {
		$this->assertFalse( extrachill_events_guest_list_allotments_enabled( self::EVENT_ID ) );

		extrachill_events_set_guest_list_allotments( self::EVENT_ID, true );
		$this->assertTrue( extrachill_events_guest_list_allotments_enabled( self::EVENT_ID ) );
		extrachill_events_set_guest_list_allotments( self::EVENT_ID, false );
		$this->assertFalse( extrachill_events_guest_list_allotments_enabled( self::EVENT_ID ) );
	}

	public function test_invite_emails_a_private_link_and_stores_only_its_hash(): void {
		$token = $this->invite();

		$this->assertCount( 1, $this->db->parties );
		$party = $this->db->parties[0];
		$this->assertSame( 'Slim S.O.U.L.', $party['name'] );
		$this->assertSame( self::HOST_ID, $party['created_by_user_id'] );
		$this->assertArrayNotHasKey( 'allotment', $party, 'No allotment unless the host gives one.' );
		$this->assertSame( GuestListPartiesTable::hash_token( $token ), $party['token_hash'] );
		$this->assertStringNotContainsString( $token, serialize( $this->db->parties ), 'The raw token is never stored.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test-only flattening.

		$sent = $GLOBALS['rsvp_pass_mail_test']['sent'];
		$this->assertCount( 1, $sent );
		$this->assertSame( 'mgmt@example.com', $sent[0]['to'] );
		$this->assertStringContainsString( $token, $sent[0]['context']['cta_url'] );
	}

	public function test_invite_needs_a_name_an_email_and_a_guest_list(): void {
		$this->assertSame( 'party_name_required', extrachill_events_create_guest_list_party( self::EVENT_ID, '  ', 'a@example.com' )->get_error_code() );
		$this->assertSame( 'invalid_email', extrachill_events_create_guest_list_party( self::EVENT_ID, 'Band', '' )->get_error_code() );

		extrachill_events_set_perk_password( self::EVENT_ID, '' );
		$this->assertSame( 'guest_list_unavailable', extrachill_events_create_guest_list_party( self::EVENT_ID, 'Band', 'a@example.com' )->get_error_code() );
		$this->assertSame( array(), $this->db->parties );
	}

	public function test_allotments_off_uses_overall_capacity_only(): void {
		// An allotment stored while allotments are off is ignored.
		$token = $this->invite( 'Slim S.O.U.L.', 1 );

		$first = extrachill_events_party_add_guest_list_name( $token, 'Jake' );
		$this->assertSame( 'Jake', $first['name'] );
		$this->assertNull( $first['party_spots_left'] );
		$this->assertIsArray( extrachill_events_party_add_guest_list_name( $token, 'Amy' ) );
		$this->assertIsArray( extrachill_events_party_add_guest_list_name( $token, 'Rae' ) );

		$this->assertSame( 'guest_list_full', extrachill_events_party_add_guest_list_name( $token, 'Late' )->get_error_code(), 'The event capacity (3) is the only bound.' );
		$this->assertSame( 'guest_list_full', extrachill_events_add_guest_list_name( self::EVENT_ID, 'Host comp', '', self::HOST_ID )->get_error_code(), 'Band names share the spots with host comps.' );
		$this->assertCount( 3, $this->db->passes );
	}

	public function test_allotments_off_with_unlimited_capacity_never_caps_a_band(): void {
		extrachill_events_set_perk_capacity( self::EVENT_ID, 0 );
		$token = $this->invite();

		foreach ( range( 1, 6 ) as $n ) {
			$this->assertIsArray( extrachill_events_party_add_guest_list_name( $token, 'Guest ' . $n ) );
		}
		$this->assertSame( array(), $this->db->lock_calls, 'Nothing to count, so no lock.' );
	}

	public function test_allotment_cap_is_enforced_when_on(): void {
		extrachill_events_set_perk_capacity( self::EVENT_ID, 0 );
		extrachill_events_set_guest_list_allotments( self::EVENT_ID, true );

		$this->assertSame( 'allotment_required', extrachill_events_create_guest_list_party( self::EVENT_ID, 'Band', 'a@example.com' )->get_error_code() );

		$slim  = $this->invite( 'Slim S.O.U.L.', 2 );
		$other = $this->invite( 'Druzin Mgmt', 1 );

		$this->assertSame( 1, extrachill_events_party_add_guest_list_name( $slim, 'Jake' )['party_spots_left'] );
		$this->assertSame( 0, extrachill_events_party_add_guest_list_name( $slim, 'Amy' )['party_spots_left'] );
		$this->assertSame( 'party_allotment_full', extrachill_events_party_add_guest_list_name( $slim, 'Rae' )->get_error_code() );
		$this->assertIsArray( extrachill_events_party_add_guest_list_name( $other, 'Sam' ), 'Each party has its own cap.' );
		$this->assertCount( 3, $this->db->passes );

		// Locked count-and-issue for every capped add.
		$this->assertSame( array( 'get', 'release', 'get', 'release', 'get', 'release' ), $this->db->lock_calls );

		// Raising the allotment opens a spot; removing a name gives one back.
		$slim_id = (int) $this->db->parties[0]['id'];
		$this->assertSame( 1, extrachill_events_set_guest_list_party_allotment( self::EVENT_ID, $slim_id, 3 )['spots_left'] );
		$this->assertIsArray( extrachill_events_party_add_guest_list_name( $slim, 'Rae' ) );
		$jake = $this->db->passes[0]['code'];
		$this->assertTrue( extrachill_events_party_remove_guest_list_name( $slim, $jake )['removed'] );
		$this->assertSame( 1, extrachill_events_list_guest_list_parties( self::EVENT_ID )['parties'][0]['spots_left'] );
	}

	public function test_allotment_is_enforced_by_the_table_under_the_lock(): void {
		extrachill_events_set_guest_list_allotments( self::EVENT_ID, true );
		$this->invite( 'Slim S.O.U.L.', 1 );
		$party_id = (int) $this->db->parties[0]['id'];

		$this->assertNotNull( RsvpPassesTable::issue_anonymous( self::EVENT_ID, 0, 'Jake', 0, $party_id, 1 ) );
		$this->assertNull( RsvpPassesTable::issue_anonymous( self::EVENT_ID, 0, 'Amy', 0, $party_id, 1 ), 'A racing add past the pre-check still stops at the cap.' );
		$this->assertSame( $party_id, $this->db->passes[0]['party_id'] );
	}

	public function test_turning_allotments_on_fails_closed_for_parties_without_one(): void {
		$token = $this->invite();
		extrachill_events_set_guest_list_allotments( self::EVENT_ID, true );

		$this->assertSame( 'party_allotment_full', extrachill_events_party_add_guest_list_name( $token, 'Jake' )->get_error_code() );
		$this->assertSame( array(), $this->db->passes );
	}

	public function test_revoked_token_is_rejected_and_existing_names_stay(): void {
		$token = $this->invite();
		extrachill_events_party_add_guest_list_name( $token, 'Jake' );
		$party_id = (int) $this->db->parties[0]['id'];

		$revoked = extrachill_events_revoke_guest_list_party( self::EVENT_ID, $party_id );
		$this->assertTrue( $revoked['revoked'] );
		$this->assertSame( 1, $revoked['kept_guests'] );

		$this->assertSame( 'invite_revoked', extrachill_events_party_add_guest_list_name( $token, 'Amy' )->get_error_code() );
		$this->assertSame( 'invite_revoked', extrachill_events_party_remove_guest_list_name( $token, $this->db->passes[0]['code'] )->get_error_code() );
		$this->assertCount( 1, $this->db->passes );
		$this->assertSame( 'active', $this->db->passes[0]['status'], 'Revoking keeps names already on the list.' );

		// The host still removes them one by one.
		$this->assertTrue( extrachill_events_remove_guest_list_name( self::EVENT_ID, $this->db->passes[0]['code'] )['removed'] );
		$this->assertSame( 'party_not_revocable', extrachill_events_revoke_guest_list_party( self::EVENT_ID, $party_id )->get_error_code() );
	}

	public function test_invalid_tokens_are_rejected_without_writing(): void {
		$this->invite();

		foreach ( array( '', 'nope', str_repeat( 'a', 64 ), str_repeat( 'g', 64 ) ) as $bad ) {
			$this->assertSame( 'invalid_invite', extrachill_events_party_add_guest_list_name( $bad, 'Jake' )->get_error_code(), "Token '{$bad}' is rejected." );
		}
		$this->assertSame( array(), $this->db->passes );
	}

	public function test_revoking_another_events_party_is_refused(): void {
		$this->invite();
		$party_id = (int) $this->db->parties[0]['id'];

		$this->assertSame( 'party_not_revocable', extrachill_events_revoke_guest_list_party( 999, $party_id )->get_error_code() );
		$this->assertSame( 'party_not_found', extrachill_events_set_guest_list_party_allotment( 999, $party_id, 4 )->get_error_code() );
		$this->assertSame( 'active', $this->db->parties[0]['status'] );
	}

	public function test_a_party_only_removes_its_own_guests(): void {
		$slim  = $this->invite( 'Slim S.O.U.L.' );
		$other = $this->invite( 'Druzin Mgmt' );
		extrachill_events_party_add_guest_list_name( $slim, 'Jake' );
		$host_comp = extrachill_events_add_guest_list_name( self::EVENT_ID, 'Comp', '', self::HOST_ID );

		$this->assertSame( 'guest_not_removable', extrachill_events_party_remove_guest_list_name( $other, $this->db->passes[0]['code'] )->get_error_code() );
		$this->assertSame( 'guest_not_removable', extrachill_events_party_remove_guest_list_name( $slim, $host_comp['code'] )->get_error_code() );
		$this->assertSame( array( 'active', 'active' ), array_column( $this->db->passes, 'status' ) );

		$this->assertCount( 1, extrachill_events_guest_list_party_passes( $this->db->parties[0] ) );
		$this->assertCount( 0, extrachill_events_guest_list_party_passes( $this->db->parties[1] ) );
	}

	public function test_door_list_shows_each_guests_source(): void {
		$token = $this->invite( 'Slim S.O.U.L.' );
		extrachill_events_party_add_guest_list_name( $token, 'Jake' );
		extrachill_events_add_guest_list_name( self::EVENT_ID, 'Comp', '', self::HOST_ID );
		extrachill_events_issue_guest_list_pass( self::EVENT_ID, 'boogieman', 'Walk-up' );

		$rows = extrachill_events_door_list_guest_rows( self::EVENT_ID );

		$this->assertSame( array( 'Jake (Slim S.O.U.L.)', 'Comp', 'Walk-up' ), array_column( $rows, 'label' ) );
		$this->assertSame( array( 'Slim S.O.U.L.', '', '' ), array_column( $rows, 'source' ) );
		$this->assertSame( array( '', 'Lo-Fi Host', '' ), array_column( $rows, 'added_by' ), 'Host comps keep "Added by".' );

		// The verify page resolves the same source from the pass row.
		$this->assertSame( 'Slim S.O.U.L.', extrachill_events_guest_list_pass_party_name( $this->db->passes[0] ) );
		$this->assertSame( '', extrachill_events_guest_list_pass_party_name( $this->db->passes[1] ) );
	}

	public function test_door_list_drops_removed_guests_but_keeps_checked_in_ones(): void {
		$token = $this->invite();
		extrachill_events_party_add_guest_list_name( $token, 'Jake' );
		extrachill_events_party_add_guest_list_name( $token, 'Amy' );
		RsvpPassesTable::redeem_by_code( self::EVENT_ID, $this->db->passes[0]['code'], self::HOST_ID );
		extrachill_events_party_remove_guest_list_name( $token, $this->db->passes[1]['code'] );

		$rows = extrachill_events_door_list_guest_rows( self::EVENT_ID );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'redeemed', $rows[0]['status'] );
		$this->assertSame( 'Jake (Slim S.O.U.L.)', $rows[0]['label'] );
	}

	public function test_door_template_renders_the_label(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/templates/door-list.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level template read.

		$this->assertStringContainsString( "esc_html( (string) \$guest_row['label'] )", $source );
	}
}
