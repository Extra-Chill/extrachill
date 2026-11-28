<?php
/**
 * Cookieless account outcomes use server-owned account evidence.
 *
 * @package ExtraChill\Analytics
 */
require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

final class AccountOutcomeClassificationTest extends Extrachill_Analytics_TestCase {
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );
		unset( $_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ], $GLOBALS['extrachill_analytics_bot_evidence'] );
	}

	public function tear_down(): void {
		unset( $GLOBALS['extrachill_analytics_bot_evidence'] );
		parent::tear_down();
	}

	private function record( string $type, array $data ): array {
		global $wpdb;
		$id = extrachill_analytics_ability_track_event( array( 'event_type' => $type, 'event_data' => $data ) );
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . extrachill_analytics_events_table() . ' WHERE id = %d', $id ), ARRAY_A );
		$row['event_data'] = json_decode( $row['event_data'], true );
		return $row;
	}

	public function test_cookieless_google_oauth_registration(): void {
		$user_id = self::factory()->user->create();
		$row = $this->record( 'user_registration', array( 'user_id' => $user_id, 'method' => 'google' ) );
		$this->assertFalse( $row['event_data']['is_bot'] );
		$this->assertSame( $user_id, (int) $row['user_id'] );
		$this->assertNull( $row['visitor_id'] );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_cookieless_email_only_registration_and_digest(): void {
		$user_id = self::factory()->user->create();
		foreach ( array( 'user_registration', 'local_scene_digest_subscribed' ) as $type ) {
			$row = $this->record( $type, array( 'user_id' => $user_id, 'method' => 'email', 'via' => 'guest' ) );
			$this->assertFalse( $row['event_data']['is_bot'] );
			$this->assertSame( $user_id, (int) $row['user_id'] );
			$this->assertNull( $row['visitor_id'] );
		}
	}

	public function test_cookieless_turnstile_rejection_has_explicit_reason(): void {
		$user_id = self::factory()->user->create();
		do_action( 'extrachill_turnstile_rejected', 'missing_token', '' );
		foreach ( array( 'user_registration', 'local_scene_digest_subscribed' ) as $type ) {
			$row = $this->record( $type, array( 'user_id' => $user_id, 'is_bot' => false ) );
			$this->assertTrue( $row['event_data']['is_bot'] );
			$this->assertSame( 'turnstile_rejected', $row['event_data']['bot_reason'] );
		}
	}

	public function test_nonexistent_account_is_not_trusted_and_has_reason(): void {
		$row = $this->record( 'user_registration', array( 'user_id' => 99999999, 'is_bot' => false ) );
		$this->assertTrue( $row['event_data']['is_bot'] );
		$this->assertSame( 'unverified_account_context', $row['event_data']['bot_reason'] );
		$this->assertNull( $row['user_id'] );
	}

	public function test_unrelated_events_do_not_trust_payload_user_id(): void {
		$user_id = self::factory()->user->create();
		$row = $this->record( 'newsletter_signup', array( 'user_id' => $user_id ) );
		$this->assertTrue( $row['event_data']['is_bot'] );
		$this->assertNull( $row['user_id'] );
	}
}
