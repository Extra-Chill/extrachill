<?php
/**
 * Purpose-bound My Shows scope token contract.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/wp-stubs.php';
require_once dirname( __DIR__, 4 ) . '/extrachill-network/inc/core/signed-token.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/my-shows-scope-token.php';

/** Verifies scope token ownership and rejection boundaries. */
final class MyShowsScopeTokenTest extends TestCase {
	/** Valid owners round trip; non-positive owners cannot mint. */
	public function test_round_trip_and_invalid_ids(): void {
		$token = ec_events_my_shows_mint_scope_token( 42 );
		$this->assertSame( 42, ec_events_my_shows_verify_scope_token( $token ) );
		$this->assertSame( '', ec_events_my_shows_mint_scope_token( 0 ) );
		$this->assertSame( '', ec_events_my_shows_mint_scope_token( -1 ) );
	}

	/** Malformed and old-format tokens fail closed. */
	public function test_tampered_and_legacy_tokens_are_rejected(): void {
		$token = ec_events_my_shows_mint_scope_token( 42 );
		foreach ( array( $token . '0', '42.' . str_repeat( 'a', 64 ), '', null, array() ) as $invalid ) {
			$this->assertSame( 0, ec_events_my_shows_verify_scope_token( $invalid ) );
		}
	}

	/** The primitive enforces the seven-day lifetime and purpose. */
	public function test_expiry_and_purpose_binding(): void {
		$now   = time();
		$token = ec_events_my_shows_mint_scope_token( 42 );
		$this->assertNull( extrachill_verify( 'events.my_shows_scope', $token, $now + 7 * DAY_IN_SECONDS + 1 ) );
		$other = extrachill_sign( 'network.experiment_exposure', array( 'uid' => 42 ), 3600 );
		$this->assertSame( 0, ec_events_my_shows_verify_scope_token( $other ) );
	}

	/** Even authentic claims must carry a positive integer uid. */
	public function test_authenticated_invalid_claims_are_rejected(): void {
		foreach ( array( array(), array( 'uid' => 0 ), array( 'uid' => -1 ), array( 'uid' => '42' ) ) as $claims ) {
			$this->assertSame( 0, ec_events_my_shows_verify_scope_token( extrachill_sign( 'events.my_shows_scope', $claims, 60 ) ) );
		}
	}
}
