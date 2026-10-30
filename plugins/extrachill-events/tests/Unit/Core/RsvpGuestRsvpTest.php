<?php
/**
 * Email-only guest RSVP (extrachill-events#969).
 *
 * Covers the domain path a logged-out visitor takes: the account primitive
 * from extrachill-users (doubled here as an ability object), attendance via
 * ec_users_mark_event() (doubled; fires the real ec_users_event_marked
 * listener), the real pass email with the claim link, the opaque response,
 * and the bounded re-send for an attendee who is already marked.
 *
 * Shims share global keys with sibling files in the rsvp-perks-unit suite
 * (`event_management_test` posts, `event_perk_test` meta,
 * `rsvp_pass_mail_test` mail), so load order doesn't matter.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP function shims with the test case class.

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

if ( ! function_exists( 'ec_send_email_queued' ) ) {
	function ec_send_email_queued( array $args ) {
		$GLOBALS['rsvp_pass_mail_test']['sent'][] = $args;
		return $GLOBALS['rsvp_pass_mail_test']['result'] ?? array( 'success' => true );
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
	/**
	 * Double of extrachill-users' ec_users_mark_event(): records the call and
	 * the claim link visible at mark time, and fires ec_users_event_marked on
	 * a new mark exactly like the real service.
	 */
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

/** Minimal $wpdb: every attendee already holds this active pass. */
final class RsvpGuestFakeWpdb {
	public $prefix = 'c8c_7_';

	public function prepare( $query, ...$args ) {
		unset( $args );
		return $query;
	}

	public function get_row( $query, $output = null ) {
		unset( $query, $output );
		return array(
			'id'     => 1,
			'code'   => 'GUEST-PASS-CODE1',
			'status' => 'active',
		);
	}
}

/** Double of the extrachill-users resolve-account-by-email ability. */
final class RsvpGuestResolveAbility {
	/** @var array<int, array> */
	public array $calls = array();

	/** @var array|WP_Error */
	public $result;

	public function execute( array $input ) {
		$this->calls[] = $input;
		return $this->result;
	}
}

final class RsvpGuestRsvpTest extends PHPUnit\Framework\TestCase {

	private const EVENT_ID = 486727;
	private const USER_ID  = 9001;

	private RsvpGuestResolveAbility $ability;

	/** @var mixed */
	private $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new RsvpGuestFakeWpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated test double.

		$this->ability         = new RsvpGuestResolveAbility();
		$this->ability->result = array(
			'user_id'         => self::USER_ID,
			'created'         => true,
			'unclaimed'       => true,
			'claim_url'       => 'https://community.extrachill.com/reset-password/?action=reset&key=k&login=guest',
			'claim_throttled' => false,
		);
		$ability                            = $this->ability;
		$GLOBALS['ec_test_ability_resolver'] = static function ( $name ) use ( $ability ) {
			return 'extrachill/resolve-account-by-email' === $name ? $ability : null;
		};

		$GLOBALS['rsvp_guest_test']     = array(
			'marks'         => array(),
			'claim_at_mark' => array(),
			'marked'        => array(),
			'transients'    => array(),
		);
		$GLOBALS['rsvp_pass_mail_test'] = array(
			'sent'   => array(),
			'result' => array( 'success' => true ),
			'users'  => array(
				self::USER_ID => (object) array(
					'ID'           => self::USER_ID,
					'user_email'   => 'guest@example.com',
					'display_name' => 'guest',
				),
			),
		);

