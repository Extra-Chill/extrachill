<?php

use ExtraChillEvents\Core\VenuePartnerKey;
use PHPUnit\Framework\TestCase;

final class VenuePartnerKeyTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['partner_meta'] = array();
		$GLOBALS['partner_blog'] = 1;
		$GLOBALS['partner_stack'] = array();
		$GLOBALS['partner_allowed'] = true;
		$GLOBALS['partner_writes'] = 0;
	}

	public function test_lifecycle_and_private_storage(): void {
		$issued = VenuePartnerKey::manage( 1524, 'issue' );
		$this->assertMatchesRegularExpression( '/^ecpk_1524_[a-f0-9]{64}$/', $issued['key'] );
		$this->assertSame( array( 38, 'venue', 1524, 'manage_members', 7 ), $GLOBALS['partner_authorization'] );
		$this->assertStringNotContainsString( $issued['key'], serialize( $GLOBALS['partner_meta'] ) );
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( 1524, VenuePartnerKey::verify( $issued['key'] ) );
		$writes = $GLOBALS['partner_writes'];
		$this->assertSame( 1524, VenuePartnerKey::verify( $issued['key'] ) );
		$this->assertSame( $writes, $GLOBALS['partner_writes'], 'last-used writes are throttled' );
		$status = VenuePartnerKey::manage( 1524, 'status' );
		$this->assertArrayNotHasKey( 'hash', $status );
		$this->assertArrayNotHasKey( 'key', $status );
		$this->assertNotNull( $status['last_used_at'] );
		$this->assertSame( substr( $issued['key'], -4 ), $status['last_four'] );
		$this->assertSame( 'extrachill_partner_key_exists', VenuePartnerKey::manage( 1524, 'issue' )->code );
		$rotated = VenuePartnerKey::manage( 1524, 'rotate' );
		$this->assertSame( 401, VenuePartnerKey::verify( $issued['key'] )->data['status'] );
		$this->assertSame( 1524, VenuePartnerKey::verify( $rotated['key'] ) );
		$this->assertFalse( VenuePartnerKey::manage( 1524, 'revoke' )['active'] );
		$this->assertSame( 401, VenuePartnerKey::verify( $rotated['key'] )->data['status'] );
		$this->assertSame( 1, get_current_blog_id() );
	}

	public function test_forged_prefix_and_secret_fail(): void {
		$a = VenuePartnerKey::manage( 1524, 'issue' );
		VenuePartnerKey::manage( 1525, 'issue' );
		$this->assertSame( 401, VenuePartnerKey::verify( str_replace( 'ecpk_1524_', 'ecpk_1525_', $a['key'] ) )->data['status'] );
		$this->assertSame( 401, VenuePartnerKey::verify( 'ecpk_1524_' . str_repeat( '0', 64 ) )->data['status'] );
		$this->assertSame( 401, VenuePartnerKey::verify( 'not-a-key' )->data['status'] );
		$this->assertSame( 'extrachill_partner_key_invalid', VenuePartnerKey::verify( 'not-a-key' )->code );
		$this->assertSame( 401, VenuePartnerKey::verify( '' )->data['status'] );
	}

	public function test_non_member_cannot_manage_any_operation(): void {
		$GLOBALS['partner_allowed'] = false;
		foreach ( array( 'issue', 'rotate', 'revoke', 'status' ) as $operation ) {
			$this->assertSame( 'forbidden', VenuePartnerKey::manage( 1524, $operation )->code );
			$this->assertSame( 1, get_current_blog_id() );
		}
		$this->assertSame( 0, $GLOBALS['partner_writes'] );
	}

	public function test_verification_uses_timing_safe_compare(): void {
		$source = file_get_contents( __DIR__ . '/../../../inc/Core/VenuePartnerKey.php' );
		$this->assertStringContainsString( 'hash_equals( $expected, self::digest( $key ) )', $source );
	}
}
