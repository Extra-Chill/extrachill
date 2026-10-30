<?php
/**
 * Production booking-attachment service coverage across two MySQL sessions.
 *
 * @package ExtraChillEvents\Tests\MySQLIntegration
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Commenting.FunctionComment.MissingParamTag,WordPress.DB.RestrictedFunctions,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- PHPUnit fixture keeps its probe provider local and requires a second raw MySQL session.

require_once __DIR__ . '/BookingSecondSessionMySQLIntegrationTestCase.php';

use ExtraChillEvents\Core\BookingAttachmentRepository;
use ExtraChillEvents\Core\BookingAttachmentService;
use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\TicketSettlementService;
use ExtraChillEvents\Core\TicketReconciliationService;
use ExtraChillEvents\Abilities\TicketSettlementAbilities;

/** CSV importer probe that loses the first row commit acknowledgement. */
final class TicketSettlementCSVReplayProbeService extends TicketSettlementService {
	/** @var callable|null Probe invoked between byte authentication and locking. */
	public $provenance_probe;

	/** Arm the real connection only after complete byte authentication. */
	protected function after_csv_authenticated( array $rows ): void {
		unset( $rows );
		$GLOBALS['wpdb']->fail_next_commit = true;
	}

	/** Invoke a second-session mutation after complete byte authentication. */
	protected function after_settlement_provenance_authenticated( array $booking ): void {
		unset( $booking );
		if ( is_callable( $this->provenance_probe ) ) {
			( $this->provenance_probe )();
		}
	}
}

/** Exercises production repositories, authorization, transactions, and cleanup. */
class BookingAttachmentMySQLIntegrationTest extends BookingSecondSessionMySQLIntegrationTestCase {
	/** Whether the membership contender remained blocked during claim.
	 *
	 * @var bool
	 */
	private $membership_update_waited = false;
	/** Whether the named-lock contender remained blocked during retirement.
	 *
	 * @var bool
	 */
	private $reference_lock_waited = false;
	/** Whether a concurrent evidence insert waited on the settlement snapshot.
	 *
	 * @var bool
	 */
	private $evidence_insert_waited = false;