		$GLOBALS['event_management_test']['posts'][ self::EVENT_ID ] = (object) array(
			'ID'          => self::EVENT_ID,
			'post_title'  => 'WordPress Meetup Charleston',
			'post_type'   => 'data_machine_events',
			'post_status' => 'publish',
		);
		$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ]        = array(
			'_extrachill_event_perk_enabled' => true,
			'_extrachill_event_perk_text'    => 'First beer is on Extra Chill.',
		);

		// Exactly one pass listener, whatever earlier files registered.
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

	private function rsvp( string $email = 'guest@example.com', array $attribution = array() ) {
		return extrachill_events_guest_rsvp( self::EVENT_ID, $email, $attribution );
	}

	public function test_new_guest_gets_one_email_with_pass_and_claim_link(): void {
		$result = $this->rsvp( 'guest@example.com', array( 'referrer' => 'https://instagram.com/' ) );

		$this->assertSame( array( 'status' => 'rsvped' ), $result );
		$this->assertSame( 'guest@example.com', $this->ability->calls[0]['email'] );
		$this->assertSame( 'event_rsvp', $this->ability->calls[0]['registration_source'] );
		$this->assertSame( 'https://instagram.com/', $this->ability->calls[0]['referrer'] );
		$this->assertSame( array( array( self::USER_ID, self::EVENT_ID, 7 ) ), $GLOBALS['rsvp_guest_test']['marks'] );

		$sent = $GLOBALS['rsvp_pass_mail_test']['sent'];
		$this->assertCount( 1, $sent );
		$body = $sent[0]['context']['body_html'];
		$this->assertStringContainsString( 'GUEST-PASS-CODE1', $body );
		$this->assertStringContainsString( 'reset-password/?action=reset', $body );
		$this->assertStringContainsString( 'Set my password', $body );
	}

	public function test_response_never_reveals_account_or_pass(): void {
		$this->ability->result['created'] = false;

		$result = $this->rsvp();

		$this->assertSame( array( 'status' => 'rsvped' ), $result );
	}

	public function test_claimed_account_email_has_no_claim_link(): void {
		$this->ability->result['unclaimed'] = false;
		$this->ability->result['claim_url'] = '';

		$this->rsvp();

		$body = $GLOBALS['rsvp_pass_mail_test']['sent'][0]['context']['body_html'];
		$this->assertStringContainsString( 'GUEST-PASS-CODE1', $body );
		$this->assertStringNotContainsString( 'Set my password', $body );
	}

	public function test_claim_link_is_scoped_to_the_guest_request(): void {
		$this->rsvp();

		$this->assertSame( $this->ability->result['claim_url'], $GLOBALS['rsvp_guest_test']['claim_at_mark'][0] );
		$this->assertSame( '', extrachill_events_rsvp_claim_url_handoff( self::USER_ID ) );
	}

	public function test_logged_in_mark_email_has_no_claim_link(): void {
		do_action( 'ec_users_event_marked', self::USER_ID, self::EVENT_ID, 7 );

		$body = $GLOBALS['rsvp_pass_mail_test']['sent'][0]['context']['body_html'];
		$this->assertStringNotContainsString( 'Set my password', $body );
	}

	public function test_repeat_rsvp_resends_once_per_window(): void {
		$this->rsvp();
		$this->assertCount( 1, $GLOBALS['rsvp_pass_mail_test']['sent'] );

		// Already marked, inside the window: no extra mail.
		$this->assertSame( array( 'status' => 'rsvped' ), $this->rsvp() );
		$this->assertCount( 1, $GLOBALS['rsvp_pass_mail_test']['sent'] );

		// Window elapsed: the lost-email path re-sends the pass.
		$GLOBALS['rsvp_guest_test']['transients'] = array();
		$this->rsvp();
		$this->assertCount( 2, $GLOBALS['rsvp_pass_mail_test']['sent'] );
		$this->assertStringContainsString( 'GUEST-PASS-CODE1', $GLOBALS['rsvp_pass_mail_test']['sent'][1]['context']['body_html'] );
	}

	public function test_invalid_email_is_rejected_before_any_account_work(): void {
		$result = $this->rsvp( 'not-an-email' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_email', $result->get_error_code() );
		$this->assertSame( array(), $this->ability->calls );
	}

	public function test_event_without_perk_is_unavailable(): void {
		$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ]['_extrachill_event_perk_enabled'] = false;

		$result = $this->rsvp();

		$this->assertSame( 'guest_rsvp_unavailable', $result->get_error_code() );
		$this->assertSame( array(), $this->ability->calls );
	}

	public function test_unpublished_event_is_not_found(): void {
		$GLOBALS['event_management_test']['posts'][ self::EVENT_ID ]->post_status = 'draft';

		$this->assertSame( 'event_not_found', $this->rsvp()->get_error_code() );
	}

	public function test_account_failure_is_generic(): void {
		$this->ability->result = new WP_Error( 'user_creation_failed', 'db exploded for guest@example.com' );

		$result = $this->rsvp();

		$this->assertSame( 'guest_rsvp_failed', $result->get_error_code() );
		$this->assertStringNotContainsString( 'guest@example.com', $result->get_error_message() );
		$this->assertSame( array(), $GLOBALS['rsvp_guest_test']['marks'] );
	}
}
