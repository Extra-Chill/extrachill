<?php
/**
 * Band spot requests and submitted lists, decided by the venue (extrachill#1129).
 *
 * Covers: a pending (submitted) name never redeems and holds no spot;
 * approving a list activates it under the capacity lock; denying keeps
 * it dead; only event managers decide; approving a "more spots" ask grows
 * the allotment; an uninvited request is inert until the venue accepts it
 * (then it is a normal party with a mailed link); and the public form's
 * guards (required email, one open request per email, rate limits).
 *
 * Reuses the shims and the stateful $wpdb double from
 * RsvpGuestListPartiesTest.php.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP function shims with the test case class.

use ExtraChillEvents\Core\GuestListPartiesTable;
use ExtraChillEvents\Core\RsvpPassesTable;

require_once __DIR__ . '/RsvpGuestListPartiesTest.php';

if ( ! function_exists( 'user_can' ) ) {
	// Same shape as EventManagementAuthorityTest's stub, which loads first in the suite.
	function user_can( $user_id, $capability, ...$args ) {
		if ( 'edit_post' === $capability ) {
			$post_id = $args[0] ?? 0;
			return in_array( (int) $post_id, $GLOBALS['event_management_test']['editable_posts'][ $user_id ] ?? array(), true );
		}
		return in_array( $capability, $GLOBALS['event_management_test']['caps'][ $user_id ] ?? array(), true );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default_value = false ) {
		return $GLOBALS['event_management_test']['options'][ $key ] ?? $default_value;
	}
}

if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy ) {
		return $GLOBALS['event_management_test']['terms'][ $post_id ][ $taxonomy ] ?? array();
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return 7;
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		return trim( strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stub.
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

require_once dirname( __DIR__, 3 ) . '/inc/core/event-management-authority.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/guest-list-requests.php';

final class RsvpGuestListRequestsTest extends PHPUnit\Framework\TestCase {

	private const EVENT_ID     = 640350;
	private const HOST_ID      = 38;
	private const STRANGER_ID  = 99;
	private const REQUESTER_IP = '203.0.113.9';

	private RsvpGuestListPartiesFakeWpdb $db;

	/** @var mixed */
	private $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db            = new RsvpGuestListPartiesFakeWpdb();
		$GLOBALS['wpdb']     = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated test double.

		$GLOBALS['rsvp_pass_mail_test'] = array(
			'sent'   => array(),
			'result' => array( 'success' => true ),
			'users'  => array(
				self::HOST_ID => (object) array(
					'ID'           => self::HOST_ID,
					'user_email'   => 'host@example.com',
					'display_name' => 'Lo-Fi Host',
				),
			),
		);

		$GLOBALS['rsvp_guest_test']['transients']                            = array();
		$GLOBALS['event_management_test']['editable_posts'][ self::HOST_ID ] = array( self::EVENT_ID );

		$GLOBALS['event_management_test']['posts'][ self::EVENT_ID ] = (object) array(
			'ID'          => self::EVENT_ID,
			'post_title'  => 'Boogie Man After Party',
			'post_type'   => 'data_machine_events',
			'post_status' => 'publish',
			'post_author' => self::HOST_ID,
		);
		$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ]        = array(
			'_extrachill_event_perk_enabled' => true,
			'_extrachill_event_perk_text'    => 'Extra Chill guest list',
		);
		extrachill_events_set_perk_password( self::EVENT_ID, 'boogieman' );
		extrachill_events_set_perk_capacity( self::EVENT_ID, 10 );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the runtime database.
		unset(
			$GLOBALS['rsvp_pass_mail_test'],
			$GLOBALS['rsvp_guest_test']['transients'],
			$GLOBALS['event_management_test']['editable_posts'][ self::HOST_ID ],
			$GLOBALS['event_management_test']['posts'][ self::EVENT_ID ],
			$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ]
		);
	}

	/**
	 * Invite a party and return its raw token.
	 *
	 * @param int|null $allotment Allotment.
	 * @return string
	 */
	private function invite( ?int $allotment = null ): string {
		$result = extrachill_events_create_guest_list_party( self::EVENT_ID, 'Slim S.O.U.L.', 'mgmt@example.com', $allotment, self::HOST_ID );
		$this->assertIsArray( $result );
		$this->assertSame( 1, preg_match( '#/guest-invite/([a-f0-9]{64})/$#', $result['invite_url'], $m ) );
		$GLOBALS['rsvp_pass_mail_test']['sent'] = array();
		return $m[1];
	}

	/** @return string[] */
	private function sent_to(): array {
		return array_column( $GLOBALS['rsvp_pass_mail_test']['sent'], 'to' );
	}

	private function claimed(): int {
		return (int) RsvpPassesTable::count_for_event( self::EVENT_ID )['claimed'];
	}

	public function test_a_submitted_name_is_pending_holds_no_spot_and_does_not_redeem(): void {
		$token  = $this->invite();
		$result = extrachill_events_party_submit_guest_list( $token, "Jake\nAmy\n\nJake" );

		$this->assertSame( 2, $result['submitted'], 'Blank lines and repeats are dropped.' );
		$this->assertSame( array( 'pending', 'pending' ), array_column( $this->db->passes, 'status' ) );
		$this->assertSame( 0, $this->claimed(), 'Pending names hold no spot.' );
		$this->assertSame( array(), extrachill_events_door_list_guest_rows( self::EVENT_ID ), 'Pending names are not on the door list.' );

		$redeem = RsvpPassesTable::redeem_by_code( self::EVENT_ID, $this->db->passes[0]['code'], self::HOST_ID );
		$this->assertInstanceOf( WP_Error::class, $redeem );
		$this->assertSame( 'rsvp_pass_not_approved', $redeem->get_error_code() );
		$this->assertStringStartsWith( 'Not on the list', $redeem->get_error_message() );
		$this->assertSame( 'pending', $this->db->passes[0]['status'] );

		// The venue's managers hear about it.
		$this->assertSame( array( 'host@example.com' ), $this->sent_to() );
	}

	public function test_approving_a_list_activates_it(): void {
		extrachill_events_set_guest_list_allotments( self::EVENT_ID, true );
		$token = $this->invite( 1 );
		extrachill_events_party_submit_guest_list( $token, array( 'Jake', 'Amy', 'Rae' ) );
		$party_id = (int) $this->db->parties[0]['id'];

		$requests = extrachill_events_list_guest_list_requests( self::EVENT_ID )['requests'];
		$this->assertCount( 1, $requests );
		$this->assertSame( 'list', $requests[0]['kind'] );
		$this->assertSame( array( 'Jake', 'Amy', 'Rae' ), $requests[0]['names'] );

		$GLOBALS['rsvp_pass_mail_test']['sent'] = array();
		$this->db->lock_calls                   = array();
		$approved                               = extrachill_events_approve_guest_list_request( self::EVENT_ID, $party_id, '', null, self::HOST_ID );

		$this->assertTrue( $approved['approved'] );
		$this->assertSame( 'list', $approved['kind'] );
		$this->assertSame( 3, $approved['activated'] );
		$this->assertSame( array( 'active', 'active', 'active' ), array_column( $this->db->passes, 'status' ) );
		$this->assertSame( array( 'get', 'release' ), $this->db->lock_calls, 'Activation runs under the capacity lock.' );
		$this->assertSame( 3, $approved['party']['allotment'], 'The allotment grows to cover the approved names.' );
		$this->assertSame( array( 'mgmt@example.com' ), $this->sent_to(), 'The band hears back.' );

		$this->assertIsArray( RsvpPassesTable::redeem_by_code( self::EVENT_ID, $this->db->passes[0]['code'], self::HOST_ID ) );
		$this->assertSame( 'redeemed', $this->db->passes[0]['status'] );
		$this->assertSame( array(), extrachill_events_list_guest_list_requests( self::EVENT_ID )['requests'] );
	}

	public function test_a_list_that_does_not_fit_the_event_stays_pending(): void {
		extrachill_events_set_perk_capacity( self::EVENT_ID, 2 );
		$token = $this->invite();
		extrachill_events_party_submit_guest_list( $token, array( 'Jake', 'Amy', 'Rae' ) );

		$result = extrachill_events_approve_guest_list_request( self::EVENT_ID, (int) $this->db->parties[0]['id'], 'list', null, self::HOST_ID );

		$this->assertSame( 'guest_list_full', $result->get_error_code() );
		$this->assertSame( array( 'pending', 'pending', 'pending' ), array_column( $this->db->passes, 'status' ), 'All or nothing.' );
	}

	public function test_denying_a_list_keeps_it_dead(): void {
		$token = $this->invite();
		extrachill_events_party_submit_guest_list( $token, array( 'Jake', 'Amy' ) );
		$party_id = (int) $this->db->parties[0]['id'];

		$GLOBALS['rsvp_pass_mail_test']['sent'] = array();
		$denied                                 = extrachill_events_deny_guest_list_request( self::EVENT_ID, $party_id, 'list', self::HOST_ID );

		$this->assertTrue( $denied['denied'] );
		$this->assertSame( 2, $denied['denied_names'] );
		$this->assertTrue( $denied['emailed'] );
		$this->assertSame( array( 'mgmt@example.com' ), $this->sent_to() );
		$this->assertSame( array( 'denied', 'denied' ), array_column( $this->db->passes, 'status' ) );

		$redeem = RsvpPassesTable::redeem_by_code( self::EVENT_ID, $this->db->passes[0]['code'], self::HOST_ID );
		$this->assertSame( 'rsvp_pass_not_approved', $redeem->get_error_code() );
		$this->assertSame( 'guest_list_request_not_found', extrachill_events_approve_guest_list_request( self::EVENT_ID, $party_id, 'list', null, self::HOST_ID )->get_error_code(), 'A denied list cannot be approved later.' );
		$this->assertSame( array( 'denied', 'denied' ), array_column( $this->db->passes, 'status' ) );
		$this->assertSame( 0, $this->claimed() );
	}

	public function test_only_event_managers_approve_or_deny(): void {
		extrachill_events_set_guest_list_allotments( self::EVENT_ID, true );
		$token = $this->invite( 2 );
		extrachill_events_party_submit_guest_list( $token, array( 'Jake' ) );
		extrachill_events_party_request_more_spots( $token, 3 );
		$party_id = (int) $this->db->parties[0]['id'];

		foreach ( array( 0, self::STRANGER_ID ) as $user_id ) {
			$this->assertSame( 'event_door_list_forbidden', extrachill_events_approve_guest_list_request( self::EVENT_ID, $party_id, 'list', null, $user_id )->get_error_code() );
			$this->assertSame( 'event_door_list_forbidden', extrachill_events_approve_guest_list_request( self::EVENT_ID, $party_id, 'spots', null, $user_id )->get_error_code() );
			$this->assertSame( 'event_door_list_forbidden', extrachill_events_deny_guest_list_request( self::EVENT_ID, $party_id, 'list', $user_id )->get_error_code() );
		}

		$this->assertSame( 'pending', $this->db->passes[0]['status'] );
		$this->assertSame( 2, (int) $this->db->parties[0]['allotment'] );
		$this->assertSame( 3, (int) $this->db->parties[0]['requested_spots'] );

		// Two requests waiting: the host must say which.
		$this->assertSame( 'guest_list_request_kind_required', extrachill_events_approve_guest_list_request( self::EVENT_ID, $party_id, '', null, self::HOST_ID )->get_error_code() );
		// Another event's manager cannot reach this party.
		$this->assertSame( 'event_door_list_forbidden', extrachill_events_approve_guest_list_request( 999, $party_id, 'list', null, self::HOST_ID )->get_error_code() );
	}

	public function test_approving_a_more_spots_ask_increases_the_allotment(): void {
		$token = $this->invite( 2 );
		$this->assertSame( 'allotments_off', extrachill_events_party_request_more_spots( $token, 3 )->get_error_code(), 'No per-band limit, nothing to ask for.' );

		extrachill_events_set_guest_list_allotments( self::EVENT_ID, true );
		$this->assertSame( 'invalid_spots', extrachill_events_party_request_more_spots( $token, 0 )->get_error_code() );
		$this->assertSame( 3, extrachill_events_party_request_more_spots( $token, 3, 'Horn section' )['requested_spots'] );
		$this->assertSame( array( 'host@example.com' ), $this->sent_to() );

		$party_id = (int) $this->db->parties[0]['id'];
		$requests = extrachill_events_list_guest_list_requests( self::EVENT_ID )['requests'];
		$this->assertSame( 'spots', $requests[0]['kind'] );
		$this->assertSame( 3, $requests[0]['requested_spots'] );
		$this->assertSame( 'Horn section', $requests[0]['note'] );

		$approved = extrachill_events_approve_guest_list_request( self::EVENT_ID, $party_id, 'spots', null, self::HOST_ID );
		$this->assertSame( 5, $approved['party']['allotment'], 'Allotment grows by the requested amount.' );
		$this->assertNull( $approved['party']['requested_spots'] );
		$this->assertSame( self::HOST_ID, $this->db->parties[0]['decided_by_user_id'] );

		// A host can grant fewer than asked.
		extrachill_events_party_request_more_spots( $token, 4 );
		$this->assertSame( 6, extrachill_events_approve_guest_list_request( self::EVENT_ID, $party_id, 'spots', 1, self::HOST_ID )['party']['allotment'] );

		// Denying leaves the allotment as it was.
		extrachill_events_party_request_more_spots( $token, 4 );
		$this->assertTrue( extrachill_events_deny_guest_list_request( self::EVENT_ID, $party_id, 'spots', self::HOST_ID )['denied'] );
		$this->assertSame( 6, (int) $this->db->parties[0]['allotment'] );
		$this->assertNull( $this->db->parties[0]['requested_spots'] );
		$this->assertSame( 'guest_list_request_not_found', extrachill_events_approve_guest_list_request( self::EVENT_ID, $party_id, 'spots', null, self::HOST_ID )->get_error_code() );

		// The new spots are usable right away.
		foreach ( range( 1, 6 ) as $n ) {
			$this->assertIsArray( extrachill_events_party_add_guest_list_name( $token, 'Guest ' . $n ) );
		}
		$this->assertSame( 'party_allotment_full', extrachill_events_party_add_guest_list_name( $token, 'Guest 7' )->get_error_code() );
	}

	public function test_an_uninvited_request_is_inert_until_accepted(): void {
		$result = extrachill_events_request_guest_list_party( self::EVENT_ID, 'Druzin Mgmt', 'druzin@example.com', 2, "Sam\nLee", 'Opening act', self::REQUESTER_IP );

		$this->assertTrue( $result['requested'] );
		$this->assertSame( 2, $result['requested_spots'] );
		$party = $this->db->parties[0];
		$this->assertSame( 'pending', $party['status'] );
		$this->assertSame( 'request', $party['origin'] );
		$this->assertSame( array( 'pending', 'pending' ), array_column( $this->db->passes, 'status' ) );
		$this->assertSame( 0, $this->claimed() );
		$this->assertSame( array( 'host@example.com' ), $this->sent_to(), 'Only the venue is emailed; no link goes to the requester.' );
		$this->assertSame( 'rsvp_pass_not_approved', RsvpPassesTable::redeem_by_code( self::EVENT_ID, $this->db->passes[0]['code'], self::HOST_ID )->get_error_code() );
		$this->assertSame( 'party_not_revocable', extrachill_events_revoke_guest_list_party( self::EVENT_ID, (int) $party['id'] )->get_error_code(), 'Not a party yet.' );

		$requests = extrachill_events_list_guest_list_requests( self::EVENT_ID )['requests'];
		$this->assertCount( 1, $requests );
		$this->assertSame( 'party', $requests[0]['kind'] );
		$this->assertSame( array( 'Sam', 'Lee' ), $requests[0]['names'] );

		$this->assertSame( 'event_door_list_forbidden', extrachill_events_approve_guest_list_request( self::EVENT_ID, (int) $party['id'], 'party', null, self::STRANGER_ID )->get_error_code() );
		$this->assertSame( 'pending', $this->db->parties[0]['status'] );

		$GLOBALS['rsvp_pass_mail_test']['sent'] = array();
		$accepted                               = extrachill_events_approve_guest_list_request( self::EVENT_ID, (int) $party['id'], 'party', null, self::HOST_ID );

		$this->assertSame( 'party', $accepted['kind'] );
		$this->assertSame( 2, $accepted['activated'] );
		$this->assertTrue( $accepted['emailed'] );
		$this->assertSame( 'active', $this->db->parties[0]['status'] );
		$this->assertSame( array( 'active', 'active' ), array_column( $this->db->passes, 'status' ) );
		$this->assertNotSame( $party['token_hash'], $this->db->parties[0]['token_hash'], 'A fresh link is minted on accept.' );

		$sent = $GLOBALS['rsvp_pass_mail_test']['sent'];
		$this->assertSame( array( 'druzin@example.com' ), array_column( $sent, 'to' ) );
		$this->assertSame( 1, preg_match( '#/guest-invite/([a-f0-9]{64})/$#', $accepted['invite_url'], $m ) );
		$this->assertStringContainsString( $m[1], $sent[0]['context']['cta_url'] );

		// Now it is an ordinary party: the link works.
		$this->assertIsArray( extrachill_events_party_add_guest_list_name( $m[1], 'Kim' ) );
		$this->assertCount( 3, extrachill_events_door_list_guest_rows( self::EVENT_ID ) );
	}

	public function test_denying_an_uninvited_request_emails_the_requester(): void {
		extrachill_events_request_guest_list_party( self::EVENT_ID, 'Druzin Mgmt', 'druzin@example.com', 2, 'Sam', '', self::REQUESTER_IP );
		$party_id = (int) $this->db->parties[0]['id'];

		$GLOBALS['rsvp_pass_mail_test']['sent'] = array();
		$denied                                 = extrachill_events_deny_guest_list_request( self::EVENT_ID, $party_id, '', self::HOST_ID );

		$this->assertSame( 'party', $denied['kind'] );
		$this->assertSame( 1, $denied['denied_names'] );
		$this->assertSame( array( 'druzin@example.com' ), $this->sent_to() );
		$this->assertSame( 'denied', $this->db->parties[0]['status'] );
		$this->assertSame( 'denied', $this->db->passes[0]['status'] );
		$this->assertSame( 'guest_list_request_not_found', extrachill_events_approve_guest_list_request( self::EVENT_ID, $party_id, 'party', null, self::HOST_ID )->get_error_code() );
		$this->assertSame( array(), extrachill_events_list_guest_list_requests( self::EVENT_ID )['requests'] );
	}

	public function test_public_request_guards(): void {
		$this->assertSame( 'invalid_email', extrachill_events_request_guest_list_party( self::EVENT_ID, 'Band', '', 2 )->get_error_code(), 'Email is required.' );
		$this->assertSame( 'party_name_required', extrachill_events_request_guest_list_party( self::EVENT_ID, ' ', 'a@example.com', 2 )->get_error_code() );
		$this->assertSame( 'invalid_spots', extrachill_events_request_guest_list_party( self::EVENT_ID, 'Band', 'a@example.com', 0 )->get_error_code() );

		$this->assertIsArray( extrachill_events_request_guest_list_party( self::EVENT_ID, 'Band', 'a@example.com', 2, '', '', self::REQUESTER_IP ) );
		$this->assertSame( 'guest_list_request_exists', extrachill_events_request_guest_list_party( self::EVENT_ID, 'Band', 'A@example.com', 2, '', '', self::REQUESTER_IP )->get_error_code(), 'One open request per email.' );

		// Per-IP limit.
		foreach ( range( 2, EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_IP_LIMIT ) as $n ) {
			$this->assertIsArray( extrachill_events_request_guest_list_party( self::EVENT_ID, 'Band ' . $n, "b{$n}@example.com", 2, '', '', self::REQUESTER_IP ) );
		}
		$this->assertSame( 'guest_list_request_rate_limited', extrachill_events_request_guest_list_party( self::EVENT_ID, 'Band X', 'x@example.com', 2, '', '', self::REQUESTER_IP )->get_error_code() );
		$this->assertCount( EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_IP_LIMIT, $this->db->parties );

		// No guest list, no requests.
		extrachill_events_set_perk_password( self::EVENT_ID, '' );
		$this->assertSame( 'guest_list_unavailable', extrachill_events_request_guest_list_party( self::EVENT_ID, 'Band Y', 'y@example.com', 2, '', '', '198.51.100.1' )->get_error_code() );
	}

	public function test_revoking_a_party_kills_its_waiting_names(): void {
		$token = $this->invite();
		extrachill_events_party_add_guest_list_name( $token, 'Jake' );
		extrachill_events_party_submit_guest_list( $token, array( 'Amy' ) );

		$this->assertTrue( extrachill_events_revoke_guest_list_party( self::EVENT_ID, (int) $this->db->parties[0]['id'] )['revoked'] );
		$this->assertSame( array( 'active', 'denied' ), array_column( $this->db->passes, 'status' ), 'Names already on the list stay; waiting ones die.' );
	}

	public function test_a_band_can_withdraw_a_waiting_name(): void {
		$token = $this->invite();
		extrachill_events_party_submit_guest_list( $token, array( 'Amy' ) );

		$this->assertTrue( extrachill_events_party_remove_guest_list_name( $token, $this->db->passes[0]['code'] )['removed'] );
		$this->assertSame( 'revoked', $this->db->passes[0]['status'] );
		$this->assertSame( array(), extrachill_events_list_guest_list_requests( self::EVENT_ID )['requests'] );
	}

	public function test_templates_wire_the_request_forms(): void {
		$root   = dirname( __DIR__, 3 );
		$panel  = (string) file_get_contents( $root . '/templates/guest-list-management.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level template read.
		$public = (string) file_get_contents( $root . '/inc/templates/guest-list.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level template read.
		$verify = (string) file_get_contents( $root . '/inc/templates/rsvp-verify.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level template read.

		$this->assertStringContainsString( 'Pending requests', $panel );
		$this->assertStringContainsString( 'ec_guest_request_approve', $panel );
		$this->assertStringContainsString( 'ec_guest_request_deny', $panel );
		$this->assertStringContainsString( 'ec_guest_party_request', $public );
		$this->assertStringContainsString( "wp_nonce_field( 'ec_guest_party_request_'", $public );
		$this->assertStringContainsString( 'name="website"', $public, 'Honeypot field.' );
		$this->assertStringContainsString( "'pending' === \$pass_status", $verify );
	}
}
