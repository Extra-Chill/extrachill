<?php
/** Genuine two-session Local Support venue-authority MySQL proof. */

require_once __DIR__ . '/BookingAttachmentMySQLIntegrationTest.php';

use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\LocalSupportAuthorization;
use ExtraChillEvents\Core\LocalSupportSchema;
use ExtraChillEvents\Core\LocalSupportService;

/** Inject a second-session revocation immediately around production lock-current authorization. */
final class LocalSupportMySQLProbeAuthorization extends LocalSupportAuthorization {
	/** @var mysqli */
	private $contender;
	/** @var string */
	private $revoke_query;
	/** @var string */
	public $mode = '';
	/** @var bool */
	public $revocation_waited = false;

	public function __construct( mysqli $contender, string $revoke_query ) {
		parent::__construct();
		$this->contender   = $contender;
		$this->revoke_query = $revoke_query;
	}

	public function authorize_organizer_locked( array $request, int $user_id, object $scope ) {
		if ( 'before' === $this->mode ) {
			$this->contender->query( $this->revoke_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Independent fixture connection commits before production authority locking.
		}
		$allowed = parent::authorize_organizer_locked( $request, $user_id, $scope );
		if ( 'after' !== $this->mode || true !== $allowed ) {
			return $allowed;
		}
		try {
			$updated = $this->contender->query( $this->revoke_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Independent fixture connection races the held production authority lock.
			$this->revocation_waited = false === $updated && 1205 === $this->contender->errno;
		} catch ( mysqli_sql_exception $exception ) {
			$this->revocation_waited = 1205 === $exception->getCode();
		}
		return $allowed;
	}
}

/** Prove both deterministic orders through an actual LocalSupportService mutation. */
final class LocalSupportAuthorityConcurrencyMySQLProof extends BookingAttachmentMySQLIntegrationTest {
	/** Remove disposable Local Support state before the parent drops booking tables. */
	public function tear_down(): void {
		global $wpdb;
		foreach ( array( LocalSupportSchema::activity_table(), LocalSupportSchema::interests_table(), LocalSupportSchema::requests_table() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable test database cleanup.
		}
		delete_option( LocalSupportSchema::VERSION_OPTION );
		parent::tear_down();
	}

	/** Revocation before lock denies; authorization before revocation makes revocation wait. */
	public function test_local_support_venue_authority_and_revocation_serialize(): void {
		global $wpdb;
		$this->assertTrue( LocalSupportSchema::install() );
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $event_id, array( $this->venue_id ), 'venue', false );

		// This proof exercises a production service-owned transaction rather than
		// the wrapper transaction WP_UnitTestCase starts for ordinary tests.
		self::commit_transaction();
		$this->assertNotFalse( $wpdb->query( 'SET autocommit = 1' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Ends the test harness transaction boundary before the production transaction starts.

		$table         = BookingSchema::memberships_table();
		$revoke        = "UPDATE {$table} SET status = 'revoked' WHERE venue_term_id = {$this->venue_id} AND user_id = {$this->actor_id}";
		$activate      = "UPDATE {$table} SET status = 'active', revoked_at = NULL WHERE venue_term_id = {$this->venue_id} AND user_id = {$this->actor_id}";
		$authorization = new LocalSupportMySQLProbeAuthorization( $this->contender, $revoke );
		$service       = new LocalSupportService( null, $authorization );
		$request       = $service->open_request(
			array(
				'event_id'        => $event_id,
				'organizer_type'  => 'venue',
				'organizer_id'    => $this->venue_id,
				'idempotency_key' => 'mysql-local-support-open',
			),
			$this->actor_id
		);
		$this->assertIsArray( $request, is_wp_error( $request ) ? $request->get_error_code() : '' );

		$authorization->mode = 'after';
		$paused = $service->transition_request( $request['id'], 'paused', 1, 'mysql-local-support-pause', $this->actor_id );
		$this->assertIsArray( $paused, is_wp_error( $paused ) ? $paused->get_error_code() : '' );
		$this->assertTrue( $authorization->revocation_waited, 'Venue revocation did not wait for the Local Support mutation transaction.' );
		$this->assertTrue( $this->contender->query( $revoke ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Revocation completes after mutation commit.

		$this->assertTrue( $this->contender->query( $activate ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Restores authority for the opposite ordering.
		$authorization->mode = 'before';
		$denied = $service->transition_request( $request['id'], 'open', 2, 'mysql-local-support-resume', $this->actor_id );
		$this->assertWPError( $denied );
		$this->assertSame( 'venue_action_forbidden', $denied->get_error_code() );
		$this->assertSame( 2, ( new ExtraChillEvents\Core\LocalSupportRepository() )->get_request( $request['id'] )['version'] );
	}
}
