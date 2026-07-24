<?php
/**
 * Unit tests for atomic browser handoff token consumption.
 */

class Browser_Handoff_Test_Cache {

	/** @var array */
	private $data = array();

	/** @var bool */
	public $fail_delete = false;

	public function set( $key, $value, $group = 'default', $expiration = 0 ): bool {
		$this->data[ $group . ':' . $key ] = array(
			'value'      => $value,
			'expires_at' => $expiration ? time() + (int) $expiration : 0,
		);
		return true;
	}

	public function add( $key, $value, $group = 'default', $expiration = 0 ): bool {
		$found = false;
		$this->get( $key, $group, false, $found );
		if ( $found ) {
			return false;
		}

		return $this->set( $key, $value, $group, $expiration );
	}

	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$id    = $group . ':' . $key;
		$entry = $this->data[ $id ] ?? null;
		if ( is_array( $entry ) && ( empty( $entry['expires_at'] ) || $entry['expires_at'] > time() ) ) {
			$found = true;
			return $entry['value'];
		}

		unset( $this->data[ $id ] );
		$found = false;
		return false;
	}

	public function delete( $key, $group = 'default' ): bool {
		$id = $group . ':' . $key;
		if ( $this->fail_delete || ! isset( $this->data[ $id ] ) ) {
			return false;
		}

		unset( $this->data[ $id ] );
		return true;
	}
}

class Test_Browser_Handoff_Token extends WP_UnitTestCase {

	/** @var mixed */
	private $original_cache;

	/** @var bool */
	private $original_ext_cache;

	/** @var Browser_Handoff_Test_Cache */
	private $cache;

	protected function setUp(): void {
		parent::setUp();

		$this->original_cache     = $GLOBALS['wp_object_cache'];
		$this->original_ext_cache = wp_using_ext_object_cache();
		$this->cache              = new Browser_Handoff_Test_Cache();
		$GLOBALS['wp_object_cache'] = $this->cache;
		wp_using_ext_object_cache( true );
	}

	protected function tearDown(): void {
		$GLOBALS['wp_object_cache'] = $this->original_cache;
		wp_using_ext_object_cache( $this->original_ext_cache );
		parent::tearDown();
	}

	public function test_concurrent_consumers_have_exactly_one_winner(): void {
		$token       = extrachill_users_create_browser_handoff_token( 42, 'https://community.extrachill.com/settings/' );
		$claim_key   = 'ec_browser_handoff_claim_' . hash( 'sha256', $token );
		$competitors = array();

		// Core fires this hook after the unique option row exists but before the
		// winning add_option() returns, providing a deterministic race barrier.
		add_action( 'add_option_' . $claim_key, function () use ( $token, &$competitors ): void {
			for ( $i = 0; $i < 3; $i++ ) {
				$competitors[] = extrachill_users_consume_browser_handoff_token( $token );
			}
		} );

		$winner = extrachill_users_consume_browser_handoff_token( $token );

		$this->assertIsArray( $winner, 'Exactly one concurrent consumer must receive the payload.' );
		$this->assertSame( 42, (int) $winner['user_id'] );
		$this->assertCount( 3, $competitors );
		foreach ( $competitors as $competitor ) {
			$this->assertWPError( $competitor );
			$this->assertSame( 'invalid_handoff_token', $competitor->get_error_code() );
		}

		$replay = extrachill_users_consume_browser_handoff_token( $token );
		$this->assertWPError( $replay );
		$this->assertSame( 'invalid_handoff_token', $replay->get_error_code() );
	}

	public function test_atomic_claim_storage_failure_fails_closed(): void {
		global $wpdb;

		$token     = extrachill_users_create_browser_handoff_token( 42, 'https://extrachill.com/' );
		$claim_key = 'ec_browser_handoff_claim_' . hash( 'sha256', $token );
		$filter    = static function ( string $query ) use ( $claim_key ): string {
			if ( false !== strpos( $query, 'INSERT INTO' ) && false !== strpos( $query, $claim_key ) ) {
				return str_replace( $GLOBALS['wpdb']->options, 'missing_handoff_options', $query );
			}
			return $query;
		};

		$wpdb->suppress_errors( true );
		add_filter( 'query', $filter );

		$result = extrachill_users_consume_browser_handoff_token( $token );

		remove_filter( 'query', $filter );
		$wpdb->suppress_errors( false );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_handoff_token', $result->get_error_code() );
	}

