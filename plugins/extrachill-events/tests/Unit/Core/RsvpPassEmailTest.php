<?php
/**
 * RSVP pass email: plain-text title and visible failures (#897).
 *
 * Event titles are stored with HTML entities, so the subject showed a
 * literal `&amp;` and the body, which escapes again, showed `&amp;amp;`. A refused
 * queued send was also discarded silently. System-context sending itself
 * belongs to ec_send_email_queued() (extrachill-network#318), so this file
 * only checks what extrachill-events owns: what it hands to that wrapper
 * and what it does with the result.
 *
 * Shims share global keys with sibling files in the rsvp-perks-unit suite
 * (`event_management_test` posts, `event_perk_test` meta), so load order
 * doesn't matter.
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

if ( ! function_exists( 'ec_send_email_queued' ) ) {
	function ec_send_email_queued( array $args ) {
		$GLOBALS['rsvp_pass_mail_test']['sent'][] = $args;
		return $GLOBALS['rsvp_pass_mail_test']['result'] ?? array( 'success' => true );
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/admin/event-perks.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/rsvp-pass-service.php';

final class RsvpPassEmailTest extends PHPUnit\Framework\TestCase {

	private const EVENT_ID = 486727;
	private const USER_ID  = 708;

	protected function setUp(): void {
		$GLOBALS['rsvp_pass_mail_test'] = array(
			'sent'   => array(),
			'result' => array( 'success' => true ),
			'users'  => array(
				self::USER_ID => (object) array(
					'ID'           => self::USER_ID,
					'user_email'   => 'attendee@example.com',
					'display_name' => 'Chase',
				),
			),
		);

		$GLOBALS['event_management_test']['posts'][ self::EVENT_ID ] = (object) array(
			'ID'         => self::EVENT_ID,
			'post_title' => 'Extra Chill &amp; WordPress Meetup: Building Your Online Presence',
			'post_type'  => 'data_machine_events',
		);

		$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ] = array(
			'_extrachill_event_perk_enabled' => true,
			'_extrachill_event_perk_text'    => 'First beer is on Extra Chill.',
		);

		$GLOBALS['ec_artist_test']['fired_actions'] = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['rsvp_pass_mail_test'],
			$GLOBALS['event_management_test']['posts'][ self::EVENT_ID ],
			$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ],
			$GLOBALS['ec_artist_test']['fired_actions']
		);
	}

	private function send(): void {
		extrachill_events_send_rsvp_pass_email( self::EVENT_ID, self::USER_ID, array( 'code' => 'DL4QD-754DX-UMMEH' ) );
	}

	/** @return array<int, array> */
	private function logged_errors(): array {
		return $GLOBALS['ec_artist_test']['fired_actions']['datamachine_log'] ?? array();
	}

	public function test_subject_uses_the_decoded_title(): void {
		$this->send();

		$sent = $GLOBALS['rsvp_pass_mail_test']['sent'];
		$this->assertCount( 1, $sent );
		$this->assertSame( 'Your RSVP pass for Extra Chill & WordPress Meetup: Building Your Online Presence', $sent[0]['subject'] );
		$this->assertStringNotContainsString( '&amp;', $sent[0]['subject'] );
	}

	public function test_body_escapes_the_title_exactly_once(): void {
		$this->send();

		$body = $GLOBALS['rsvp_pass_mail_test']['sent'][0]['context']['body_html'];
		$this->assertStringContainsString( 'Extra Chill &amp; WordPress Meetup', $body );
		$this->assertStringNotContainsString( '&amp;amp;', $body );
		$this->assertStringContainsString( 'DL4QD-754DX-UMMEH', $body );
		$this->assertStringContainsString( 'First beer is on Extra Chill.', $body );
	}

	public function test_successful_send_logs_nothing(): void {
		$this->send();

		$this->assertSame( array(), $this->logged_errors() );
	}

	public function test_refused_send_is_logged_without_the_address(): void {
		$GLOBALS['rsvp_pass_mail_test']['result'] = array(
			'success'    => false,
			'error'      => 'An authorized mailbox ref is required to queue email.',
			'error_code' => 'email_queue_mailbox_required',
		);

		$this->send();

		$logs = $this->logged_errors();
		$this->assertCount( 1, $logs );
		$this->assertSame( 'error', $logs[0][0] );
		$this->assertSame( self::EVENT_ID, $logs[0][2]['event_id'] );
		$this->assertSame( self::USER_ID, $logs[0][2]['user_id'] );
		$this->assertSame( 'email_queue_mailbox_required', $logs[0][2]['error_code'] );
		$this->assertStringNotContainsString( 'attendee@example.com', (string) wp_json_encode_for_test( $logs ) );
	}

	public function test_result_success_detection(): void {
		$this->assertTrue( extrachill_events_rsvp_pass_email_queued( array( 'success' => true ) ) );
		$this->assertFalse( extrachill_events_rsvp_pass_email_queued( array( 'success' => false ) ) );
		$this->assertFalse( extrachill_events_rsvp_pass_email_queued( array() ) );
		$this->assertFalse( extrachill_events_rsvp_pass_email_queued( null ) );
		$this->assertFalse( extrachill_events_rsvp_pass_email_queued( new WP_Error( 'x', 'y' ) ) );
	}

	public function test_title_helper_decodes_quotes_and_ampersands(): void {
		$post = (object) array( 'post_title' => 'Rock &#8217;n&#8217; Roll &amp; &quot;More&quot;' );

		$this->assertSame( "Rock \u{2019}n\u{2019} Roll & \"More\"", extrachill_events_rsvp_pass_event_title( $post ) );
	}
}

/**
 * JSON-encode for assertions without depending on wp_json_encode().
 *
 * @param mixed $value Value.
 * @return string|false
 */
function wp_json_encode_for_test( $value ) {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only helper; no WP loaded.
}
