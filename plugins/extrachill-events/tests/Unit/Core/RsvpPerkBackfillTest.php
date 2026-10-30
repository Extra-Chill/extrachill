<?php
/**
 * RSVP perk backfill: enabling a perk issues passes to existing attendees (#920).
 *
 * Uses the real RsvpPassesTable over a small fake $wpdb, the real meta-hook
 * listener, and shims for the extrachill-users attendee API and the network
 * mail wrapper. Shims share global keys with sibling files in the
 * rsvp-perks-unit suite (`event_management_test` posts, `event_perk_test`
 * meta), so load order doesn't matter.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP shims and a fake $wpdb with the test case class.

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

if ( ! function_exists( 'ec_users_get_event_attendees_full' ) ) {
	function ec_users_get_event_attendees_full( int $event_id, int $blog_id = 0, int $limit = 200 ): array {
		unset( $blog_id, $limit );
		return $GLOBALS['rsvp_pass_mail_test']['attendees'][ $event_id ] ?? array();
	}
}

if ( ! function_exists( 'ec_send_email_queued' ) ) {
	function ec_send_email_queued( array $args ) {
		$GLOBALS['rsvp_pass_mail_test']['sent'][] = $args;
		return $GLOBALS['rsvp_pass_mail_test']['result'] ?? array( 'success' => true );
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/Core/RsvpPassesTable.php';
require_once dirname( __DIR__, 3 ) . '/inc/admin/event-perks.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/rsvp-pass-service.php';

/** Fake $wpdb for the RsvpPassesTable calls the backfill makes. */
final class RsvpPerkBackfillFakeWpdb {

	public $prefix = 'wp_';

	/** @var array<int, array<string, mixed>> */
	public $rows = array();

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

	public function get_row( $query, $output = null ) {
		unset( $output );
		if ( preg_match( '/WHERE event_id = (\d+) AND user_id = (\d+)/', $query, $match ) ) {
			foreach ( $this->rows as $row ) {
				if ( (int) $row['event_id'] === (int) $match[1] && (int) $row['user_id'] === (int) $match[2] ) {
					return $row;
				}
			}
		}
		return null;
	}

	public function insert( $table, $data ) {
		unset( $table );
		$data['id']   = $this->next_id++;
		$this->rows[] = $data;
		return 1;
	}

	public function update( $table, $data, $where ) {
		unset( $table );
		foreach ( $this->rows as $index => $row ) {
			if ( (int) $row['id'] === (int) $where['id'] ) {
				$this->rows[ $index ] = array_merge( $row, $data );
				return 1;
			}
		}
		return 0;
	}
}

final class RsvpPerkBackfillTest extends PHPUnit\Framework\TestCase {

	private const EVENT_ID = 486727;

	/** @var mixed */
	private $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new RsvpPerkBackfillFakeWpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated test double.

		$users = array();
		foreach ( array( 708, 1, 38, 711 ) as $id ) {
			$users[ $id ] = (object) array(
				'ID'           => $id,
				'user_email'   => "user{$id}@example.com",
				'display_name' => "User {$id}",
			);
		}

		$GLOBALS['rsvp_pass_mail_test'] = array(
			'sent'      => array(),
			'users'     => $users,
			'attendees' => array(
				self::EVENT_ID => array(
					array( 'user_id' => 708 ),
					array( 'user_id' => 1 ),
					array( 'user_id' => 38 ),
					array( 'user_id' => 711 ),
				),
			),
		);

		$GLOBALS['event_management_test']['posts'][ self::EVENT_ID ] = (object) array(
			'ID'         => self::EVENT_ID,
			'post_title' => 'Extra Chill Meetup',
			'post_type'  => 'data_machine_events',
		);

