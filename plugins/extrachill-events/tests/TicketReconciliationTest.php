<?php
/**
 * Ticket attribution, import, and reconciliation tests.
 *
 * @package ExtraChillEvents\Tests
 */

// Test fixtures intentionally use concise comments and disposable local files.
// phpcs:disable Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.FunctionComment.Missing,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.unlink_unlink

use ExtraChillEvents\Abilities\TicketSettlementAbilities;
use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingAttachmentRepository;
use ExtraChillEvents\Core\BookingAttachmentService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\TicketReconciliationService;
use ExtraChillEvents\Core\TicketSettlementService;

require_once __DIR__ . '/Support/BookingTestHarness.php';

/** Verifies that unresolved or contradictory evidence never reaches settlement. */
final class TicketReconciliationTest extends BookingTestCase {
	/** @var BookingRepository */
	private $bookings;
	/** @var BookingTestAuthorization */
	private $authorization;
	/** @var BookingTestPrivateFileProvider */
	private $provider;
	/** @var BookingAttachmentService */
	private $attachment_service;
	/** @var TicketReconciliationService */
	private $reconciliation;
	/** @var TicketSettlementService */
	private $settlements;

	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'       => 7,
			'stack'         => array(),
			'uuid'          => 0,
			'options'       => array( BookingSchema::VERSION_OPTION => BookingSchema::SCHEMA_VERSION ),
			'dbdelta'       => array(),
			'abilities'     => array(),
			'actions'       => array(),
			'cache_deletes' => array(),
			'terms'         => array(
				7 => array(
					55 => (object) array(
						'term_id'  => 55,
						'taxonomy' => 'venue',
						'name'     => 'Reconciliation Room',
					),
					56 => (object) array(
						'term_id'  => 56,
						'taxonomy' => 'venue',
						'name'     => 'Other Room',
					),
				),
			),
			'meta'          => array(),
			'posts'         => array(
				7 => array(
					900 => (object) array(
						'ID'          => 900,
						'post_type'   => 'data_machine_events',
						'post_status' => 'publish',
					),
					901 => (object) array(
						'ID'          => 901,
						'post_type'   => 'data_machine_events',
						'post_status' => 'publish',
					),
				),
			),
			'post_meta'     => array(),
		);
		$GLOBALS['wpdb']           = new BookingWpdb();
		$this->bookings            = new BookingRepository();
		$this->authorization       = new BookingTestAuthorization();
		$this->provider            = new BookingTestPrivateFileProvider();
		$attachments               = new BookingAttachmentRepository();
		$activity                  = new BookingActivityRepository();
		$this->attachment_service  = new BookingAttachmentService( $attachments, $this->bookings, $activity, null, $this->provider, $this->authorization );
		$this->reconciliation      = new TicketReconciliationService( $this->bookings, $activity, $this->authorization, $attachments, $this->attachment_service );
		$this->settlements         = new TicketSettlementService( $this->bookings, $activity, $this->authorization, null, $this->reconciliation );
	}

	public function test_schema_upgrades_v12_without_replacing_settlement_tables(): void {
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ] = '12';
		$this->assertTrue( BookingSchema::maybe_install() );
		$this->assertSame( '16', get_option( BookingSchema::VERSION_OPTION ) );
		$this->assertTrue( BookingSchema::health() );
		$this->assertArrayHasKey( BookingSchema::ticket_sources_table(), $GLOBALS['wpdb']->schemas );
		$this->assertArrayHasKey( BookingSchema::sales_resolutions_table(), $GLOBALS['wpdb']->schemas );
		$this->assertArrayHasKey( BookingSchema::settlements_table(), $GLOBALS['wpdb']->schemas );
		$sales = $GLOBALS['wpdb']->schemas[ BookingSchema::sales_reports_table() ];
		$this->assertArrayHasKey( 'ticket_source_id', $sales['columns'] );
		$this->assertArrayHasKey( 'evidence_attachment_id', $sales['columns'] );
		$this->assertSame( array( 'booking_id', 'provider', 'external_report_id_hash' ), $sales['indexes']['provider_external_report']['columns'] );
	}

	public function test_sources_are_stable_idempotent_redacted_and_venue_scoped(): void {
		$booking = $this->booking();
		$input   = array(
			'booking_id' => $booking['id'],
			'provider'   => 'box-office',
			'source_key' => 'campaign-a',
			'ticket_url' => 'https://tickets.example.test/private-value/buy?token=private-value&utm_source=extrachill',
		);
		$first   = $this->reconciliation->register_source( $input, 12 );
		$retry   = $this->reconciliation->register_source( $input, 12 );
		$this->assertSame( $first, $retry );
		$this->assertSame( 'https://tickets.example.test', $first['display_url'] );
		$this->assertStringNotContainsString( 'private-value', wp_json_encode( $first ) );

		$changed               = $input;
		$changed['ticket_url'] = 'https://tickets.example.test/other';
		$this->assertSame( 'ticket_source_idempotency_conflict', $this->reconciliation->register_source( $changed, 12 )->get_error_code() );
		$this->assertSame( 'venue_action_forbidden', $this->reconciliation->register_source( $input, 99 )->get_error_code() );

		$other               = $this->booking( 56, 901 );
		$cross               = $input;
		$cross['booking_id'] = $other['id'];
		$cross['source_key'] = 'other';
		$this->assertSame( 'venue_action_forbidden', $this->reconciliation->register_source( $cross, 12 )->get_error_code() );
	}

	public function test_opaque_source_and_report_ids_are_lossless_and_byte_bounded(): void {
		$booking = $this->booking();
		$upper   = $this->reconciliation->register_source(
			array(
				'booking_id' => $booking['id'],
				'provider'   => 'box-office',
				'source_key' => 'Opaque ID/Case A',
				'ticket_url' => 'https://tickets.example.test/upper',
			),
			12
		);
		$lower   = $this->reconciliation->register_source(
			array(
				'booking_id' => $booking['id'],
				'provider'   => 'box-office',
				'source_key' => 'opaque id/case a',
				'ticket_url' => 'https://tickets.example.test/lower',
			),
			12
		);
		$this->assertSame( 'Opaque ID/Case A', $upper['source_key'] );
		$this->assertSame( 'opaque id/case a', $lower['source_key'] );
		$this->assertNotSame( $upper['id'], $lower['id'] );

		$first  = $this->settlements->record_sales( $this->report( $booking['id'], 'Report/Case A', $upper['id'], '2026-07-01 00:00:00', '2026-07-01 23:59:59' ), 12 );
		$second = $this->settlements->record_sales( $this->report( $booking['id'], 'report/case a', $upper['id'], '2026-07-02 00:00:00', '2026-07-02 23:59:59' ), 12 );
		$this->assertSame( 'Report/Case A', $first['external_report_id'] );
		$this->assertSame( 'report/case a', $second['external_report_id'] );
		$this->assertNotSame( $first['id'], $second['id'] );

		$invalid_source = array(
			'booking_id' => $booking['id'],
			'provider'   => 'box-office',
			'source_key' => "identity\ncollapse",
			'ticket_url' => 'https://tickets.example.test/invalid',
		);
		$this->assertSame( 'invalid_opaque_identifier', $this->reconciliation->register_source( $invalid_source, 12 )->get_error_code() );
		$invalid_report = $this->report( $booking['id'], "report\0id", $upper['id'], '2026-07-03 00:00:00', '2026-07-03 23:59:59' );
		$this->assertSame( 'invalid_opaque_identifier', $this->settlements->record_sales( $invalid_report, 12 )->get_error_code() );
	}

	public function test_unattributed_evidence_requires_immutable_resolution_before_settlement(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking['id'] );
		$input   = $this->report( $booking['id'], 'unattributed', null, '2026-07-01 00:00:00', '2026-07-01 23:59:59' );
		$report  = $this->settlements->record_sales( $input, 12 );
		$this->assertNull( $report['ticket_source_id'] );
		$diagnostics = $this->reconciliation->diagnostics( $booking['id'], 12 );
		$this->assertSame( array( 'unattributed' ), $diagnostics['reports'][0]['issues'] );
		$this->assertFalse( $diagnostics['ready_for_settlement'] );
		$this->assertSame( 'settlement_reconciliation_required', $this->preview( $booking['id'] )->get_error_code() );
		$wrong_source = $this->reconciliation->register_source(
			array(
				'booking_id' => $booking['id'],
				'provider'   => 'other-provider',
				'source_key' => 'other',
				'ticket_url' => 'https://other.example.test/buy',
			),
			12
		);
		$this->assertIsArray( $wrong_source );
		$wrong_attribution = $this->reconciliation->resolve(
			array(
				'booking_id'       => $booking['id'],
				'report_id'        => $report['id'],
				'expected_version' => 0,
				'decision'         => 'admit',
				'ticket_source_id' => $wrong_source['id'],
				'reason'           => 'Incorrect provider.',
			),
			12
		);
		$this->assertSame( 'ticket_source_not_found', $wrong_attribution->get_error_code() );

		$resolution_input = array(
			'booking_id'       => $booking['id'],
			'report_id'        => $report['id'],
			'expected_version' => 0,
			'decision'         => 'admit',
			'ticket_source_id' => $source['id'],
			'reason'           => 'Matched against the signed venue export.',
		);
		$resolution       = $this->reconciliation->resolve(
			$resolution_input,
			12
		);
		$this->assertSame( 1, $resolution['version'] );
		$this->assertSame( $source['id'], $resolution['ticket_source_id'] );
		$this->assertSame( $resolution, $this->reconciliation->resolve( $resolution_input, 12 ) );
		$this->assertTrue( $this->reconciliation->diagnostics( $booking['id'], 12 )['ready_for_settlement'] );
		$this->assertSame( array( $report['id'] ), $this->preview( $booking['id'] )['included_report_ids'] );

		$stale = $this->reconciliation->resolve(
			array(
				'booking_id'       => $booking['id'],
				'report_id'        => $report['id'],
				'expected_version' => 0,
				'decision'         => 'exclude',
				'reason'           => 'Stale overwrite.',
			),
			12
		);
		$this->assertSame( 'sales_resolution_version_conflict', $stale->get_error_code() );
		$second = $this->reconciliation->resolve(
			array(
				'booking_id'       => $booking['id'],
				'report_id'        => $report['id'],
				'expected_version' => 1,
				'decision'         => 'exclude',
				'reason'           => 'Superseded by certified evidence.',
			),
			12
		);
		$this->assertSame( $resolution['id'], $second['supersedes_resolution_id'] );
		$this->assertCount( 2, $GLOBALS['wpdb']->rows[ BookingSchema::sales_resolutions_table() ] );
		$third   = $this->reconciliation->resolve(
			array(
				'booking_id'       => $booking['id'],
				'report_id'        => $report['id'],
				'expected_version' => 2,
				'decision'         => 'admit',
				'ticket_source_id' => $source['id'],
				'reason'           => 'Final finance review restored admission.',
			),
			12
		);
		$preview = $this->preview( $booking['id'] );
		$fourth  = $this->reconciliation->resolve(
			array(
				'booking_id'       => $booking['id'],
				'report_id'        => $report['id'],
				'expected_version' => $third['version'],
				'decision'         => 'admit',
				'ticket_source_id' => $source['id'],
				'reason'           => 'Second finance review confirmed admission.',
			),
			12
		);
		$this->assertSame( 'settlement_evidence_conflict', $this->settlements->finalize( $this->resolution_finalization( $preview ), 12 )->get_error_code() );
		$preview    = $this->preview( $booking['id'] );
		$settlement = $this->settlements->finalize( $this->resolution_finalization( $preview ), 12 );
		$this->assertIsArray( $settlement, is_wp_error( $settlement ) ? $settlement->get_error_code() : '' );
		$this->assertSame( 0, $GLOBALS['wpdb']->nested_transaction_starts, 'Settlement finalization must not release its locks through a nested attachment transaction.' );
		$this->assertSame( $report, $this->settlements->record_sales( $input, 12 ) );
		$after_settlement                       = $input;
		$after_settlement['external_report_id'] = 'after-settlement';
		$this->assertSame( 'sales_report_settlement_frozen', $this->settlements->record_sales( $after_settlement, 12 )->get_error_code() );
		$frozen = $this->reconciliation->resolve(
			array(
				'booking_id'       => $booking['id'],
				'report_id'        => $report['id'],
				'expected_version' => $fourth['version'],
				'decision'         => 'exclude',
				'reason'           => 'Must not alter frozen evidence.',
			),
			12
		);
		$this->assertSame( 'sales_resolution_settlement_frozen', $frozen->get_error_code() );
		$GLOBALS['wpdb']->rows[ BookingSchema::sales_resolutions_table() ][ $fourth['id'] ]['request_hash'] = str_repeat( '0', 64 );
		$this->assertSame( 'sales_resolution_integrity_failed', $this->settlements->finalize( $this->resolution_finalization( $preview ), 12 )->get_error_code() );
	}

	public function test_all_currencies_must_reconcile_before_one_currency_settles(): void {
		$booking               = $this->booking();
		$source                = $this->source( $booking['id'] );
		$usd                   = $this->settlements->record_sales( $this->report( $booking['id'], 'usd-clean', $source['id'], '2026-07-01 00:00:00', '2026-07-01 23:59:59' ), 12 );
		$eur_input             = $this->report( $booking['id'], 'eur-unattributed', null, '2026-07-02 00:00:00', '2026-07-02 23:59:59' );
		$eur_input['currency'] = 'EUR';
		$eur                   = $this->settlements->record_sales( $eur_input, 12 );
		$this->assertSame( 'settlement_reconciliation_required', $this->preview( $booking['id'] )->get_error_code() );
		$this->resolve_report( $booking['id'], $eur['id'], 'exclude' );
		$this->assertSame( array( $usd['id'] ), $this->preview( $booking['id'] )['included_report_ids'] );
	}

	public function test_report_identity_is_scoped_to_booking_and_source_authority(): void {
		$first        = $this->booking();
		$first_source = $this->source( $first['id'] );
		$first_report = $this->settlements->record_sales( $this->report( $first['id'], 'account-local-id', $first_source['id'], '2026-07-01 00:00:00', '2026-07-01 23:59:59' ), 12 );
		$this->assertIsArray( $first_report );

		$this->authorization->allowed['12:56'] = true;
		$second                                = $this->booking( 56, 901 );
		$second_source                         = $this->source( $second['id'] );
		$second_report                         = $this->settlements->record_sales( $this->report( $second['id'], 'account-local-id', $second_source['id'], '2026-07-01 00:00:00', '2026-07-01 23:59:59' ), 12 );
		$this->assertIsArray( $second_report, is_wp_error( $second_report ) ? $second_report->get_error_code() : '' );
		$this->assertNotSame( $first_report['id'], $second_report['id'] );

		$alternate_source                 = $this->reconciliation->register_source(
			array(
				'booking_id' => $first['id'],
				'provider'   => 'box-office',
				'source_key' => 'alternate',
				'ticket_url' => 'https://tickets.example.test/alternate',
			),
			12
		);
		$correction                       = $this->report( $first['id'], 'cross-source-correction', $alternate_source['id'], '2026-07-01 00:00:00', '2026-07-01 23:59:59' );
		$correction['corrects_report_id'] = $first_report['id'];
		$this->assertSame( 'invalid_sales_report_correction', $this->settlements->record_sales( $correction, 12 )->get_error_code() );
	}

	public function test_stored_evidence_is_rejected_after_booking_identity_changes(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking['id'] );
		$report  = $this->settlements->record_sales( $this->report( $booking['id'], 'identity-change', $source['id'], '2026-07-01 00:00:00', '2026-07-01 23:59:59' ), 12 );

		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['event_id'] = 901;
		$this->assertSame( 'ticket_source_booking_changed', $this->reconciliation->list_sources( $booking['id'], 12 )->get_error_code() );
		$this->assertSame( 'sales_report_booking_changed', $this->settlements->list_sales( $booking['id'], 12 )->get_error_code() );
		$this->assertSame( 'sales_report_booking_changed', $this->reconciliation->diagnostics( $booking['id'], 12 )->get_error_code() );
		$this->assertSame(
			'sales_report_booking_changed',
			$this->reconciliation->resolve(
				array(
					'booking_id'       => $booking['id'],
					'report_id'        => $report['id'],
					'expected_version' => 0,
					'decision'         => 'exclude',
					'reason'           => 'Must not reinterpret stale evidence.',
				),
				12
			)->get_error_code()
		);
	}

	public function test_duplicates_overlaps_contradictions_and_currency_mismatch_are_explicit(): void {
		$booking                = $this->booking();
		$source                 = $this->source( $booking['id'] );
		$first                  = $this->settlements->record_sales( $this->report( $booking['id'], 'first', $source['id'], '2026-07-01 00:00:00', '2026-07-10 23:59:59' ), 12 );
		$duplicate              = $this->report( $booking['id'], 'duplicate', $source['id'], '2026-07-01 00:00:00', '2026-07-10 23:59:59' );
		$second                 = $this->settlements->record_sales( $duplicate, 12 );
		$conflict               = $this->report( $booking['id'], 'conflict', $source['id'], '2026-07-01 00:00:00', '2026-07-10 23:59:59' );
		$conflict['fees_minor'] = 999;
		$third                  = $this->settlements->record_sales( $conflict, 12 );
		$eur                    = $this->report( $booking['id'], 'eur', $source['id'], '2026-07-20 00:00:00', '2026-07-20 23:59:59' );
		$eur['currency']        = 'EUR';
		$fourth                 = $this->settlements->record_sales( $eur, 12 );

		$diagnostics = $this->reconciliation->diagnostics( $booking['id'], 12 );
		$this->assertContains( 'duplicate', $diagnostics['reports'][0]['issues'] );
		$this->assertContains( 'contradictory', $diagnostics['reports'][0]['issues'] );
		$this->assertContains( 'currency_mismatch', $diagnostics['reports'][3]['issues'] );
		$this->assertSame( 4, $diagnostics['counts']['unresolved'] );

		$this->resolve_report( $booking['id'], $first['id'], 'admit' );
		$this->resolve_report( $booking['id'], $second['id'], 'exclude' );
		$this->resolve_report( $booking['id'], $third['id'], 'exclude' );
		$this->resolve_report( $booking['id'], $fourth['id'], 'exclude' );
		$ready = $this->reconciliation->diagnostics( $booking['id'], 12 );
		$this->assertTrue( $ready['ready_for_settlement'] );
		$this->assertSame( array( $first['id'] ), $this->preview( $booking['id'] )['included_report_ids'] );
	}

	public function test_csv_import_uses_private_attachment_and_replays_idempotently(): void {
		$booking    = $this->booking();
		$source     = $this->source( $booking['id'] );
		$csv        = implode( ',', array( 'external_report_id', 'period_start', 'period_end', 'tickets_sold', 'tickets_refunded', 'gross_minor', 'fees_minor', 'tax_minor', 'refunds_minor', 'net_minor', 'currency' ) ) . "\n"
			. 'csv-1,"2026-07-01 00:00:00","2026-07-01 23:59:59",10,1,10000,500,0,1000,8500,USD' . "\n"
			. 'csv-2,"2026-07-02 00:00:00","2026-07-02 23:59:59",5,0,5000,250,0,0,4750,USD' . "\n";
		$attachment = $this->csv_attachment( $booking['id'], $csv, 'canonical.csv' );
		$input      = array(
			'booking_id'       => $booking['id'],
			'attachment_id'    => $attachment['id'],
			'ticket_source_id' => $source['id'],
		);
		$rows       = $this->reconciliation->csv_report_inputs( $input, 12 );
		$this->assertCount( 2, $rows );
		$this->assertSame( $attachment['id'], $rows[0]['evidence_attachment_id'] );
		$this->assertSame( 'sales_csv_import_required', $this->settlements->record_sales( $rows[0], 12 )->get_error_code() );
		$first = $this->settlements->import_csv( $input, 12 );
		$retry = $this->settlements->import_csv( $input, 12 );
		$this->assertSame( array_column( $first, 'id' ), array_column( $retry, 'id' ) );
		$this->assertSame( array( 'csv_certified' ), array_values( array_unique( array_column( $first, 'source_type' ) ) ) );
		$preview = $this->preview( $booking['id'] );
		$this->assertSame( array_column( $first, 'id' ), $preview['included_report_ids'] );
		$this->provider->contents[ $attachment['storage_reference'] ] = substr( $csv, 0, -5 );
		$this->assertSame( 'settlement_csv_evidence_invalid', $this->preview( $booking['id'] )->get_error_code() );
		$this->assertSame( 'sales_csv_evidence_mismatch', $this->reconciliation->csv_report_inputs( $input, 12 )->get_error_code() );
		$this->provider->contents[ $attachment['storage_reference'] ] = $csv . 'extra';
		$this->assertSame( 'sales_csv_evidence_mismatch', $this->reconciliation->csv_report_inputs( $input, 12 )->get_error_code() );
		$this->provider->contents[ $attachment['storage_reference'] ] = $csv;
		$settlement = $this->settlements->finalize( $this->resolution_finalization( $preview ), 12 );
		$this->assertIsArray( $settlement, is_wp_error( $settlement ) ? $settlement->get_error_code() : '' );
		$GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ][ $attachment['id'] ]['state']     = 'purged';
		$GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ][ $attachment['id'] ]['purged_at'] = gmdate( 'Y-m-d H:i:s' );
		$this->assertSame( 'settlement_csv_evidence_invalid', $this->settlements->finalize( $this->resolution_finalization( $preview ), 12 )->get_error_code() );
		$this->assertSame( $settlement['id'], $this->settlements->get( $booking['id'], 12 )['id'], 'Immutable historical reads must not depend on retained private bytes.' );

		$bad_attachment = $this->csv_attachment( $booking['id'], "formula,total\n=HYPERLINK(\"https://evil.test\"),1\n", 'hostile.csv' );
		$bad            = $this->reconciliation->csv_report_inputs(
			array(
				'booking_id'       => $booking['id'],
				'attachment_id'    => $bad_attachment['id'],
				'ticket_source_id' => $source['id'],
			),
			12
		);
		$this->assertSame( 'sales_csv_header_invalid', $bad->get_error_code() );
		$long_csv        = implode( ',', array( 'external_report_id', 'period_start', 'period_end', 'tickets_sold', 'tickets_refunded', 'gross_minor', 'fees_minor', 'tax_minor', 'refunds_minor', 'net_minor', 'currency' ) ) . "\n\"" . str_repeat( 'x', 17000 );
		$long_attachment = $this->csv_attachment( $booking['id'], $long_csv, 'overlong.csv' );
		$long            = $this->reconciliation->csv_report_inputs(
			array(
				'booking_id'       => $booking['id'],
				'attachment_id'    => $long_attachment['id'],
				'ticket_source_id' => $source['id'],
			),
			12
		);
		$this->assertSame( 'sales_csv_line_too_long', $long->get_error_code() );
	}

	public function test_abilities_expose_bounded_contracts_and_redact_provenance(): void {
		$abilities = new TicketSettlementAbilities( $this->settlements, $this->bookings, $this->authorization, $this->reconciliation );
		$abilities->register();
		foreach ( array( 'extrachill/register-booking-ticket-source', 'extrachill/list-booking-ticket-sources', 'extrachill/import-booking-ticket-sales-csv', 'extrachill/diagnose-booking-ticket-sales', 'extrachill/resolve-booking-ticket-sales' ) as $name ) {
			$this->assertArrayHasKey( $name, $GLOBALS['ec_artist_test']['abilities'] );
			$this->assertTrue( $GLOBALS['ec_artist_test']['abilities'][ $name ]['meta']['show_in_rest'] );
		}
		$this->assertSame( 1000, $GLOBALS['ec_artist_test']['abilities']['extrachill/import-booking-ticket-sales-csv']['output_schema']['maxItems'] );
		$this->assertSame( array( 'manual' ), $GLOBALS['ec_artist_test']['abilities']['extrachill/record-booking-ticket-sales']['input_schema']['properties']['source_type']['enum'] );
		$this->assertFalse( $GLOBALS['ec_artist_test']['abilities']['extrachill/resolve-booking-ticket-sales']['meta']['annotations']['readonly'] );

		$booking                = $this->booking();
		$source                 = $this->source( $booking['id'] );
		$report_input           = $this->report( $booking['id'], 'redacted', $source['id'], '2026-07-01 00:00:00', '2026-07-01 23:59:59' );
		$report_input['source'] = array(
			'certificate' => 'signed',
			'api_key'     => 'must-not-leak',
			'url'         => 'https://private.test/?token=secret',
			'headers'     => array( 'X-Session' => 'private-session' ),
		);
		$report                 = $this->settlements->record_sales( $report_input, 12 );
		$this->assertSame( array( 'redacted' => true ), $report['source'] );
		$this->assertStringNotContainsString( 'must-not-leak', wp_json_encode( $this->settlements->list_sales( $booking['id'], 12 ) ) );
		$this->assertStringNotContainsString( 'private-session', wp_json_encode( $this->settlements->list_sales( $booking['id'], 12 ) ) );
		$this->assertStringNotContainsString( 'private.test', wp_json_encode( $this->settlements->list_sales( $booking['id'], 12 ) ) );
		$oversized                       = $report_input;
		$oversized['external_report_id'] = 'oversized';
		$oversized['source']             = array( 'certificate' => str_repeat( 'x', 9000 ) );
		$this->assertSame( 'sales_report_source_too_large', $this->settlements->record_sales( $oversized, 12 )->get_error_code() );
		$GLOBALS['wpdb']->rows[ BookingSchema::sales_reports_table() ][ $report['id'] ]['gross_minor'] = 1;
		$this->assertSame( 'sales_report_integrity_failed', $this->reconciliation->diagnostics( $booking['id'], 12 )->get_error_code() );
	}

	private function booking( int $venue_id = 55, int $event_id = 900 ): array {
		$booking = $this->bookings->create(
			array(
				'venue_term_id' => $venue_id,
				'artist_name'   => 'Evidence Artist',
				'intake'        => array(),
			)
		);
		$booking = $this->bookings->claim_event( $booking['id'], $event_id, $booking['version'] );
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['confirmed_deal_payload'] = wp_json_encode(
			array(
				'version' => 1,
				'data'    => array( 'currency' => 'USD' ),
			)
		);
		return $this->bookings->get( $booking['id'] );
	}

	private function source( int $booking_id ): array {
		$source = $this->reconciliation->register_source(
			array(
				'booking_id' => $booking_id,
				'provider'   => 'box-office',
				'source_key' => 'primary',
				'ticket_url' => 'https://tickets.example.test/buy?campaign=private',
			),
			12
		);
		$this->assertIsArray( $source, is_wp_error( $source ) ? $source->get_error_code() : '' );
		return $source;
	}

	private function report( int $booking_id, string $external_id, ?int $source_id, string $start, string $end ): array {
		return array(
			'booking_id'         => $booking_id,
			'ticket_source_id'   => $source_id,
			'provider'           => 'box-office',
			'external_report_id' => $external_id,
			'source_type'        => 'manual',
			'period_start'       => $start,
			'period_end'         => $end,
			'tickets_sold'       => 10,
			'tickets_refunded'   => 1,
			'gross_minor'        => 10000,
			'fees_minor'         => 500,
			'tax_minor'          => 0,
			'refunds_minor'      => 1000,
			'net_minor'          => 8500,
			'currency'           => 'USD',
			'source'             => array( 'certificate' => $external_id ),
		);
	}

	private function preview( int $booking_id ) {
		return $this->settlements->calculate(
			array(
				'booking_id'   => $booking_id,
				'basis'        => 'gross_ticket_sales',
				'basis_points' => 2000,
				'currency'     => 'USD',
			),
			12
		);
	}

	private function resolution_finalization( array $preview ): array {
		return array(
			'booking_id'               => $preview['booking_id'],
			'expected_booking_version' => $preview['booking_version'],
			'expected_report_ids'      => $preview['included_report_ids'],
			'expected_evidence_hash'   => $preview['evidence_hash'],
			'basis'                    => $preview['basis'],
			'basis_points'             => $preview['basis_points'],
			'currency'                 => $preview['currency'],
			'formula_version'          => $preview['formula_version'],
			'adjustment_minor'         => $preview['adjustment_minor'],
		);
	}

	private function resolve_report( int $booking_id, int $report_id, string $decision ): void {
		$result = $this->reconciliation->resolve(
			array(
				'booking_id'       => $booking_id,
				'report_id'        => $report_id,
				'expected_version' => 0,
				'decision'         => $decision,
				'reason'           => 'Operator reviewed conflicting evidence.',
			),
			12
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
	}

	private function csv_attachment( int $booking_id, string $bytes, string $filename ): array {
		$path = tempnam( sys_get_temp_dir(), 'ticket-csv-' );
		file_put_contents( $path, $bytes );
		$reference = $this->provider->stage( $path, $filename, 'other_private_evidence' );
		unlink( $path );
		$attachment = $this->attachment_service->attach(
			array(
				'booking_id'         => $booking_id,
				'storage_reference'  => $reference,
				'idempotency_key'    => 'csv-' . hash( 'sha256', $bytes ),
				'purpose'            => 'other_private_evidence',
				'uploader_type'      => 'user',
				'uploader_user_id'   => 12,
				'uploader_reference' => null,
				'artist_term_id'     => null,
				'artist_profile_id'  => null,
			)
		);
		$this->assertIsArray( $attachment, is_wp_error( $attachment ) ? $attachment->get_error_code() : '' );
		return $attachment;
	}
}