	/** Prove production attach and cleanup serialize their authority domains. */
	public function test_production_attach_and_cleanup_hold_the_locked_authority_and_reference_domains(): void {
		global $wpdb;
		$booking = ( new BookingRepository() )->create(
			array(
				'venue_term_id' => $this->venue_id,
				'artist_name'   => 'Integration Artist',
				'intake'        => array(),
			)
		);
		$this->assertIsArray( $booking, is_wp_error( $booking ) ? $booking->get_error_code() : '' );

		$memberships                 = BookingSchema::memberships_table();
		$this->provider->claim_probe = function () use ( $memberships ): void {
			try {
				$updated                        = $this->contender->query( "UPDATE {$memberships} SET status = 'revoked' WHERE venue_term_id = {$this->venue_id} AND user_id = {$this->actor_id}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Independent test connection races the production transaction.
				$this->membership_update_waited = false === $updated && 1205 === $this->contender->errno;
			} catch ( mysqli_sql_exception $exception ) {
				$this->membership_update_waited = 1205 === $exception->getCode();
			}
		};

		$service    = new BookingAttachmentService( null, null, null, null, $this->provider );
		$attachment = $service->attach(
			array(
				'booking_id'        => $booking['id'],
				'storage_reference' => 'private_object_integration_123456',
				'idempotency_key'   => 'mysql-integration-attach',
				'purpose'           => 'other_private_evidence',
				'uploader_type'     => 'user',
				'uploader_user_id'  => $this->actor_id,
			)
		);
		$this->assertIsArray( $attachment, is_wp_error( $attachment ) ? $attachment->get_error_code() : '' );
		$this->assertTrue( $this->membership_update_waited, 'Membership revocation bypassed the rows used by production attachment authorization.' );
		$this->assertTrue( $this->contender->query( "UPDATE {$memberships} SET status = 'revoked' WHERE venue_term_id = {$this->venue_id} AND user_id = {$this->actor_id}" ), 'Membership revocation did not complete after the production transaction committed.' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Independent test connection confirms lock release.
		$this->assertSame( 1, $this->contender->affected_rows );

		$this->contender->query( "UPDATE {$memberships} SET status = 'active' WHERE venue_term_id = {$this->venue_id} AND user_id = {$this->actor_id}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Restores isolated fixture authority for cleanup.
		$deleted = $service->delete( $booking['id'], $attachment['id'], $this->actor_id );
		$this->assertIsArray( $deleted, is_wp_error( $deleted ) ? $deleted->get_error_code() : '' );
		$wpdb->update( BookingSchema::attachments_table(), array( 'retired_at' => '2020-01-01 00:00:00' ), array( 'id' => $attachment['id'] ) );

		$lock_name                    = $this->reference_lock_name( $attachment['storage_reference'] );
		$this->provider->retire_probe = function () use ( $lock_name ): void {
			$escaped                     = $this->contender->real_escape_string( $lock_name );
			$result                      = $this->contender->query( "SELECT GET_LOCK('{$escaped}', 1)" );
			$this->reference_lock_waited = $result instanceof mysqli_result && 0 === (int) $result->fetch_row()[0];
		};
		$cleanup                      = $service->cleanup(
			array(
				'actor_id'            => $this->actor_id,
				'retention_days'      => 1,
				'legal_hold_callback' => static function (): bool {
					return false;
				},
			)
		);
		$this->assertSame( 1, $cleanup['purged'] ?? 0, is_wp_error( $cleanup ) ? $cleanup->get_error_code() : '' );
		$this->assertTrue( $this->reference_lock_waited, 'A second session acquired the reference domain while production cleanup was retiring bytes.' );
		$result = $this->contender->query( "SELECT GET_LOCK('{$lock_name}', 1)" );
		$this->assertInstanceOf( mysqli_result::class, $result );
		$this->assertSame( 1, (int) $result->fetch_row()[0], 'The second session did not acquire the reference domain after cleanup committed.' );
		$this->assertSame( 1, (int) $this->contender->query( "SELECT RELEASE_LOCK('{$lock_name}')" )->fetch_row()[0] );
		$this->assertSame( array( 'private_object_integration_123456' ), $this->provider->retired );
		$this->assertSame( 'purged', ( new BookingAttachmentRepository() )->get( $attachment['id'] )['state'] );
	}

	/** Prove second-session source/report competitors converge or conflict exactly. */
	public function test_competing_source_and_report_identities_are_exact_and_lossless(): void {
		$event_id       = self::factory()->post->create(
			array(
				'post_type'   => defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$bookings       = new BookingRepository();
		$booking        = $bookings->create(
			array(
				'venue_term_id' => $this->venue_id,
				'artist_name'   => 'Competing Identity Artist',
				'intake'        => array(),
			)
		);
		$booking        = $bookings->claim_event( $booking['id'], $event_id, $booking['version'] );
		$reconciliation = new TicketReconciliationService();
		$source_input   = array(
			'booking_id' => $booking['id'],
			'provider'   => 'neutral-provider',
			'source_key' => 'Opaque/Case ID',
			'ticket_url' => 'https://tickets.example.test/identity-one',
		);
		$source         = $reconciliation->register_source( $source_input, $this->actor_id );
		$this->assertIsArray( $source, is_wp_error( $source ) ? $source->get_error_code() : '' );
		$sources          = BookingSchema::ticket_sources_table();
		$duplicate_source = "INSERT INTO {$sources} (public_id,booking_id,event_id,venue_term_id,provider,source_key,source_key_hash,canonical_url,url_hash,request_hash,created_by_user_id,created_at) SELECT UUID(),booking_id,event_id,venue_term_id,provider,source_key,source_key_hash,'https://tickets.example.test/competitor',SHA2('competitor',256),SHA2('competitor',256),created_by_user_id,UTC_TIMESTAMP() FROM {$sources} WHERE id = " . (int) $source['id'];
		$source_duplicate = false;
		try {
			$source_duplicate = false === $this->contender->query( $duplicate_source ) && 1062 === $this->contender->errno;
		} catch ( mysqli_sql_exception $exception ) {
			$source_duplicate = 1062 === $exception->getCode();
		}
		$this->assertTrue( $source_duplicate, 'A second session inserted a competing source identity.' );
		$this->assertSame( $source['id'], $reconciliation->register_source( $source_input, $this->actor_id )['id'] );
		$changed_url               = $source_input;
		$changed_url['ticket_url'] = 'https://tickets.example.test/conflict';
		$this->assertSame( 'ticket_source_idempotency_conflict', $reconciliation->register_source( $changed_url, $this->actor_id )->get_error_code() );
		$case_distinct               = $source_input;
		$case_distinct['source_key'] = 'opaque/case id';
		$case_distinct['ticket_url'] = 'https://tickets.example.test/identity-two';
		$this->assertIsArray( $reconciliation->register_source( $case_distinct, $this->actor_id ) );

		$service           = new TicketSettlementService( $bookings, null, null, null, $reconciliation );
		$input             = $this->settlement_report_input( $booking['id'], 'Opaque/Report ID', $source['id'] );
		$input['provider'] = 'neutral-provider';
		$report            = $service->record_sales( $input, $this->actor_id );
		$this->assertIsArray( $report, is_wp_error( $report ) ? $report->get_error_code() : '' );
		$sales            = BookingSchema::sales_reports_table();
		$duplicate_report = "INSERT INTO {$sales} (booking_id,event_id,venue_term_id,ticket_source_id,evidence_attachment_id,provider,external_report_id,external_report_id_hash,source_type,provenance_version,ticket_source_request_hash,evidence_attachment_request_hash,evidence_content_hash,evidence_byte_size,period_start,period_end,tickets_sold,tickets_refunded,gross_minor,fees_minor,tax_minor,refunds_minor,net_minor,currency,corrects_report_id,source_payload,request_hash,created_by_user_id,created_at) SELECT booking_id,event_id,venue_term_id,ticket_source_id,evidence_attachment_id,provider,external_report_id,external_report_id_hash,source_type,provenance_version,ticket_source_request_hash,evidence_attachment_request_hash,evidence_content_hash,evidence_byte_size,period_start,period_end,tickets_sold,tickets_refunded,gross_minor + 1,fees_minor,tax_minor,refunds_minor,net_minor,currency,corrects_report_id,source_payload,SHA2('competitor',256),created_by_user_id,UTC_TIMESTAMP() FROM {$sales} WHERE id = " . (int) $report['id'];
		$report_duplicate = false;
		try {
			$report_duplicate = false === $this->contender->query( $duplicate_report ) && 1062 === $this->contender->errno;
		} catch ( mysqli_sql_exception $exception ) {
			$report_duplicate = 1062 === $exception->getCode();
		}
		$this->assertTrue( $report_duplicate, 'A second session inserted a competing report identity.' );
		$this->assertSame( $report['id'], $service->record_sales( $input, $this->actor_id )['id'] );
		$conflict                = $input;
		$conflict['gross_minor'] = $input['gross_minor'] + 1;
		$this->assertSame( 'sales_report_idempotency_conflict', $service->record_sales( $conflict, $this->actor_id )->get_error_code() );
	}

	/** Prove a lost mid-import commit acknowledgement replays without duplicates. */
	public function test_csv_mid_loop_commit_uncertain_replay_converges_exactly(): void {
		global $wpdb;
		$event_id           = self::factory()->post->create(
			array(
				'post_type'   => defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$bookings           = new BookingRepository();
		$booking            = $bookings->create(
			array(
				'venue_term_id' => $this->venue_id,
				'artist_name'   => 'CSV Replay Artist',
				'intake'        => array(),
			)
		);
		$booking            = $bookings->claim_event( $booking['id'], $event_id, $booking['version'] );
		$attachments        = new BookingAttachmentRepository();
		$activity           = new BookingActivityRepository();
		$attachment_service = new BookingAttachmentService( $attachments, $bookings, $activity, null, $this->provider );
		$csv                = "external_report_id,period_start,period_end,tickets_sold,tickets_refunded,gross_minor,fees_minor,tax_minor,refunds_minor,net_minor,currency\n"
			. "mysql-csv-1,2026-07-01 00:00:00,2026-07-01 23:59:59,10,0,10000,500,0,0,9500,USD\n"
			. "mysql-csv-2,2026-07-02 00:00:00,2026-07-02 23:59:59,5,0,5000,250,0,0,4750,USD\n";
		$path               = wp_tempnam( 'ticket-sales.csv' );
		file_put_contents( $path, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable local test fixture file, not a filesystem operation on user-facing content.
		$reference  = $this->provider->stage( $path, 'ticket-sales.csv', 'other_private_evidence' );
		$attachment = $attachment_service->attach(
			array(
				'booking_id'        => $booking['id'],
				'storage_reference' => $reference,
				'idempotency_key'   => 'mysql-csv-replay',
				'purpose'           => 'other_private_evidence',
				'uploader_type'     => 'user',
				'uploader_user_id'  => $this->actor_id,
			)
		);
		$this->assertIsArray( $attachment, is_wp_error( $attachment ) ? $attachment->get_error_code() : '' );
		$reconciliation = new TicketReconciliationService( $bookings, $activity, null, $attachments, $attachment_service );
		$source         = $reconciliation->register_source(
			array(
				'booking_id' => $booking['id'],
				'provider'   => 'csv-provider',
				'source_key' => 'csv-source',
				'ticket_url' => 'https://tickets.example.test/csv',
			),
			$this->actor_id
		);
		$this->assertIsArray( $source, is_wp_error( $source ) ? $source->get_error_code() : '' );
		$service   = new TicketSettlementCSVReplayProbeService( $bookings, $activity, null, null, $reconciliation );
		$original  = $wpdb;
		$uncertain = new BookingCommitUncertainWpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$uncertain->set_prefix( $original->base_prefix );
		$uncertain->set_blog_id( $original->blogid, $original->siteid );
		$wpdb = $uncertain; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Swaps the connection to prove a lost commit acknowledgement; restored in the finally block below.
		try {
			$input   = array(
				'booking_id'       => $booking['id'],
				'attachment_id'    => $attachment['id'],
				'ticket_source_id' => $source['id'],
			);
			$reports = $service->import_csv( $input, $this->actor_id );
			$this->assertIsArray( $reports, is_wp_error( $reports ) ? $reports->get_error_code() : '' );
			$this->assertCount( 2, $reports );
			$this->assertSame( array( 'mysql-csv-1', 'mysql-csv-2' ), array_column( $reports, 'external_report_id' ) );
			$retry = $service->import_csv( $input, $this->actor_id );
			$this->assertSame( array_column( $reports, 'id' ), array_column( $retry, 'id' ) );
			$sales_table = BookingSchema::sales_reports_table();
			$this->assertSame( 2, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sales_table} WHERE booking_id = %d", $booking['id'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact disposable integration row count.
			$preview = $service->calculate(
				array(
					'booking_id'   => $booking['id'],
					'basis'        => 'gross_ticket_sales',
					'basis_points' => 2000,
					'currency'     => 'USD',
				),
				$this->actor_id
			);
			$this->assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() : '' );
			$finalize                  = array(
				'booking_id'               => $booking['id'],
				'expected_booking_version' => $preview['booking_version'],
				'expected_report_ids'      => $preview['included_report_ids'],
				'expected_evidence_hash'   => $preview['evidence_hash'],
				'basis'                    => $preview['basis'],
				'basis_points'             => $preview['basis_points'],
				'currency'                 => $preview['currency'],
				'formula_version'          => $preview['formula_version'],
				'adjustment_minor'         => 0,
			);
			$attachments_table         = BookingSchema::attachments_table();
			$service->provenance_probe = function () use ( $attachments_table, $attachment ): void {
				$this->assertTrue( $this->contender->query( "UPDATE {$attachments_table} SET state = 'purged', purged_at = UTC_TIMESTAMP() WHERE id = " . (int) $attachment['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Independent session wins between byte authentication and settlement locking.
			};
			$this->assertSame( 'settlement_csv_evidence_invalid', $service->finalize( $finalize, $this->actor_id )->get_error_code() );
			$settlements_table = BookingSchema::settlements_table();
			$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$settlements_table} WHERE booking_id = %d", $booking['id'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- No settlement may survive the won retirement race.
			$this->assertTrue( $this->contender->query( "UPDATE {$attachments_table} SET state = 'active', purged_at = NULL WHERE id = " . (int) $attachment['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Restores the disposable fixture after proving the race.
			$service->provenance_probe = null;
			$settlement                = $service->finalize( $finalize, $this->actor_id );
			$this->assertIsArray( $settlement, is_wp_error( $settlement ) ? $settlement->get_error_code() : '' );
			$this->provider->contents[ $reference ] = substr( $csv, 0, -1 );
			$this->assertSame( 'settlement_csv_evidence_invalid', $service->finalize( $finalize, $this->actor_id )->get_error_code() );
			$this->assertSame( $settlement['id'], $service->get( $booking['id'], $this->actor_id )['id'] );
		} finally {
			$wpdb = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the real connection this test intentionally swapped above.
			$uncertain->dbh->close();
			if ( file_exists( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable local test fixture file.
			}
		}
	}

	/** Prove settlement evidence snapshots block concurrent same-booking inserts. */
	public function test_production_settlement_locks_evidence_and_preserves_payment_audit(): void {
		if ( ! function_exists( 'pcntl_fork' ) ) {
			// This proof's contender closure calls mysqli::rollback(), which PHP-WASM
			// traps (Automattic/wp-codebox#2518, extrachill-events#870/#884) — the
			// interpreter crashes rather than raising a catchable PHP error, so the
			// call must never execute in the managed sandbox. There is no pcntl_fork()
			// call in this test; function_exists('pcntl_fork') is reused here purely
			// as this repo's established signal for "we are in the PHP-WASM sandbox"
			// (see InternalBookingHoldConcurrencyMySQLProof). Runs for real against
			// real MySQL in the host MySQL proof workflow.
			$this->markTestSkipped( 'This proof calls mysqli::rollback(), which PHP-WASM traps unrecoverably. It executes against real MySQL in the host MySQL proof workflow — see extrachill-events#870/#884.' );
		}
		global $wpdb;
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$bookings = new BookingRepository();
		$booking  = $bookings->create(
			array(
				'venue_term_id' => $this->venue_id,
				'artist_name'   => 'Settlement Integration Artist',
				'intake'        => array(),
			)
		);
		$this->assertIsArray( $booking, is_wp_error( $booking ) ? $booking->get_error_code() : '' );
		$booking = $bookings->claim_event( $booking['id'], $event_id, $booking['version'] );
		$this->assertIsArray( $booking, is_wp_error( $booking ) ? $booking->get_error_code() : '' );

		$service   = new TicketSettlementMySQLProbeService();
		$abilities = $this->register_settlement_abilities( $service );
		wp_set_current_user( $this->actor_id );
		$source = ( new TicketReconciliationService() )->register_source(
			array(
				'booking_id' => $booking['id'],
				'provider'   => 'manual-certified',
				'source_key' => 'primary',
				'ticket_url' => 'https://tickets.example.test/mysql-primary?token=private',
			),
			$this->actor_id
		);
		$this->assertIsArray( $source, is_wp_error( $source ) ? $source->get_error_code() : '' );
		$this->assertSame(
			$source,
			( new TicketReconciliationService() )->register_source(
				array(
					'booking_id' => $booking['id'],
					'provider'   => 'manual-certified',
					'source_key' => 'primary',
					'ticket_url' => 'https://tickets.example.test/mysql-primary?token=private',
				),
				$this->actor_id
			)
		);
		$report = $abilities['extrachill/record-booking-ticket-sales']->execute(
			array(
				'booking_id'         => $booking['id'],
				'ticket_source_id'   => $source['id'],
				'provider'           => 'manual-certified',
				'external_report_id' => 'mysql-settlement-report-1',
				'source_type'        => 'manual',
				'period_start'       => '2026-07-01 00:00:00',
				'period_end'         => '2026-07-31 23:59:59',
				'tickets_sold'       => 100,
				'tickets_refunded'   => 5,
				'gross_minor'        => 100000,
				'fees_minor'         => 5000,
				'tax_minor'          => 3000,
				'refunds_minor'      => 5000,
				'net_minor'          => 87000,
				'currency'           => 'USD',
				'source'             => array( 'certificate' => 'mysql-proof' ),
			)
		);
		$this->assertIsArray( $report, is_wp_error( $report ) ? $report->get_error_code() : '' );
		$listed = $abilities['extrachill/list-booking-ticket-sales']->execute( array( 'booking_id' => $booking['id'] ) );
		$this->assertIsArray( $listed, is_wp_error( $listed ) ? $listed->get_error_code() : '' );
		$this->assertSame( $report['id'], $listed[0]['id'] );

		$sales         = BookingSchema::sales_reports_table();
		$external      = wp_json_encode(
			array(
				'version' => 1,
				'data'    => array( 'certificate' => 'contender' ),
			)
		);
		$sql           = $this->contender->prepare( "INSERT INTO {$sales} (booking_id,event_id,venue_term_id,provider,external_report_id,external_report_id_hash,source_type,provenance_version,period_start,period_end,tickets_sold,tickets_refunded,gross_minor,fees_minor,tax_minor,refunds_minor,net_minor,currency,corrects_report_id,source_payload,request_hash,created_by_user_id,created_at) VALUES (?,?,?,?,?,?,'manual',1,'2026-07-01 00:00:00','2026-07-31 23:59:59',1,0,100,0,0,0,100,'USD',NULL,?,?,?,UTC_TIMESTAMP())" );
		$provider      = 'manual-certified';
		$external_id   = 'mysql-settlement-contender';
		$external_hash = hash( 'sha256', $external_id );
		$request_hash  = hash( 'sha256', 'contender' );
		$sql->bind_param( 'iiisssssi', $booking['id'], $event_id, $this->venue_id, $provider, $external_id, $external_hash, $external, $request_hash, $this->actor_id );
		$preview = $abilities['extrachill/calculate-booking-settlement']->execute(
			array(
				'booking_id'   => $booking['id'],
				'basis'        => 'gross_ticket_sales',
				'basis_points' => 2000,
				'currency'     => 'USD',
			)
		);
		$this->assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() : '' );
		$this->assertSame( 20000, $preview['amount_due_minor'] );
		$service->evidence_probe = function () use ( $sql ): void {
			try {
				$inserted                     = $sql->execute();
				$this->evidence_insert_waited = false === $inserted && 1205 === $sql->errno;
			} catch ( mysqli_sql_exception $exception ) {
				$this->evidence_insert_waited = 1205 === $exception->getCode();
			}
			$this->contender->rollback();
		};
		$settlement              = $abilities['extrachill/finalize-booking-settlement']->execute(
			array(
				'booking_id'               => $booking['id'],
				'expected_booking_version' => $booking['version'],
				'expected_report_ids'      => $preview['included_report_ids'],
				'expected_evidence_hash'   => $preview['evidence_hash'],
				'basis'                    => $preview['basis'],
				'basis_points'             => $preview['basis_points'],
				'currency'                 => $preview['currency'],
				'formula_version'          => $preview['formula_version'],
				'adjustment_minor'         => 0,
			)
		);
		$this->assertIsArray( $settlement, is_wp_error( $settlement ) ? $settlement->get_error_code() : '' );
		$this->assertSame( 'finalized', $settlement['status'] );
		$this->assertTrue( $this->evidence_insert_waited, 'Concurrent ticket evidence bypassed production finalize() evidence locking.' );
		$next_booking_version = $booking['version'] + 1;
		$this->assertSame(
			1,
			$wpdb->update(
				BookingSchema::bookings_table(),
				array(
					'status'  => 'completed',
					'version' => $next_booking_version,
				),
				array(
					'id'      => $booking['id'],
					'version' => $booking['version'],
				)
			)
		);
		$paid = $abilities['extrachill/mark-booking-settlement-paid']->execute(
			array(
				'booking_id'               => $booking['id'],
				'expected_booking_version' => $next_booking_version,
				'expected_version'         => 1,
				'payment_reference'        => 'mysql-ach-proof',
			)
		);
		$this->assertIsArray( $paid, is_wp_error( $paid ) ? $paid->get_error_code() : '' );
		$this->assertSame( 'paid', $paid['status'] );
		$this->assertSame( 'mysql-ach-proof', $paid['payment_reference'] );
		$this->assertSame( $this->actor_id, $paid['paid_by_user_id'] );

		$service->evidence_probe = null;
		$void_event_id           = self::factory()->post->create(
			array(
				'post_type'   => defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$void_booking            = $bookings->create(
			array(
				'venue_term_id' => $this->venue_id,
				'artist_name'   => 'Settlement Void Artist',
				'intake'        => array(),
			)
		);
		$void_booking            = $bookings->claim_event( $void_booking['id'], $void_event_id, $void_booking['version'] );
		$this->assertIsArray( $void_booking, is_wp_error( $void_booking ) ? $void_booking->get_error_code() : '' );
		$void_source = ( new TicketReconciliationService() )->register_source(
			array(
				'booking_id' => $void_booking['id'],
				'provider'   => 'manual-certified',
				'source_key' => 'primary',
				'ticket_url' => 'https://tickets.example.test/mysql-void',
			),
			$this->actor_id
		);
		$this->assertIsArray( $void_source, is_wp_error( $void_source ) ? $void_source->get_error_code() : '' );
		$this->assertIsArray( $abilities['extrachill/record-booking-ticket-sales']->execute( $this->settlement_report_input( $void_booking['id'], 'mysql-settlement-void-report', $void_source['id'] ) ) );
		$void_preview = $abilities['extrachill/calculate-booking-settlement']->execute(
			array(
				'booking_id'   => $void_booking['id'],
				'basis'        => 'gross_ticket_sales',
				'basis_points' => 2000,
				'currency'     => 'USD',
			)
		);
		$this->assertIsArray( $void_preview, is_wp_error( $void_preview ) ? $void_preview->get_error_code() : '' );
		$void_settlement = $abilities['extrachill/finalize-booking-settlement']->execute(
			array(
				'booking_id'               => $void_booking['id'],
				'expected_booking_version' => $void_preview['booking_version'],
				'expected_report_ids'      => $void_preview['included_report_ids'],
				'expected_evidence_hash'   => $void_preview['evidence_hash'],
				'basis'                    => $void_preview['basis'],
				'basis_points'             => $void_preview['basis_points'],
				'currency'                 => $void_preview['currency'],
				'formula_version'          => $void_preview['formula_version'],
				'adjustment_minor'         => 0,
			)
		);
		$this->assertIsArray( $void_settlement, is_wp_error( $void_settlement ) ? $void_settlement->get_error_code() : '' );
		$voided = $abilities['extrachill/void-booking-settlement']->execute(
			array(
				'booking_id'               => $void_booking['id'],
				'expected_booking_version' => $void_booking['version'],
				'expected_version'         => 1,
				'reason'                   => 'MySQL ability void proof.',
			)
		);
		$this->assertIsArray( $voided, is_wp_error( $voided ) ? $voided->get_error_code() : '' );
		$this->assertSame( 'void', $voided['status'] );
	}

	/** Register settlement definitions into the real Core Abilities registry. */
	private function register_settlement_abilities( TicketSettlementService $service ): array {
		$names = array( 'extrachill/register-booking-ticket-source', 'extrachill/list-booking-ticket-sources', 'extrachill/record-booking-ticket-sales', 'extrachill/import-booking-ticket-sales-csv', 'extrachill/list-booking-ticket-sales', 'extrachill/diagnose-booking-ticket-sales', 'extrachill/resolve-booking-ticket-sales', 'extrachill/calculate-booking-settlement', 'extrachill/finalize-booking-settlement', 'extrachill/mark-booking-settlement-paid', 'extrachill/void-booking-settlement' );
		foreach ( $names as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		$registrar = new TicketSettlementAbilities( $service );
		$previous  = isset( $GLOBALS['wp_filter']['wp_abilities_api_init'] ) ? clone $GLOBALS['wp_filter']['wp_abilities_api_init'] : null;
		remove_all_actions( 'wp_abilities_api_init' );
		add_action( 'wp_abilities_api_init', array( $registrar, 'register' ) );
		do_action( 'wp_abilities_api_init' );
		remove_all_actions( 'wp_abilities_api_init' );
		if ( null !== $previous ) {
			$GLOBALS['wp_filter']['wp_abilities_api_init'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the real wp_abilities_api_init hook state this test intentionally cleared above to isolate its own ability registration.
		}
		$abilities = array();
		foreach ( $names as $name ) {
			$abilities[ $name ] = wp_get_ability( $name );
			$this->assertInstanceOf( WP_Ability::class, $abilities[ $name ], $name . ' was not registered in Core.' );
		}
		return $abilities;
	}

	/** Derive the exact production advisory-lock identity. */
	private function reference_lock_name( string $reference ): string {
		$scope = get_current_blog_id() . ':' . BookingSchema::attachments_table() . ':' . $reference;
		return 'ec_booking_file_' . substr( hash( 'sha256', $scope ), 0, 40 );
	}
}
