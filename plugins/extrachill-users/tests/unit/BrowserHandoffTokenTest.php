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

/**
 * Deterministic named-lock double that pauses the winner after claiming.
 */
class Browser_Handoff_Lock_Database {

	/** @var bool */
	public $fail_lock = false;

	/** @var callable|null */
	public $after_claim;

	/** @var bool */
	private $locked = false;

	public function prepare( string $query, ...$args ): array {
		return array( $query, $args );
	}

	public function get_var( $query ) {
		if ( 0 === strpos( $query[0], 'SELECT GET_LOCK' ) ) {
			if ( $this->fail_lock || $this->locked ) {
				return '0';
			}

			$this->locked = true;
			if ( is_callable( $this->after_claim ) ) {
				call_user_func( $this->after_claim );
			}
			return '1';
		}

		$this->locked = false;
		return '1';
	}
}

class Test_Browser_Handoff_Token extends WP_UnitTestCase {

	/** @var mixed */
	private $original_cache;

	/** @var bool */
	private $original_ext_cache;

	/** @var mixed */
	private $original_database;

	/** @var Browser_Handoff_Test_Cache */
	private $cache;

	/** @var Browser_Handoff_Lock_Database */
	private $database;

	protected function setUp(): void {
		parent::setUp();

		$this->original_cache     = $GLOBALS['wp_object_cache'];
		$this->original_database  = $GLOBALS['wpdb'];
		$this->original_ext_cache = wp_using_ext_object_cache();
		$this->cache              = new Browser_Handoff_Test_Cache();
		$this->database           = new Browser_Handoff_Lock_Database();
		$GLOBALS['wp_object_cache'] = $this->cache;
		$GLOBALS['wpdb']            = $this->database;
		wp_using_ext_object_cache( true );
	}

	protected function tearDown(): void {
		$GLOBALS['wp_object_cache'] = $this->original_cache;
		$GLOBALS['wpdb']            = $this->original_database;
		wp_using_ext_object_cache( $this->original_ext_cache );
		parent::tearDown();
	}

	public function test_concurrent_consumers_have_exactly_one_winner(): void {
		$token       = extrachill_users_create_browser_handoff_token( 42, 'https://community.extrachill.com/settings/' );
		$competitors = array();

		// Hold the winner immediately after its atomic claim, then release every
		// competitor at that barrier before the winner reads or deletes payload.
		$this->database->after_claim = function () use ( $token, &$competitors ): void {
			for ( $i = 0; $i < 3; $i++ ) {
				$competitors[] = extrachill_users_consume_browser_handoff_token( $token );
			}
		};

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
		$token                     = extrachill_users_create_browser_handoff_token( 42, 'https://extrachill.com/' );
		$this->database->fail_lock = true;

		$result = extrachill_users_consume_browser_handoff_token( $token );

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
}
