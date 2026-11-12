<?php
/**
 * Purpose-bound notification unsubscribe email links.
 *
 * @package ExtraChill\Users
 */
class Test_Notification_Unsubscribe_Token extends WP_UnitTestCase {
	/** Load the public URL and verifier entry points. */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/inc/notifications/unsubscribe.php';
	}

	/** New URLs contain only a token, which resolves to the recipient. */
	public function test_new_url_round_trip(): void {
		$user_id = self::factory()->user->create();
		parse_str( wp_parse_url( ec_notifications_unsubscribe_url( $user_id ), PHP_URL_QUERY ), $params );
		$this->assertSame( array( 'token' ), array_keys( $params ) );
		$this->assertSame( $user_id, ec_notifications_unsubscribe_verify( 0, 0, '', $params['token'] ) );
		$this->assertNull( extrachill_verify( 'users.notifications_unsubscribe', $params['token'], time() + EC_NOTIFICATIONS_UNSUBSCRIBE_TTL + 1 ) );
	}

	/** Old inbox links work before the cutoff, but not at or after it. */
	public function test_legacy_cutoff(): void {
		$user_id = self::factory()->user->create();
		$issued  = time();
		$sig     = ec_notifications_unsubscribe_signature( $user_id, $issued );
		$this->assertSame( 1797724800, EXTRACHILL_SIGN_LEGACY_UNTIL );
		$this->assertSame( $user_id, ec_notifications_unsubscribe_verify( $user_id, $issued, $sig, '', EXTRACHILL_SIGN_LEGACY_UNTIL - 1 ) );
		$this->assertWPError( ec_notifications_unsubscribe_verify( $user_id, $issued, $sig, '', EXTRACHILL_SIGN_LEGACY_UNTIL ) );
		$this->assertWPError( ec_notifications_unsubscribe_verify( $user_id, $issued, $sig, '', EXTRACHILL_SIGN_LEGACY_UNTIL + 1 ) );
	}

	/** Purpose separation, expiry, and recipient existence remain mandatory. */
	public function test_invalid_tokens(): void {
		$user_id = self::factory()->user->create();
		$claims  = array( 'user_id' => $user_id );
		$foreign = extrachill_sign( 'users.notification_read_all', $claims, EC_NOTIFICATIONS_UNSUBSCRIBE_TTL );
		$this->assertWPError( ec_notifications_unsubscribe_verify( 0, 0, '', $foreign ) );
		$token = extrachill_sign( 'users.notifications_unsubscribe', $claims, EC_NOTIFICATIONS_UNSUBSCRIBE_TTL );
		$this->assertWPError( ec_notifications_unsubscribe_verify( 0, 0, '', $token, time() + EC_NOTIFICATIONS_UNSUBSCRIBE_TTL + 1 ) );
		$token = extrachill_sign( 'users.notifications_unsubscribe', array( 'user_id' => 0 ), EC_NOTIFICATIONS_UNSUBSCRIBE_TTL );
		$this->assertWPError( ec_notifications_unsubscribe_verify( 0, 0, '', $token ) );
	}

	/** The original legacy checks still reject tampering and old timestamps. */
	public function test_invalid_legacy_links(): void {
		$user_id = self::factory()->user->create();
		$issued  = time() - EC_NOTIFICATIONS_UNSUBSCRIBE_TTL - 1;
		$sig     = ec_notifications_unsubscribe_signature( $user_id, $issued );
		$this->assertWPError( ec_notifications_unsubscribe_verify( $user_id, $issued, $sig, '', EXTRACHILL_SIGN_LEGACY_UNTIL - 1 ) );
		$this->assertWPError( ec_notifications_unsubscribe_verify( $user_id, time(), 'tampered', '', EXTRACHILL_SIGN_LEGACY_UNTIL - 1 ) );
	}

	/** New requests validate without any of the legacy query parameters. */
	public function test_route_schema_accepts_token_only(): void {
		ec_notifications_register_unsubscribe_route();
		$routes = rest_get_server()->get_routes();
		$args   = $routes['/extrachill/v1/notifications/unsubscribe'][0]['args'];
		$this->assertSame( 'string', $args['token']['type'] );
		foreach ( array( 'uid', 'ts', 'sig' ) as $key ) {
			$this->assertFalse( $args[ $key ]['required'] );
		}
	}
}