		$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ] = array(
			EXTRACHILL_EVENTS_PERK_ENABLED_META => true,
			EXTRACHILL_EVENTS_PERK_TEXT_META    => 'First beer is on Extra Chill.',
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the runtime database.
		unset(
			$GLOBALS['rsvp_pass_mail_test'],
			$GLOBALS['event_management_test']['posts'][ self::EVENT_ID ],
			$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ]
		);
	}

	/** @return array<int, string> */
	private function recipients(): array {
		return array_map(
			static fn( $args ) => $args['to'],
			$GLOBALS['rsvp_pass_mail_test']['sent']
		);
	}

	private function pass_for( int $user_id ): ?array {
		return RsvpPassesTable::find_for_user_event( self::EVENT_ID, $user_id );
	}

	public function test_enabling_the_perk_issues_and_emails_every_going_attendee(): void {
		do_action( 'added_post_meta', 1, self::EVENT_ID, EXTRACHILL_EVENTS_PERK_ENABLED_META, true );

		foreach ( array( 708, 1, 38, 711 ) as $user_id ) {
			$pass = $this->pass_for( $user_id );
			$this->assertNotNull( $pass, "user {$user_id} has a pass" );
			$this->assertSame( RsvpPassesTable::STATUS_ACTIVE, $pass['status'] );
		}

		$this->assertSame(
			array( 'user708@example.com', 'user1@example.com', 'user38@example.com', 'user711@example.com' ),
			$this->recipients()
		);
	}

	public function test_backfill_returns_the_issued_count(): void {
		$this->assertSame( 4, extrachill_events_backfill_rsvp_passes( self::EVENT_ID ) );
		$this->assertSame( 0, extrachill_events_backfill_rsvp_passes( self::EVENT_ID ) );
	}

	public function test_existing_active_pass_keeps_its_code_and_gets_no_second_email(): void {
		$existing = RsvpPassesTable::issue_or_reactivate( self::EVENT_ID, 708 );

		extrachill_events_backfill_rsvp_passes( self::EVENT_ID );

		$this->assertSame( $existing['code'], $this->pass_for( 708 )['code'] );
		$this->assertNotContains( 'user708@example.com', $this->recipients() );
		$this->assertCount( 3, $this->recipients() );
	}

	public function test_redeemed_pass_is_left_alone(): void {
		$pass = RsvpPassesTable::issue_or_reactivate( self::EVENT_ID, 38 );
		$GLOBALS['wpdb']->update( 'x', array( 'status' => RsvpPassesTable::STATUS_REDEEMED ), array( 'id' => $pass['id'] ) );

		extrachill_events_backfill_rsvp_passes( self::EVENT_ID );

		$this->assertSame( RsvpPassesTable::STATUS_REDEEMED, $this->pass_for( 38 )['status'] );
		$this->assertNotContains( 'user38@example.com', $this->recipients() );
	}

	public function test_revoked_pass_for_a_going_attendee_is_reactivated(): void {
		$pass = RsvpPassesTable::issue_or_reactivate( self::EVENT_ID, 711 );
		$GLOBALS['wpdb']->update( 'x', array( 'status' => RsvpPassesTable::STATUS_REVOKED ), array( 'id' => $pass['id'] ) );

		extrachill_events_backfill_rsvp_passes( self::EVENT_ID );

		$this->assertSame( RsvpPassesTable::STATUS_ACTIVE, $this->pass_for( 711 )['status'] );
		$this->assertNotSame( $pass['code'], $this->pass_for( 711 )['code'] );
		$this->assertContains( 'user711@example.com', $this->recipients() );
	}

	public function test_re_saving_an_enabled_perk_is_a_no_op(): void {
		do_action( 'added_post_meta', 1, self::EVENT_ID, EXTRACHILL_EVENTS_PERK_ENABLED_META, true );
		$codes                                  = array_map( fn( $id ) => $this->pass_for( $id )['code'], array( 708, 1, 38, 711 ) );
		$GLOBALS['rsvp_pass_mail_test']['sent'] = array();

		do_action( 'updated_post_meta', 1, self::EVENT_ID, EXTRACHILL_EVENTS_PERK_ENABLED_META, true );

		$this->assertSame( array(), $this->recipients() );
		$this->assertSame( $codes, array_map( fn( $id ) => $this->pass_for( $id )['code'], array( 708, 1, 38, 711 ) ) );
	}

	public function test_disabled_perk_issues_nothing(): void {
		unset( $GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ][ EXTRACHILL_EVENTS_PERK_ENABLED_META ] );

		$this->assertSame( 0, extrachill_events_backfill_rsvp_passes( self::EVENT_ID ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows );
	}

	public function test_other_meta_keys_and_falsy_values_do_not_trigger(): void {
		do_action( 'added_post_meta', 1, self::EVENT_ID, EXTRACHILL_EVENTS_PERK_TEXT_META, 'text' );
		do_action( 'updated_post_meta', 1, self::EVENT_ID, EXTRACHILL_EVENTS_PERK_ENABLED_META, '' );

		$this->assertSame( array(), $GLOBALS['wpdb']->rows );
		$this->assertSame( array(), $this->recipients() );
	}
}
