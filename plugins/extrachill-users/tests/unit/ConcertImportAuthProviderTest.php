<?php
/**
 * Unit tests for the concert-import Data Machine auth provider integration.
 *
 * These tests exercise the provider/source contract that replaces the old
 * hand-rolled `OPTION_API_KEY` / `CONSTANT_API_KEY` storage (fixed in #54).
 *
 * The tests work against the real BaseAuthProvider storage path so we
 * actually verify encryption + decryption round-trip through the same
 * envelope used in production. WP_UnitTestCase ensures wp_salt('auth') is
 * available so the AES-256-GCM key derivation succeeds.
 */

use ExtraChill\Users\Concert_Import\Sources\ConcertImportAuthProvider;
use ExtraChill\Users\Concert_Import\Sources\PhishNetAuthProvider;
use ExtraChill\Users\Concert_Import\Sources\PhishNetImportSource;
use ExtraChill\Users\Concert_Import\Sources\SetlistFmAuthProvider;
use ExtraChill\Users\Concert_Import\Sources\SetlistFmImportSource;

class Test_Concert_Import_Auth_Provider extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\DataMachine\\Core\\OAuth\\BaseAuthProvider' ) ) {
			$this->markTestSkipped( 'Data Machine BaseAuthProvider is not available in this test run.' );
		}
		ConcertImportAuthProvider::register_with_datamachine();
		// Reset the shared auth storage between tests.
		delete_site_option( 'datamachine_auth_data' );
	}

	protected function tearDown(): void {
		delete_site_option( 'datamachine_auth_data' );
		parent::tearDown();
	}

	public function test_setlist_source_is_not_configured_when_no_api_key_is_stored(): void {
		$source = new SetlistFmImportSource();
		$this->assertFalse(
			$source->is_configured(),
			'SetlistFmImportSource::is_configured() must return false when no auth ref is configured.'
		);
	}

	public function test_phishnet_source_is_not_configured_when_no_api_key_is_stored(): void {
		$source = new PhishNetImportSource();
		$this->assertFalse(
			$source->is_configured(),
			'PhishNetImportSource::is_configured() must return false when no auth ref is configured.'
		);
	}

	public function test_setlist_source_reads_api_key_from_data_machine_auth_provider(): void {
		$provider = new SetlistFmAuthProvider();
		$provider->save_config( array( 'api_key' => 'secret-setlist-key' ) );

		$source = new SetlistFmImportSource();
		$this->assertTrue(
			$source->is_configured(),
			'SetlistFmImportSource::is_configured() must return true once a key is stored via the auth provider.'
		);

		// Round-trip the key through a second provider instance to prove decryption works.
		$round_trip = ( new SetlistFmAuthProvider() )->get_api_key();
		$this->assertSame( 'secret-setlist-key', $round_trip );
	}

	public function test_phishnet_source_reads_api_key_from_data_machine_auth_provider(): void {
		$provider = new PhishNetAuthProvider();
		$provider->save_config( array( 'api_key' => 'secret-phish-key' ) );

		$source = new PhishNetImportSource();
		$this->assertTrue( $source->is_configured() );

		$round_trip = ( new PhishNetAuthProvider() )->get_api_key();
		$this->assertSame( 'secret-phish-key', $round_trip );
	}

	public function test_api_key_is_stored_encrypted_at_rest(): void {
		$provider = new SetlistFmAuthProvider();
		$provider->save_config( array( 'api_key' => 'plaintext-key' ) );

		$raw = get_site_option( 'datamachine_auth_data', array() );
		$stored = $raw[ SetlistFmAuthProvider::PROVIDER_SLUG ]['config']['api_key'] ?? '';

		$this->assertNotEmpty( $stored, 'API key must be persisted.' );
		$this->assertStringStartsWith(
			'dm:enc:v1:',
			$stored,
			'API key must be stored in the Data Machine encryption envelope, not as plaintext in wp_sitemeta.'
		);
		$this->assertStringNotContainsString(
			'plaintext-key',
			$stored,
			'Plaintext API key value must never appear in the persisted record.'
		);
	}

	public function test_get_config_fields_advertises_a_single_api_key_field(): void {
		$provider = new SetlistFmAuthProvider();
		$fields   = $provider->get_config_fields();

		$this->assertArrayHasKey( 'api_key', $fields, 'Provider must expose `api_key` to the auth CLI.' );
		$this->assertSame( 'password', $fields['api_key']['type'] ?? '' );
		$this->assertTrue( ! empty( $fields['api_key']['required'] ) );

		// No leftover account/username from HttpBasicAuthProvider — these
		// sources have one platform-wide credential, not a per-account fan-out.
		$this->assertArrayNotHasKey( 'account', $fields );
		$this->assertArrayNotHasKey( 'username', $fields );
	}

	public function test_separate_sources_use_distinct_provider_slots(): void {
		( new SetlistFmAuthProvider() )->save_config( array( 'api_key' => 'setlist-only' ) );
		( new PhishNetAuthProvider() )->save_config( array( 'api_key' => 'phish-only' ) );

		$this->assertSame( 'setlist-only', ( new SetlistFmAuthProvider() )->get_api_key() );
		$this->assertSame( 'phish-only',   ( new PhishNetAuthProvider() )->get_api_key() );

		// Storage layout: each provider gets its own top-level key.
		$raw = get_site_option( 'datamachine_auth_data', array() );
		$this->assertArrayHasKey( SetlistFmAuthProvider::PROVIDER_SLUG, $raw );
		$this->assertArrayHasKey( PhishNetAuthProvider::PROVIDER_SLUG, $raw );
	}
}