	public function test_payload_delete_failure_fails_closed(): void {
		$token                    = extrachill_users_create_browser_handoff_token( 42, 'https://extrachill.com/' );
		$this->cache->fail_delete = true;

		$result = extrachill_users_consume_browser_handoff_token( $token );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_handoff_token', $result->get_error_code() );
	}

	public function test_claim_release_storage_failure_fails_closed(): void {
		global $wpdb;

		$token     = extrachill_users_create_browser_handoff_token( 42, 'https://extrachill.com/' );
		$claim_key = 'ec_browser_handoff_claim_' . hash( 'sha256', $token );
		$filter    = static function ( string $query ) use ( $claim_key ): string {
			if ( false !== strpos( $query, 'DELETE FROM' ) && false !== strpos( $query, $claim_key ) ) {
				return str_replace( $GLOBALS['wpdb']->options, 'missing_handoff_options', $query );
			}
			return $query;
		};

		$wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		$result = extrachill_users_consume_browser_handoff_token( $token );
		remove_filter( 'query', $filter );
		$wpdb->suppress_errors( false );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_handoff_token', $result->get_error_code() );

		$claim = get_option( $claim_key );
		$this->assertIsArray( $claim );
		extrachill_users_cleanup_browser_handoff_claim( $claim_key, $claim );
	}

	public function test_missing_and_expired_payloads_fail_closed(): void {
		$missing = extrachill_users_consume_browser_handoff_token( 'missing-token' );
		$this->assertWPError( $missing );
		$this->assertSame( 'invalid_handoff_token', $missing->get_error_code() );

		$token = 'expired-token';
		$key   = 'ec_browser_handoff_' . hash( 'sha256', $token );
		set_site_transient(
			$key,
			array(
				'user_id'       => 42,
				'redirect_url'  => 'https://extrachill.com/',
				'created_at_ts' => time() - 60,
			),
			60
		);

		$expired = extrachill_users_consume_browser_handoff_token( $token );
		$this->assertWPError( $expired );
		$this->assertSame( 'invalid_handoff_token', $expired->get_error_code() );
	}

	public function test_claim_cleanup_is_owner_safe(): void {
		$claim_key = 'ec_browser_handoff_claim_' . hash( 'sha256', 'cleanup-token' );
		$old_claim = array(
			'owner'      => 'old-owner',
			'expires_at' => time() - 1,
		);
		$new_claim = array(
			'owner'      => 'new-owner',
			'expires_at' => time() + 60,
		);

		$this->assertTrue( add_option( $claim_key, $new_claim, '', false ) );
		extrachill_users_cleanup_browser_handoff_claim( $claim_key, $old_claim );
		$this->assertSame( $new_claim, get_option( $claim_key ) );

		extrachill_users_cleanup_browser_handoff_claim( $claim_key, $new_claim );
		$this->assertFalse( get_option( $claim_key ) );
	}

	public function test_interrupted_claim_has_scheduled_cleanup(): void {
		$claim_key = 'ec_browser_handoff_claim_' . hash( 'sha256', 'interrupted-token' );
		$claim     = array(
			'owner'      => 'interrupted-owner',
			'expires_at' => time() + 60,
		);
		$args      = array( $claim_key, $claim );

		$this->assertTrue( extrachill_users_claim_browser_handoff( $claim_key, $claim ) );
		$this->assertSame( $claim['expires_at'], wp_next_scheduled( EXTRACHILL_USERS_BROWSER_HANDOFF_CLAIM_CLEANUP_HOOK, $args ) );

		extrachill_users_cleanup_browser_handoff_claim( $claim_key, $claim );
		$this->assertFalse( get_option( $claim_key ) );
		$this->assertFalse( wp_next_scheduled( EXTRACHILL_USERS_BROWSER_HANDOFF_CLAIM_CLEANUP_HOOK, $args ) );
	}

	public function test_claims_use_the_main_site_options_table(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite is required.' );
		}

		$main_site_id = get_main_site_id();
		$subsite_id   = self::factory()->blog->create();
		$token        = extrachill_users_create_browser_handoff_token( 42, 'https://community.extrachill.com/settings/' );
		$claim_key    = 'ec_browser_handoff_claim_' . hash( 'sha256', $token );
		$claim_site   = 0;

		add_action( 'add_option_' . $claim_key, static function () use ( &$claim_site ): void {
			$claim_site = get_current_blog_id();
		} );

		switch_to_blog( $subsite_id );
		try {
			$result = extrachill_users_consume_browser_handoff_token( $token );
		} finally {
			restore_current_blog();
		}

		$this->assertIsArray( $result );
		$this->assertSame( $main_site_id, $claim_site );
	}
}
