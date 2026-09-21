<?php
/**
 * Shared fixture for two-session MySQL booking proofs.
 *
 * Every proof in this directory that opens a second, independent MySQL
 * session to prove lock/transaction behaviour needs the same starting point:
 * one venue, one authorized owning actor, and the installed production
 * booking schema. That fixture belongs in a base class both proofs extend as
 * siblings, not in one proof's own test class that another proof then
 * `require_once`s and extends — doing the latter drags whichever trapping
 * production calls the first proof declares into the suite that only meant
 * to declare the second (see extrachill-events#870).
 *
 * @package ExtraChillEvents\Tests\MySQLIntegration
 */

use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueMembershipRepository;

/** Base fixture: one venue, one authorized owner, the installed booking schema. */
abstract class BookingMySQLIntegrationTestCase extends WP_UnitTestCase {
	/** Venue fixture ID.
	 *
	 * @var int
	 */
	protected $venue_id;
	/** Authorized actor fixture ID.
	 *
	 * @var int
	 */
	protected $actor_id;

	/** Install the production schema and create the shared venue/actor fixtures. */
	public function set_up(): void {
		parent::set_up();
		if ( ! extension_loaded( 'mysqli' ) ) {
			$this->markTestSkipped( 'The mysqli extension is required for two-session MySQL coverage.' );
		}
		if ( ':memory:' === DB_NAME || false !== stripos( (string) DB_HOST, 'sqlite' ) ) {
			$this->markTestSkipped( 'A real MySQL test database is required; SQLite substitution is not faithful.' );
		}

		register_taxonomy(
			'venue',
			'post',
			array( 'public' => false )
		);
		$venue = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'venue',
				'name'     => 'Integration Room ' . wp_generate_uuid4(),
			)
		);
		$this->assertNotWPError( $venue );
		$this->venue_id = (int) $venue->term_id;
		$this->actor_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $this->actor_id )->add_cap( VenueAuthorization::ACCESS_CAPABILITY );

		$this->assertTrue( BookingSchema::install() );
		$membership = ( new VenueMembershipRepository() )->create(
			array(
				'venue_term_id'      => $this->venue_id,
				'user_id'            => $this->actor_id,
				'is_owner'           => true,
				'status'             => VenueAuthorization::STATUS_ACTIVE,
				'created_by_user_id' => $this->actor_id,
			)
		);
		$this->assertIsArray( $membership, is_wp_error( $membership ) ? $membership->get_error_code() : '' );
	}

	/** Remove all disposable booking state. */
	public function tear_down(): void {
		global $wpdb;
		foreach ( array( BookingSchema::settlements_table(), BookingSchema::sales_resolutions_table(), BookingSchema::sales_reports_table(), BookingSchema::ticket_sources_table(), BookingSchema::holds_table(), BookingSchema::attachment_deliveries_table(), BookingSchema::attachments_table(), BookingSchema::activity_table(), BookingSchema::bookings_table(), BookingSchema::memberships_table() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Disposable test database cleanup.
		}
		delete_option( BookingSchema::VERSION_OPTION );
		delete_option( BookingSchema::FAILURE_OPTION );
		parent::tear_down();
	}

	/** Give a forked application process an independent WordPress DB session. */
	protected function reconnect_wordpress_database(): void {
		global $wpdb, $table_prefix;
		if ( $wpdb->dbh instanceof mysqli ) {
			$wpdb->dbh->close();
		}
		$wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$wpdb->set_prefix( $table_prefix );
	}
}
